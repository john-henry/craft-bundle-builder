<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\adjusters;

use Craft;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\TaxAddressZone;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\elements\Address;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use Money\Currencies\ISOCurrencies;
use Money\Currency as MoneyCurrency;
use Money\Teller;
use Throwable;

/**
 * Bundle tax adjuster.
 *
 * Applies tax to "multiple supply" bundle line items by apportioning the line's
 * price across its components (by selling-price share) and taxing each share at
 * its own component's tax category rate(s), following the composite-vs-multiple
 * supply distinction common to VAT, GST and sales-tax regimes alike. Composite
 * supply bundles are left to Commerce's core tax adjuster.
 *
 * Built on Commerce's own tax rates, zones, tax categories and tax-ID
 * validators, all merchant-configured per store, so it works with whatever
 * jurisdiction's tax scheme Commerce supports. Money math goes through
 * Commerce's {@see Teller} at the order's own currency precision.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTaxAdjuster implements AdjusterInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param Order $order The order to adjust.
     * @return OrderAdjustment[] The tax adjustments to add.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function adjust(Order $order): array
    {
        $purchasableRates = Commerce::getInstance()->getTaxRates()
            ->getAllEnabledTaxRates($order->storeId)
            ->filter(static fn(TaxRate $rate): bool => $rate->taxable === TaxRateRecord::TAXABLE_PURCHASABLE);

        if ($purchasableRates->isEmpty()) {
            return [];
        }

        $address = $this->_taxAddress($order);
        $teller = Commerce::getInstance()->getCurrencies()->getTeller($order->currency);
        $lines = $this->_apportionedLines($order, $teller);

        if (empty($lines)) {
            return [];
        }

        $adjustments = [];

        // Iterate rates first, lines second: a tax category can have more
        // than one matching rate (e.g. a federal rate plus a state rate, or
        // separate zones for "this country" and "elsewhere"), and every
        // matching rate applies independently, exactly as Commerce's own
        // core adjuster does.
        foreach ($purchasableRates as $rate) {
            foreach ($lines as $line) {
                $rows = array_values(array_filter(
                    $line['rows'],
                    static fn(array $row): bool => $row['taxCategoryId'] === $rate->taxCategoryId,
                ));

                if (empty($rows)) {
                    continue;
                }

                $adjustments = array_merge(
                    $adjustments,
                    $this->_rateAdjustments($order, $line['lineItem'], $rate, $rows, $address, $teller),
                );
            }
        }

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the apportioned component rows for every taxable, multiple-supply
     * bundle line item on the order. Apportionment happens once per line item
     * (it doesn't depend on the tax rate being applied).
     *
     * @param Order $order The order.
     * @param Teller $teller The order currency's money teller.
     * @return array<int, array{lineItem: LineItem, rows: array}> The apportioned lines.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _apportionedLines(Order $order, Teller $teller): array
    {
        $cartService = BundleBuilder::getInstance()->getBundleCart();
        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            $bundle = $lineItem->getPurchasable();

            if (!$bundle instanceof Bundle || $bundle->getType()->taxTreatment !== TaxTreatment::Multiple->value) {
                continue;
            }

            if (!$lineItem->getIsTaxable()) {
                continue;
            }

            $selections = $cartService->getSelections($lineItem);

            if (empty($selections)) {
                continue;
            }

            $components = $this->_weightComponents($selections);
            $totalWeight = array_sum(array_column($components, 'weight'));

            if ($totalWeight <= 0) {
                continue;
            }

            // Apportion the taxable subtotal (the line subtotal net of any
            // line-item discount), so a discounted bundle line is taxed on
            // what the customer actually pays, the same base Commerce's own
            // tax adjuster uses for a purchasable line.
            $taxableSubtotal = $lineItem->getTaxableSubtotal(TaxRateRecord::TAXABLE_PRICE);
            $amounts = $this->_apportion($taxableSubtotal, $components, $totalWeight, $teller);

            $rows = [];
            foreach ($components as $i => $component) {
                $rows[] = [
                    'variant' => $component['variant'],
                    'taxCategoryId' => $component['variant']->getTaxCategoryId(),
                    'amount' => $amounts[$i],
                ];
            }

            $lines[] = ['lineItem' => $lineItem, 'rows' => $rows];
        }

        return $lines;
    }

    /**
     * Builds a weighted list of components for apportionment, keyed by their
     * resolved variant and selling-price weight.
     *
     * @param array $selections The bundle's snapshot selections.
     * @return array The weighted components.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _weightComponents(array $selections): array
    {
        $components = [];

        foreach ($selections as $selection) {
            if (empty($selection['variantId'])) {
                continue;
            }

            /** @var Variant|null $variant */
            $variant = Variant::find()->id((int)$selection['variantId'])->status(null)->one();

            if (!$variant) {
                continue;
            }

            $qty = (int)($selection['qty'] ?? 1);

            // Weight by sale price, so a component that's on sale takes the
            // share of the line it actually contributes. This keeps the tax
            // split in line with how automatic bundle pricing builds the price
            // from component sale prices in the first place.
            $components[] = [
                'variant' => $variant,
                'weight' => (float)($variant->getSalePrice() ?? 0) * $qty,
            ];
        }

        return $components;
    }

    /**
     * Apportions a line total across its weighted components, reconciling
     * rounding so the shares always sum exactly to the line total: the last
     * component absorbs whatever the prior, rounded shares leave over.
     *
     * @param float $lineTotal The line item's taxable subtotal (net of any line discount).
     * @param array $components The weighted components, as built by {@see _weightComponents()}.
     * @param float $totalWeight The sum of every component's weight.
     * @param Teller $teller The order currency's money teller.
     * @return float[] Each component's apportioned amount, in the same order as `$components`.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _apportion(float $lineTotal, array $components, float $totalWeight, Teller $teller): array
    {
        $amounts = [];
        $running = 0.0;
        $last = array_key_last($components);

        foreach ($components as $i => $component) {
            if ($i === $last) {
                $amounts[$i] = (float)$teller->subtract($lineTotal, $running);
                continue;
            }

            $share = $component['weight'] / $totalWeight;
            $amount = (float)$teller->multiply($lineTotal, $share);
            $amounts[$i] = $amount;
            $running = (float)$teller->add($running, $amount);
        }

        return $amounts;
    }

    /**
     * Resolves the tax adjustments (or included-tax removals) for one rate
     * against one line item's apportioned component rows, mirroring the
     * zone-match / tax-ID-exemption decision tree Commerce's own core tax
     * adjuster uses for a whole line item, scoped down to the rows that share
     * this rate's tax category.
     *
     * @param Order $order The order.
     * @param LineItem $lineItem The bundle line item.
     * @param TaxRate $rate The tax rate being evaluated.
     * @param array $rows The line item's component rows matching this rate's tax category.
     * @param Address|null $address The order's tax address.
     * @param Teller $teller The order currency's money teller.
     * @return OrderAdjustment[] The resulting adjustments.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _rateAdjustments(Order $order, LineItem $lineItem, TaxRate $rate, array $rows, ?Address $address, Teller $teller): array
    {
        $zoneMatches = $this->_zoneMatches($rate, $address);
        $hasValidTaxId = $zoneMatches && $rate->hasTaxIdValidators()
            && $this->_hasValidTaxId($address, $rate->getSelectedEnabledTaxIdValidators());

        $removeIncluded = !$zoneMatches && $rate->removeIncluded;
        $removeDueToTaxId = $zoneMatches && $hasValidTaxId && $rate->removeVatIncluded;

        if ($removeIncluded || $removeDueToTaxId) {
            return $this->_distribute($order, $lineItem, $rate, $rows, $teller, true);
        }

        // The rate's zone doesn't cover this address, or the order has a
        // validated tax ID that exempts it entirely (e.g. a reverse-charge
        // scenario for a verified cross-border business); no tax to add.
        if (!$zoneMatches || ($rate->hasTaxIdValidators() && $hasValidTaxId)) {
            return [];
        }

        return $this->_distribute($order, $lineItem, $rate, $rows, $teller, false);
    }

    /**
     * Taxes (or removes included tax from) a group of component rows as a
     * single amount, matching what Commerce's core adjuster would compute for
     * an un-apportioned line at this rate, then splits that total back across
     * the rows by largest remainder so the adjustments sum exactly.
     *
     * @param Order $order The order.
     * @param LineItem $lineItem The bundle line item.
     * @param TaxRate $rate The tax rate being applied.
     * @param array $rows The component rows in this group.
     * @param Teller $teller The order currency's money teller.
     * @param bool $removeIncluded Whether this removes already-included tax (a discount) rather than adding tax.
     * @return OrderAdjustment[] One adjustment per non-zero row.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _distribute(Order $order, LineItem $lineItem, TaxRate $rate, array $rows, Teller $teller, bool $removeIncluded): array
    {
        $groupAmount = 0.0;
        foreach ($rows as $row) {
            $groupAmount = (float)$teller->add($groupAmount, $row['amount']);
        }

        $groupTax = $this->_taxAmount($groupAmount, $rate->rate, $rate->include, $teller);

        if ($removeIncluded) {
            $groupTax = -$groupTax;
        }

        $shares = $this->_allocateProportionally(
            $groupTax,
            array_column($rows, 'amount'),
            $groupAmount,
            $order->currency,
        );

        $adjustments = [];

        foreach ($rows as $i => $row) {
            if ($shares[$i] === 0.0) {
                continue;
            }

            $adjustment = new OrderAdjustment();
            $adjustment->type = $removeIncluded ? 'discount' : 'tax';
            $adjustment->name = $removeIncluded
                ? (string)$rate->name . ' ' . Craft::t('commerce', 'Removed')
                : (string)$rate->name;
            $adjustment->description = $row['variant']->title;
            $adjustment->amount = $shares[$i];
            $adjustment->included = $removeIncluded ? false : $rate->include;
            $adjustment->sourceSnapshot = $rate->toArray();
            $adjustment->setOrder($order);
            $adjustment->setLineItem($lineItem);

            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }

    /**
     * Splits a total amount proportionally across a set of weights using the
     * largest-remainder method, in the currency's own minor-unit precision,
     * so the parts always sum exactly to the total regardless of how many
     * decimal places the currency uses.
     *
     * @param float $total The amount to split.
     * @param float[] $weights Each row's weight (its apportioned amount).
     * @param float $weightSum The sum of every weight.
     * @param string $currency The order's currency code.
     * @return float[] Each row's share, in the same order as `$weights`.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _allocateProportionally(float $total, array $weights, float $weightSum, string $currency): array
    {
        $count = count($weights);

        if ($weightSum <= 0 || $total === 0.0) {
            return array_fill(0, $count, 0.0);
        }

        $subunit = (new ISOCurrencies())->subunitFor(new MoneyCurrency($currency));
        $factor = 10 ** $subunit;

        $sign = $total < 0 ? -1 : 1;
        $totalMinor = (int)round(abs($total) * $factor);

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $i => $weight) {
            $exact = $totalMinor * ($weight / $weightSum);
            $floor = (int)floor($exact);
            $shares[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $allocated += $floor;
        }

        // Hand the leftover minor units to the rows with the largest
        // fractional remainder, one each, until the shares reconcile exactly.
        $remaining = $totalMinor - $allocated;
        arsort($remainders);

        foreach (array_keys($remainders) as $i) {
            if ($remaining <= 0) {
                break;
            }

            $shares[$i]++;
            $remaining--;
        }

        foreach ($shares as $i => $minor) {
            $shares[$i] = $sign * ((float)$minor / $factor);
        }

        return $shares;
    }

    /**
     * Returns whether a tax rate's zone applies to the given address.
     *
     * @param TaxRate $rate The tax rate.
     * @param Address|null $address The order's tax address.
     * @return bool Whether the rate applies.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _zoneMatches(TaxRate $rate, ?Address $address): bool
    {
        if ($rate->getIsEverywhere()) {
            return true;
        }

        $zone = $rate->getTaxZone();

        if (!$zone instanceof TaxAddressZone) {
            return false;
        }

        if (!$address) {
            return $zone->default;
        }

        return $zone->getCondition()->matchElement($address);
    }

    /**
     * Returns whether the order's tax address carries a tax ID (e.g. a VAT or
     * GST number) that validates against any of the given validators, reusing
     * Commerce's own validation cache so a tax ID validated by Commerce's core
     * adjuster doesn't trigger a second external lookup here.
     *
     * @param Address|null $address The order's tax address.
     * @param array $validators The rate's enabled tax-ID validators.
     * @return bool Whether the address has a valid tax ID.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _hasValidTaxId(?Address $address, array $validators): bool
    {
        if (!$address || !$address->organizationTaxId || !$address->getCountryCode()) {
            return false;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'commerce:validVatId:' . $address->organizationTaxId;

        if ($cache->exists($cacheKey)) {
            return true;
        }

        foreach ($validators as $validator) {
            try {
                if ($validator->validate($address->organizationTaxId)) {
                    $cache->set($cacheKey, '1');
                    return true;
                }
            } catch (Throwable $e) {
                Craft::error('Communication with tax ID validation API failed: ' . $e->getMessage(), __METHOD__);
                return false;
            }
        }

        return false;
    }

    /**
     * Calculates the tax on an amount, mirroring Commerce's inclusive/exclusive
     * handling and using its money teller throughout for precision.
     *
     * @param float $amount The taxable amount.
     * @param float $rate The tax rate (e.g. 0.23).
     * @param bool $included Whether tax is included in the amount.
     * @param Teller $teller The order currency's money teller.
     * @return float The tax amount.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _taxAmount(float $amount, float $rate, bool $included, Teller $teller): float
    {
        if ($included) {
            $exclusive = $teller->divide($amount, (1 + $rate));
            return (float)$teller->subtract($amount, $exclusive);
        }

        $inclusive = $teller->multiply($amount, (1 + $rate));
        return (float)$teller->subtract($inclusive, $amount);
    }

    /**
     * Resolves the address used to determine tax for the order.
     *
     * @param Order $order The order.
     * @return Address|null The tax address, or null.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _taxAddress(Order $order): ?Address
    {
        if ($order->getStore()->getUseBillingAddressForTax()) {
            return $order->getBillingAddress() ?? $order->getEstimatedBillingAddress();
        }

        return $order->getShippingAddress() ?? $order->getEstimatedShippingAddress();
    }
}

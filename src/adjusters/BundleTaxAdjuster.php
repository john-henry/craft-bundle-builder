<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\adjusters;

use Craft;
use craft\commerce\base\AdjusterInterface;
use craft\commerce\base\TaxIdValidatorInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\errors\StoreNotFoundException;
use craft\commerce\models\LineItem;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\TaxAddressZone;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\elements\Address;
use craft\errors\SiteNotFoundException;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\services\BundleCart;
use Money\Currencies\ISOCurrencies;
use Money\Currency as MoneyCurrency;
use Money\Teller;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Bundle tax adjuster.
 *
 * Taxes "multiple supply" bundle line items by apportioning the line's price
 * across its components by sale-price share, then taxing each share at its
 * component's tax category rates. Composite supply bundles are left to
 * Commerce's core tax adjuster.
 *
 * Uses Commerce's own tax rates, zones, categories and tax ID validators.
 * Money maths goes through {@see Teller} at the order currency's precision.
 *
 * @phpstan-import-type Selection from BundleCart
 * @phpstan-type WeightedComponent array{variant: Variant, qty: int, weight: float}
 * @phpstan-type ComponentRow array{variant: Variant, taxCategoryId: int, price: float, shipping: float}
 * @phpstan-type TaxableRow array{variant: Variant, amount: float}
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTaxAdjuster implements AdjusterInterface
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] The line-level taxable subjects this adjuster handles.
     * Order-level rates are applied to order totals by Commerce's core
     * adjuster, which already includes bundle lines.
     */
    private const LINE_TAXABLES = [
        TaxRateRecord::TAXABLE_PURCHASABLE,
        TaxRateRecord::TAXABLE_PRICE,
        TaxRateRecord::TAXABLE_SHIPPING,
        TaxRateRecord::TAXABLE_PRICE_SHIPPING,
    ];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param Order $order The order to adjust.
     * @return OrderAdjustment[] The tax adjustments to add.
     * @throws InvalidConfigException
     * @throws StoreNotFoundException
     * @throws SiteNotFoundException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function adjust(Order $order): array
    {
        $lineRates = Commerce::getInstance()->getTaxRates()
            ->getAllEnabledTaxRates($order->storeId)
            ->filter(static fn(TaxRate $rate): bool => in_array($rate->taxable, self::LINE_TAXABLES, true));

        if ($lineRates->isEmpty()) {
            return [];
        }

        $address = $this->_taxAddress($order);
        $teller = Commerce::getInstance()->getCurrencies()->getTeller($order->currency);
        $lines = $this->_apportionedLines($order, $teller);

        if (empty($lines)) {
            return [];
        }

        $adjustments = [];

        // A tax category can match several rates (e.g. federal plus state),
        // and each applies independently, as in Commerce's core adjuster.
        foreach ($lineRates as $rate) {
            foreach ($lines as $line) {
                $rows = [];

                foreach ($line['rows'] as $row) {
                    if ($row['taxCategoryId'] === $rate->taxCategoryId) {
                        $rows[] = [
                            'variant' => $row['variant'],
                            'amount' => $this->_taxableAmount($row, $rate->taxable, $teller),
                        ];
                    }
                }

                if (empty($rows)) {
                    continue;
                }

                $adjustments[] = $this->_rateAdjustments($order, $line['lineItem'], $rate, $rows, $address, $teller);
            }
        }

        return array_merge(...$adjustments);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the apportioned component rows for every taxable bundle line item
     * on the zero-rate apportioned tax category.
     *
     * @param Order $order The order.
     * @param Teller $teller The order currency's money teller.
     * @return list<array{lineItem: LineItem, rows: list<ComponentRow>}> The apportioned lines.
     * @throws InvalidConfigException|SiteNotFoundException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _apportionedLines(Order $order, Teller $teller): array
    {
        $cartService = BundleBuilder::getInstance()->getBundleCart();
        $apportionedId = Bundle::apportionedTaxCategoryId();
        $lines = [];

        if ($apportionedId === null) {
            return [];
        }

        foreach ($order->getLineItems() as $lineItem) {
            // Only bundle lines on the zero-rate category; any other bundle line
            // is taxed whole by Commerce's core adjuster, so taxing it here too
            // would charge it twice. The type check comes first: Commerce throws
            // when asked for a custom line item's purchasable.
            if (
                $lineItem->type !== LineItemType::Purchasable ||
                $lineItem->taxCategoryId !== $apportionedId ||
                !$lineItem->getPurchasable() instanceof Bundle
            ) {
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

            if (!$components) {
                continue;
            }

            $totalWeight = array_sum(array_column($components, 'weight'));

            // Components that all cost nothing still carry the line's price
            // (a fixed price bundle, say), so split it by quantity instead
            if ($totalWeight <= 0) {
                foreach ($components as $i => $component) {
                    $components[$i]['weight'] = (float)$component['qty'];
                }

                $totalWeight = array_sum(array_column($components, 'weight'));
            }

            // Net of line discounts, the same base Commerce's core adjuster uses.
            $taxableSubtotal = $lineItem->getTaxableSubtotal(TaxRateRecord::TAXABLE_PRICE);
            $prices = $this->_apportion($taxableSubtotal, $components, $totalWeight, $teller);
            $shipping = $this->_apportion($lineItem->getShippingCost(), $components, $totalWeight, $teller);

            $rows = [];
            foreach ($components as $i => $component) {
                $rows[] = [
                    'variant' => $component['variant'],
                    'taxCategoryId' => $component['variant']->getTaxCategoryId(),
                    'price' => $prices[$i],
                    'shipping' => $shipping[$i],
                ];
            }

            $lines[] = ['lineItem' => $lineItem, 'rows' => $rows];
        }

        return $lines;
    }

    /**
     * Resolves each selection's variant and weights it by sale price × qty.
     *
     * @param Selection[] $selections The bundle's snapshot selections.
     * @return list<WeightedComponent> The weighted components.
     * @author John Henry Donovan <info@johnhenry.ie>
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

            $components[] = [
                'variant' => $variant,
                'qty' => $qty,
                'weight' => (float)($variant->getSalePrice() ?? 0) * $qty,
            ];
        }

        return $components;
    }

    /**
     * Returns a component row's taxable amount for a rate's taxable subject.
     *
     * @param ComponentRow $row The component row.
     * @param string $taxable The rate's taxable subject.
     * @param Teller $teller The order currency's money teller.
     * @return float The taxable amount.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _taxableAmount(array $row, string $taxable, Teller $teller): float
    {
        return match ($taxable) {
            TaxRateRecord::TAXABLE_SHIPPING => $row['shipping'],
            TaxRateRecord::TAXABLE_PRICE_SHIPPING => (float)$teller->add($row['price'], $row['shipping']),
            default => $row['price'],
        };
    }

    /**
     * Apportions a line total across its weighted components, reconciling
     * rounding so the shares always sum exactly to the line total: the last
     * component absorbs whatever the prior, rounded shares leave over.
     *
     * @param float $lineTotal The line amount to apportion.
     * @param list<WeightedComponent> $components The weighted components, as built by {@see _weightComponents()}.
     * @param float $totalWeight The sum of every component's weight.
     * @param Teller $teller The order currency's money teller.
     * @return float[] Each component's apportioned amount, in the same order as `$components`.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * against the rows sharing its tax category, following the same zone and
     * tax ID exemption rules as Commerce's core adjuster.
     *
     * @param Order $order The order.
     * @param LineItem $lineItem The bundle line item.
     * @param TaxRate $rate The tax rate being evaluated.
     * @param list<TaxableRow> $rows The line item's component rows matching this rate's tax category.
     * @param Address|null $address The order's tax address.
     * @param Teller $teller The order currency's money teller.
     * @return OrderAdjustment[] The resulting adjustments.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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

        // Outside the rate's zone, or exempt via a validated tax ID (reverse charge).
        if (!$zoneMatches || ($rate->hasTaxIdValidators() && $hasValidTaxId)) {
            return [];
        }

        return $this->_distribute($order, $lineItem, $rate, $rows, $teller, false);
    }

    /**
     * Taxes a group of rows as one amount, so the total matches Commerce's core
     * adjuster for the whole line, then splits it across the rows by largest
     * remainder so the adjustments sum exactly.
     *
     * @param Order $order The order.
     * @param LineItem $lineItem The bundle line item.
     * @param TaxRate $rate The tax rate being applied.
     * @param list<TaxableRow> $rows The component rows in this group.
     * @param Teller $teller The order currency's money teller.
     * @param bool $removeIncluded Whether this removes already-included tax (a discount) rather than adding tax.
     * @return OrderAdjustment[] One adjustment per non-zero row.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Splits a total across weights by largest remainder, in the currency's
     * minor units, so the parts always sum exactly to the total.
     *
     * @param float $total The amount to split.
     * @param float[] $weights Each row's weight (its apportioned amount).
     * @param float $weightSum The sum of every weight.
     * @param string $currency The order's currency code.
     * @return float[] Each row's share, in the same order as `$weights`.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Returns whether the address has a tax ID (e.g. VAT or GST number) that
     * passes any of the given validators. Shares Commerce's validation cache,
     * so an ID already checked by the core adjuster isn't looked up again.
     *
     * @param Address|null $address The order's tax address.
     * @param TaxIdValidatorInterface[] $validators The rate's enabled tax-ID validators.
     * @return bool Whether the address has a valid tax ID.
     * @author John Henry Donovan <info@johnhenry.ie>
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
                Craft::error('Communication with tax ID validation API failed: ' . $e->getMessage(), 'bundle-builder');
                return false;
            }
        }

        return false;
    }

    /**
     * Calculates the tax on an amount, inclusive or exclusive.
     *
     * @param float $amount The taxable amount.
     * @param float $rate The tax rate (e.g. 0.23).
     * @param bool $included Whether tax is included in the amount.
     * @param Teller $teller The order currency's money teller.
     * @return float The tax amount.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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

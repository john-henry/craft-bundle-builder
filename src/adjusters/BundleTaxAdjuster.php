<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\adjusters;

use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\models\OrderAdjustment;
use craft\commerce\models\TaxAddressZone;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\elements\Address;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;

/**
 * Bundle tax adjuster.
 *
 * Applies VAT to "multiple supply" bundle line items by apportioning the line's
 * price across its components (by selling-price share) and taxing each share at
 * its own component's tax category rate, following Irish Revenue's mixed-supply
 * rules. Composite-supply bundles are left to Commerce's core tax adjuster.
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
        $cartService = BundleBuilder::getInstance()->getBundleCart();
        $adjustments = [];

        foreach ($order->getLineItems() as $lineItem) {
            $bundle = $lineItem->getPurchasable();

            if (!$bundle instanceof Bundle || $bundle->getType()->taxTreatment !== TaxTreatment::Multiple->value) {
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

            $lineTotal = (float)$lineItem->getSubtotal();

            foreach ($components as $component) {
                $variant = $component['variant'];
                $share = $component['weight'] / $totalWeight;
                $componentTotal = $lineTotal * $share;

                $rate = $this->_matchRate($purchasableRates, $variant->getTaxCategoryId(), $address);

                if (!$rate) {
                    continue;
                }

                $taxAmount = $this->_taxAmount($componentTotal, $rate->rate, $rate->include);

                $adjustment = new OrderAdjustment();
                $adjustment->type = 'tax';
                $adjustment->name = (string)$rate->name;
                $adjustment->description = $variant->title;
                $adjustment->amount = round($taxAmount, 2);
                $adjustment->included = $rate->include;
                $adjustment->sourceSnapshot = $rate->toArray();
                $adjustment->setOrder($order);
                $adjustment->setLineItem($lineItem);

                $adjustments[] = $adjustment;
            }
        }

        return $adjustments;
    }

    // Private Methods
    // =========================================================================

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

            // Weight by base price (not sale/catalog price) so apportionment
            // matches the proportions the merchant configured.
            $components[] = [
                'variant' => $variant,
                'weight' => (float)($variant->getBasePrice() ?? 0) * $qty,
            ];
        }

        return $components;
    }

    /**
     * Returns the first enabled purchasable tax rate matching the given tax
     * category and applicable to the order's tax address.
     *
     * @param iterable $rates The candidate tax rates.
     * @param int $taxCategoryId The component's tax category ID.
     * @param Address|null $address The order's tax address.
     * @return TaxRate|null The matching rate, or null.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _matchRate(iterable $rates, int $taxCategoryId, ?Address $address): ?TaxRate
    {
        foreach ($rates as $rate) {
            if ($rate->taxCategoryId !== $taxCategoryId) {
                continue;
            }

            if ($this->_rateApplies($rate, $address)) {
                return $rate;
            }
        }

        return null;
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
    private function _rateApplies(TaxRate $rate, ?Address $address): bool
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
     * Calculates the VAT on an amount, mirroring Commerce's inclusive/exclusive
     * handling.
     *
     * @param float $amount The taxable amount.
     * @param float $rate The tax rate (e.g. 0.23).
     * @param bool $included Whether tax is included in the amount.
     * @return float The tax amount.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _taxAmount(float $amount, float $rate, bool $included): float
    {
        if ($included) {
            return $amount - ($amount / (1 + $rate));
        }

        return $amount * $rate;
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

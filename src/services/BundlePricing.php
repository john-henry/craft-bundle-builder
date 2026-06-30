<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\records\BundleProductRecord;

/**
 * Bundle pricing service.
 *
 * Computes a bundle's price for the automatic pricing strategy — the summed base
 * price of its components, less a percentage or flat discount — and recalculates
 * automatically priced bundles when a component product's price changes.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundlePricing extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the summed base price of a bundle's components, each multiplied by
     * its quantity.
     *
     * @param Bundle $bundle The bundle to total.
     * @return float The components subtotal.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getComponentsSubtotal(Bundle $bundle): float
    {
        $subtotal = 0.0;

        foreach ($bundle->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            if (!$product) {
                continue;
            }

            $subtotal += $this->getComponentPrice($product) * $bundleProduct->qty;
        }

        return $subtotal;
    }

    /**
     * Returns the price used when totalling a component: its default variant's
     * base price.
     *
     * @param Product $product The component product.
     * @return float The component's unit price.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getComponentPrice(Product $product): float
    {
        $variant = $product->getDefaultVariant();

        return (float)($variant?->getBasePrice() ?? 0);
    }

    /**
     * Calculates a bundle's automatic price: the components subtotal less the
     * configured discount, floored at zero.
     *
     * @param Bundle $bundle The bundle to price.
     * @return float The calculated price.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function calculatePrice(Bundle $bundle): float
    {
        $subtotal = $this->getComponentsSubtotal($bundle);
        $discountAmount = (float)($bundle->discountAmount ?? 0);

        $price = match ($bundle->discountType) {
            DiscountType::Percentage->value => $subtotal * (1 - ($discountAmount / 100)),
            DiscountType::Flat->value => $subtotal - $discountAmount,
            default => $subtotal,
        };

        return max(0.0, round($price, 2));
    }

    /**
     * Recalculates the stored price of every automatically priced bundle that
     * contains the given product.
     *
     * @param int $productId The component product's ID.
     * @return void
     * @throws \Throwable if a bundle can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function recalculateBundlesForProduct(int $productId): void
    {
        $bundleIds = BundleProductRecord::find()
            ->select(['bundleId'])
            ->where(['productId' => $productId])
            ->column();

        if (empty($bundleIds)) {
            return;
        }

        $bundles = Bundle::find()
            ->id(array_values(array_unique($bundleIds)))
            ->status(null)
            ->all();

        $elementsService = Craft::$app->getElements();

        foreach ($bundles as $bundle) {
            if ($bundle->pricingStrategy !== PricingStrategy::Automatic->value) {
                continue;
            }

            // Re-saving recomputes the stored base price via the element's
            // beforeSave() hook.
            $elementsService->saveElement($bundle, false);
        }
    }
}

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
use johnhenry\bundlebuilder\models\BundleProduct;
use johnhenry\bundlebuilder\records\BundleProductRecord;

/**
 * Bundle pricing service.
 *
 * Computes a bundle's price for the automatic pricing strategy: the summed sale
 * price of its components, less a percentage or flat discount, and recalculates
 * automatically priced bundles when a component product is saved, deleted or
 * restored.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundlePricing extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the summed sale price of a bundle's components, each multiplied by
     * its quantity, so a component currently on sale or covered by a catalog
     * pricing rule is reflected in the bundle's automatic price. Batch-loads
     * every component product in one query rather than letting each
     * {@see BundleProduct::getProduct()} call hit the database individually.
     *
     * @param Bundle $bundle The bundle to total.
     * @return float The components subtotal.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getComponentsSubtotal(Bundle $bundle): float
    {
        $bundleProducts = $bundle->getProducts();

        if (empty($bundleProducts)) {
            return 0.0;
        }

        $productIds = array_values(array_unique(array_filter(array_map(
            static fn(BundleProduct $bundleProduct): ?int => $bundleProduct->productId,
            $bundleProducts,
        ))));

        $products = Product::find()->id($productIds)->indexBy('id')->all();

        $subtotal = 0.0;

        foreach ($bundleProducts as $bundleProduct) {
            $product = $products[$bundleProduct->productId] ?? null;

            if (!$product) {
                continue;
            }

            $subtotal += $this->getComponentPrice($product) * $bundleProduct->qty;
        }

        return $subtotal;
    }

    /**
     * Returns the price used when totalling a component: its default variant's
     * sale price (its promotional/catalog-pricing-rule price if one applies,
     * otherwise its regular price).
     *
     * @param Product $product The component product.
     * @return float The component's unit price.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getComponentPrice(Product $product): float
    {
        $variant = $product->getDefaultVariant();

        return (float)($variant?->getSalePrice() ?? 0);
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
     * Returns the IDs of every bundle that lists the given product as a
     * component.
     *
     * @param int $productId The component product's ID.
     * @return int[] The bundle IDs.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleIdsForProduct(int $productId): array
    {
        $bundleIds = BundleProductRecord::find()
            ->select(['bundleId'])
            ->where(['productId' => $productId])
            ->column();

        return array_values(array_unique(array_map('intval', $bundleIds)));
    }

    /**
     * Recalculates and stores an automatically priced bundle's price by
     * re-saving it. Fixed-price bundles are left alone.
     *
     * @param Bundle $bundle The bundle to recalculate.
     * @return void
     * @throws \Throwable if the bundle can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function recalculateBundle(Bundle $bundle): void
    {
        if ($bundle->pricingStrategy !== PricingStrategy::Automatic->value) {
            return;
        }

        // Re-saving recomputes the stored base price via the element's
        // beforeSave() hook.
        Craft::$app->getElements()->saveElement($bundle, false);
    }

    /**
     * Recalculates the stored price of every automatically priced bundle that
     * contains the given product. For a component used across a lot of bundles,
     * prefer queueing {@see RecalculateBundlePrices}, which does the same work
     * in batches.
     *
     * @param int $productId The component product's ID.
     * @return void
     * @throws \Throwable if a bundle can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function recalculateBundlesForProduct(int $productId): void
    {
        $bundleIds = $this->getBundleIdsForProduct($productId);

        if (empty($bundleIds)) {
            return;
        }

        $bundles = Bundle::find()
            ->id($bundleIds)
            ->status(null)
            ->all();

        foreach ($bundles as $bundle) {
            $this->recalculateBundle($bundle);
        }
    }
}

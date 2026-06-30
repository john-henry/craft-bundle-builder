<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\variables;

use Craft;
use craft\commerce\elements\Product;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\elements\db\BundleQuery;

/**
 * Bundle Builder Twig variable.
 *
 * Exposes bundles to templates via `craft.bundleBuilder`: a query builder and
 * a reverse lookup that finds the bundles a given product belongs to.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleBuilderVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns a bundle element query, optionally configured with criteria.
     *
     * @param array $criteria The query criteria.
     * @return BundleQuery The bundle query.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function bundles(array $criteria = []): BundleQuery
    {
        $query = Bundle::find();
        Craft::configure($query, $criteria);

        return $query;
    }

    /**
     * Returns the bundles that include the given product.
     *
     * @param Product|int $product The product, or its ID.
     * @param array $criteria Additional query criteria (e.g. status).
     * @return Bundle[] The bundles containing the product.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundlesForProduct(Product|int $product, array $criteria = []): array
    {
        $productId = $product instanceof Product ? $product->id : (int)$product;

        if (!$productId) {
            return [];
        }

        $bundleIds = (new \craft\db\Query())
            ->select(['bundleId'])
            ->from(['{{%bundlebuilder_products}}'])
            ->where(['productId' => $productId])
            ->column();

        if (empty($bundleIds)) {
            return [];
        }

        $query = Bundle::find()->id(array_values(array_unique(array_map('intval', $bundleIds))));
        Craft::configure($query, $criteria);

        return $query->all();
    }
}

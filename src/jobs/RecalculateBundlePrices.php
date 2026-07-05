<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;

/**
 * Recalculate bundle prices job.
 *
 * Re-saves every automatically priced bundle that lists a given component
 * product, so their stored price tracks the component's price. Batched, so a
 * component used across a lot of bundles is worked through in chunks (and can
 * pick up where it left off) rather than resaving every bundle in one go.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class RecalculateBundlePrices extends BaseBatchedJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int The component product's ID.
     */
    public int $productId;

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return Batchable The bundles that list the product as a component.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function loadData(): Batchable
    {
        $bundleIds = BundleBuilder::getInstance()->getBundlePricing()->getBundleIdsForProduct($this->productId);

        // Fall back to an ID that never matches, so an empty set yields an empty
        // batch rather than resaving every bundle.
        $query = Bundle::find()
            ->id($bundleIds ?: [0])
            ->status(null);

        return new QueryBatcher($query);
    }

    /**
     * @inheritdoc
     *
     * @param mixed $item The bundle to recalculate.
     * @return void
     * @throws \Throwable if the bundle can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function processItem(mixed $item): void
    {
        /** @var Bundle $item */
        BundleBuilder::getInstance()->getBundlePricing()->recalculateBundle($item);
    }

    /**
     * @inheritdoc
     *
     * @return string|null The job description.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('bundle-builder', 'Recalculating bundle prices');
    }
}

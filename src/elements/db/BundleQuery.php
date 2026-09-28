<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\elements\db;

use craft\commerce\elements\db\PurchasableQuery;
use craft\helpers\Db;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use yii\base\NotSupportedException;

/**
 * Bundle element query.
 *
 * Adds query params for filtering bundles by type, SKU, and availability dates,
 * and joins the bundle table so the bundle-specific columns are available on
 * every result.
 *
 * @extends PurchasableQuery<int, Bundle>
 * @method Bundle[] all($db = null)
 * @method Bundle|null one($db = null)
 * @method Bundle|null nth(int $n, $db = null)
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleQuery extends PurchasableQuery
{
    // Public Properties
    // =========================================================================

    /**
     * @var mixed The bundle type ID(s) to filter results by.
     */
    public mixed $typeId = null;

    /**
     * @var mixed The post date to filter results by.
     */
    public mixed $postDate = null;

    /**
     * @var mixed The expiry date to filter results by.
     */
    public mixed $expiryDate = null;

    // Public Methods
    // =========================================================================

    /**
     * Narrows the query results to bundles of the given type ID(s).
     *
     * @param mixed $value The bundle type ID(s).
     * @return static self reference.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function typeId(mixed $value): static
    {
        $this->typeId = $value;
        return $this;
    }

    /**
     * Narrows the query results to bundles of the given type handle(s). Null
     * clears the filter; handles that don't match a bundle type match nothing.
     *
     * @param string|string[]|null $value The bundle type handle(s).
     * @return static self reference.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function type(string|array|null $value): static
    {
        if ($value === null) {
            $this->typeId = null;
            return $this;
        }

        $bundleTypes = BundleBuilder::getInstance()->getBundleTypes();
        $typeIds = [];

        foreach ((array)$value as $handle) {
            $bundleType = $bundleTypes->getBundleTypeByHandle((string)$handle);

            if ($bundleType !== null) {
                $typeIds[] = $bundleType->id;
            }
        }

        $this->typeId = $typeIds ?: 0;
        return $this;
    }

    /**
     * Narrows the query results based on the bundles' post dates.
     *
     * @param mixed $value The post date value.
     * @return static self reference.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function postDate(mixed $value): static
    {
        $this->postDate = $value;
        return $this;
    }

    /**
     * Narrows the query results based on the bundles' expiry dates.
     *
     * @param mixed $value The expiry date value.
     * @return static self reference.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function expiryDate(mixed $value): static
    {
        $this->expiryDate = $value;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the query should be prepared and executed.
     * @throws NotSupportedException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function beforePrepare(): bool
    {
        $this->joinElementTable('{{%bundlebuilder_bundles}}');

        $this->query->addSelect([
            'bundlebuilder_bundles.typeId',
            'bundlebuilder_bundles.pricingStrategy',
            'bundlebuilder_bundles.discountType',
            'bundlebuilder_bundles.discountAmount',
            'bundlebuilder_bundles.postDate',
            'bundlebuilder_bundles.expiryDate',
        ]);

        if ($this->typeId !== null) {
            $this->subQuery->andWhere(Db::parseParam('[[bundlebuilder_bundles.typeId]]', $this->typeId));
        }

        if ($this->postDate !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('[[bundlebuilder_bundles.postDate]]', $this->postDate));
        }

        if ($this->expiryDate !== null) {
            $this->subQuery->andWhere(Db::parseDateParam('[[bundlebuilder_bundles.expiryDate]]', $this->expiryDate));
        }

        return parent::beforePrepare();
    }
}

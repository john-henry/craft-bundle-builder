<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\records;

use craft\db\ActiveRecord;

/**
 * Bundle product active record.
 *
 * Persists a single product included in a bundle, together with the quantity
 * required and the order it appears in. The customer chooses which variant of
 * the product to buy at add-to-cart time.
 *
 * @property int $id The record's ID.
 * @property int $bundleId The bundle's ID.
 * @property int $productId The included product's ID.
 * @property int $qty The quantity of the product in the bundle.
 * @property int|null $sortOrder The product's sort order within the bundle.
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleProductRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the name of the database table this record uses.
     *
     * @return string The table name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function tableName(): string
    {
        return '{{%bundlebuilder_products}}';
    }
}

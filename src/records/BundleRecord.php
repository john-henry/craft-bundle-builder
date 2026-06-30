<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\records;

use craft\db\ActiveRecord;

/**
 * Bundle active record.
 *
 * Persists the bundle-specific columns of a bundle element: its type, pricing
 * strategy and discount inputs, and availability dates. SKU, base price,
 * tax/shipping category, free-shipping and inventory tracking are persisted
 * natively by Commerce's Purchasable base class.
 *
 * @property int $id The bundle element's ID.
 * @property int $typeId The bundle type's ID.
 * @property string $pricingStrategy The pricing strategy ("fixed" or "automatic").
 * @property string|null $discountType The automatic discount type ("percentage" or "flat").
 * @property float|null $discountAmount The automatic discount amount.
 * @property string|null $postDate The date the bundle becomes available.
 * @property string|null $expiryDate The date the bundle stops being available.
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleRecord extends ActiveRecord
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
        return '{{%bundlebuilder_bundles}}';
    }
}

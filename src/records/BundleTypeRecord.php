<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\records;

use craft\db\ActiveRecord;

/**
 * Bundle type active record.
 *
 * Persists a single bundle type: its name, handle, field layout, and the
 * formats used to generate each bundle's SKU and description.
 *
 * @property int $id The bundle type's ID.
 * @property int|null $fieldLayoutId The bundle type's field layout ID.
 * @property string $name The bundle type's name.
 * @property string $handle The bundle type's handle.
 * @property string|null $skuFormat The bundle type's SKU format.
 * @property string|null $descriptionFormat The bundle type's description format.
 * @property bool $showSlugField Whether the slug field is shown on bundles of this type.
 * @property string $taxTreatment How bundles of this type are taxed ("composite" or "multiple").
 * @property string|null $previewTargets The bundle type's preview targets, as JSON.
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTypeRecord extends ActiveRecord
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
        return '{{%bundlebuilder_bundletypes}}';
    }
}

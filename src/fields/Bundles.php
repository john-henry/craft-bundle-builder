<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\fields;

use Craft;
use craft\fields\BaseRelationField;
use johnhenry\bundlebuilder\elements\Bundle;

/**
 * Bundles relation field.
 *
 * A relation field for selecting bundle elements, so editors can relate bundles
 * from other elements' field layouts (e.g. a "Bundles" field on a product or
 * entry).
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class Bundles extends BaseRelationField
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The field type's display name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('bundle-builder', 'Bundles');
    }

    /**
     * @inheritdoc
     *
     * @return string The field type's icon.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function icon(): string
    {
        return 'box';
    }

    /**
     * @inheritdoc
     *
     * @return string The related element type.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function elementType(): string
    {
        return Bundle::class;
    }

    /**
     * @inheritdoc
     *
     * @return string The default selection label.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function defaultSelectionLabel(): string
    {
        return Craft::t('bundle-builder', 'Add a bundle');
    }
}

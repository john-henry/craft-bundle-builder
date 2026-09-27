<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\nodetypes;

use Craft;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;
use verbb\navigation\base\ElementNodeType;

/**
 * Navigation 4 node type for linking a menu item to a {@see BundleElement}.
 *
 * Only loaded when Navigation 4 is installed, as it extends a class that
 * Navigation 3 doesn't have. Navigation 3 finds bundles by itself.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Bundle extends ElementNodeType
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The node type's display name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function displayName(): string
    {
        return BundleElement::pluralDisplayName();
    }

    /**
     * Returns the element type this node type links to.
     *
     * @return string The bundle element class.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getElementType(): string
    {
        return BundleElement::class;
    }

    /**
     * Returns the colour of the node type's badge in the menu builder.
     *
     * @return string The badge colour.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getColor(): string
    {
        return '#0d9488';
    }

    /**
     * Returns the menu builder's settings for the node type, with a
     * bundle-specific "add" button label.
     *
     * @return array<string, mixed> The builder settings.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getBuilderConfig(): array
    {
        return array_merge(parent::getBuilderConfig(), [
            'button' => Craft::t('bundle-builder', 'Add a bundle'),
        ]);
    }
}

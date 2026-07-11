<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\links;

use Craft;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fields\HyperField;

/**
 * Bundle link type.
 *
 * Registers the {@see BundleElement} as a selectable target in Verbb's Hyper
 * link field, so editors can point a Hyper link at a bundle the same way they
 * would an entry or product. All of the selection UI, element resolution, and
 * URL/text rendering is inherited from {@see ElementLink}; this class only
 * declares which element type it targets.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.1.0
 */
class Bundle extends ElementLink
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function displayName(): string
    {
        return Craft::t('bundle-builder', 'Bundle');
    }

    /**
     * @inheritdoc
     *
     * @return string
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function elementType(): string
    {
        return BundleElement::class;
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Rendered directly from Hyper's shared element-link templates rather than a
     * per-type folder: the base {@see ElementLink::getSettingsHtml()} derives the
     * template path from the class name (`hyper/links/bundle/settings`), which
     * only exists for Hyper's own built-in types. This points at the same shared
     * template its built-ins ultimately include.
     *
     * @return string|null
     * @throws \yii\base\Exception If the shared element template can't be loaded.
     * @throws \Twig\Error\LoaderError If the shared element template can't be found.
     * @throws \Twig\Error\SyntaxError If the shared element template has a syntax error.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'hyper/links/_element/settings',
            $this->getSettingsHtmlVariables(),
        );
    }

    /**
     * @inheritdoc
     *
     * See {@see self::getSettingsHtml()} for why the shared element template is
     * rendered directly instead of a per-type folder.
     *
     * @param LinkField $layoutField The Hyper link field-layout element.
     * @param HyperField $field The Hyper field.
     * @return string|null
     * @throws \yii\base\Exception If the shared element template can't be loaded.
     * @throws \Twig\Error\LoaderError If the shared element template can't be found.
     * @throws \Twig\Error\SyntaxError If the shared element template has a syntax error.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function getInputHtml(LinkField $layoutField, HyperField $field): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'hyper/links/_element/input',
            $this->getInputHtmlVariables($layoutField, $field),
        );
    }
}

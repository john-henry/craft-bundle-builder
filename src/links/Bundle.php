<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\links;

use Craft;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use verbb\hyper\base\ElementLink;
use verbb\hyper\fieldlayoutelements\LinkField;
use verbb\hyper\fields\HyperField;
use yii\base\Exception;

/**
 * Bundle link type.
 *
 * Hyper link type for linking to a {@see BundleElement}.
 *
 * @property-read null|string $settingsHtml
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.1.0
 */
class Bundle extends ElementLink
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The link type's display name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function displayName(): string
    {
        return Craft::t('bundle-builder', 'Bundle');
    }

    /**
     * Returns the element type this link type targets.
     *
     * @return string The bundle element class.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public static function elementType(): string
    {
        return BundleElement::class;
    }

    // Public Methods
    // =========================================================================

    /**
     * Returns the link type's settings HTML.
     *
     * Renders Hyper's shared element template, as the base class looks for
     * `hyper/links/bundle/settings`, which only exists for Hyper's own types.
     *
     * @return string|null The settings HTML.
     * @throws Exception If the shared element template can't be loaded.
     * @throws LoaderError If the shared element template can't be found.
     * @throws SyntaxError|RuntimeError If the shared element template has a syntax error.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * Returns the link type's input HTML, from Hyper's shared element template
     * (see {@see self::getSettingsHtml()}).
     *
     * @param LinkField $layoutField The Hyper link field layout element.
     * @param HyperField $field The Hyper field.
     * @return string|null The input HTML.
     * @throws Exception If the shared element template can't be loaded.
     * @throws LoaderError If the shared element template can't be found.
     * @throws SyntaxError|RuntimeError If the shared element template has a syntax error.
     * @author John Henry Donovan <info@johnhenry.ie>
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

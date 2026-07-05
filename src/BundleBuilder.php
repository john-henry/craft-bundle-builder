<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder;

use Craft;
use craft\base\Plugin as BasePlugin;
use johnhenry\bundlebuilder\base\PluginTrait;
use johnhenry\bundlebuilder\services\ServicesTrait;

/**
 * Bundle Builder plugin.
 *
 * Adds product bundles to Craft Commerce: each bundle is a first-class
 * purchasable element grouping several products together at either a fixed
 * price or an automatically discounted sum of its components. Customers pick a
 * variant per component at add-to-cart time, the chosen variants are frozen
 * onto the order, and the bundle draws its availability from, and decrements,
 * its component variants' inventory.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleBuilder extends BasePlugin
{
    // Traits
    // =========================================================================

    use PluginTrait;
    use ServicesTrait;

    // Static Properties
    // =========================================================================

    /**
     * @var BundleBuilder The plugin instance.
     */
    public static BundleBuilder $plugin;

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the plugin has a CP section.
     */
    public bool $hasCpSection = true;

    /**
     * @var string The plugin's schema version.
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerElementTypes();
        $this->_registerFieldTypes();
        $this->_registerNativeFields();
        $this->_registerPermissions();
        $this->_registerPricingRecalculation();
        $this->_registerTaxAdjuster();
        $this->_registerTwigVariable();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpUrlRules();
        }
    }
}

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RebuildConfigEvent;
use craft\services\ProjectConfig;
use johnhenry\bundlebuilder\base\PluginTrait;
use johnhenry\bundlebuilder\services\BundleTypes;
use johnhenry\bundlebuilder\services\ServicesTrait;
use yii\base\Event;

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
 * @author John Henry Donovan <info@johnhenry.ie>
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
    public string $schemaVersion = '1.0.7';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $bundleTypes = $this->getBundleTypes();
        Craft::$app->getProjectConfig()
            ->onAdd(BundleTypes::CONFIG_BUNDLE_TYPES . '.{uid}', [$bundleTypes, 'handleChangedBundleType'])
            ->onUpdate(BundleTypes::CONFIG_BUNDLE_TYPES . '.{uid}', [$bundleTypes, 'handleChangedBundleType'])
            ->onRemove(BundleTypes::CONFIG_BUNDLE_TYPES . '.{uid}', [$bundleTypes, 'handleDeletedBundleType'])
            ->onRemove('sites.{uid}', [$bundleTypes, 'pruneDeletedSite']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, static function (RebuildConfigEvent $event): void {
            $event->config['bundleBuilder']['bundleTypes'] = [];
            foreach (self::$plugin->getBundleTypes()->getAllBundleTypes() as $bundleType) {
                $event->config['bundleBuilder']['bundleTypes'][$bundleType->uid] = $bundleType->getConfig();
            }
        });

        $this->_registerContainerDeposits();
        $this->_registerElementTypes();
        $this->_registerFieldTypes();
        $this->_registerGql();
        $this->_registerHyperLinkTypes();
        $this->_registerNativeFields();
        $this->_registerNavigationElements();
        $this->_registerPermissions();
        $this->_registerPricingRecalculation();
        $this->_registerTaxAdjuster();
        $this->_registerTwigVariable();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->_registerCpUrlRules();
            $this->_registerOrderEditor();
        }
    }
}

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\base;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\fieldlayoutelements\PurchasableAvailableForPurchaseField;
use craft\commerce\fieldlayoutelements\PurchasableFreeShippingField;
use craft\commerce\fieldlayoutelements\PurchasablePromotableField;
use craft\commerce\fieldlayoutelements\PurchasableSkuField;
use craft\commerce\services\OrderAdjustments;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\TitleField;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use johnhenry\bundlebuilder\adjusters\BundleTaxAdjuster;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\fieldlayoutelements\BundleComponentsField;
use johnhenry\bundlebuilder\fieldlayoutelements\BundlePricingField;
use johnhenry\bundlebuilder\fields\Bundles as BundlesField;
use johnhenry\bundlebuilder\variables\BundleBuilderVariable;
use yii\base\Event;

/**
 * PluginTrait
 *
 * Holds the plugin's event-listener registration, control-panel URL rules, and
 * lifecycle overrides, keeping the main plugin class a thin orchestrating shell.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
trait PluginTrait
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array|null The CP nav item definition.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('bundle-builder', 'Bundles');
        $item['subnav'] = [
            'bundles' => [
                'label' => Craft::t('bundle-builder', 'Bundles'),
                'url' => 'bundle-builder/bundles',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['bundle-types'] = [
                'label' => Craft::t('bundle-builder', 'Bundle Types'),
                'url' => 'bundle-builder/bundle-types',
            ];
        }

        return $item;
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the plugin's control panel URL rules.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge([
                    'bundle-builder' => 'bundle-builder/bundles/index',
                    'bundle-builder/bundles' => 'bundle-builder/bundles/index',
                    'bundle-builder/bundles/new' => 'bundle-builder/bundles/create',
                    'bundle-builder/bundles/<elementId:\d+><slug:(?:-[^\/]*)?>' => 'elements/edit',
                    'bundle-builder/bundle-types' => 'bundle-builder/bundle-types/index',
                    'bundle-builder/bundle-types/new' => 'bundle-builder/bundle-types/edit',
                    'bundle-builder/bundle-types/<bundleTypeId:\d+>' => 'bundle-builder/bundle-types/edit',
                ], $event->rules);
            }
        );
    }

    /**
     * Registers the plugin's element types.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = Bundle::class;
            }
        );
    }

    /**
     * Registers the plugin's field types.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = BundlesField::class;
            }
        );
    }

    /**
     * Registers the bundle element's native field-layout elements (title, SKU,
     * pricing, components, and the optional purchasable toggles) so they appear
     * in the bundle type's field layout designer and the native editor.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerNativeFields(): void
    {
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
            static function(DefineFieldLayoutFieldsEvent $event) {
                /** @var FieldLayout $fieldLayout */
                $fieldLayout = $event->sender;

                if ($fieldLayout->type !== Bundle::class) {
                    return;
                }

                $event->fields[] = TitleField::class;
                $event->fields[] = PurchasableSkuField::class;
                $event->fields[] = BundlePricingField::class;
                $event->fields[] = BundleComponentsField::class;
                $event->fields[] = PurchasableAvailableForPurchaseField::class;
                $event->fields[] = PurchasablePromotableField::class;
                $event->fields[] = PurchasableFreeShippingField::class;
            }
        );
    }

    /**
     * Registers the plugin's user permissions, scoped per bundle type.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $bundleTypePermissions = [];

                foreach ($this->getBundleTypes()->getAllBundleTypes() as $bundleType) {
                    $suffix = ':' . $bundleType->uid;
                    $bundleTypePermissions["bundle-builder:manageBundles{$suffix}"] = [
                        'label' => Craft::t('bundle-builder', 'Manage “{type}” bundles', ['type' => $bundleType->name]),
                    ];
                }

                $event->permissions[] = [
                    'heading' => Craft::t('bundle-builder', 'Bundle Builder'),
                    'permissions' => $bundleTypePermissions,
                ];
            }
        );
    }

    /**
     * Recalculates automatically priced bundles whenever a component product is
     * saved, so their stored price tracks component price changes.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPricingRecalculation(): void
    {
        Event::on(
            Product::class,
            Product::EVENT_AFTER_SAVE,
            function(ModelEvent $event) {
                /** @var Product $product */
                $product = $event->sender;

                if (!$product->id || $product->getIsDraft() || $product->getIsRevision() || $product->propagating) {
                    return;
                }

                $this->getBundlePricing()->recalculateBundlesForProduct($product->id);
            }
        );
    }

    /**
     * Registers the bundle tax adjuster, which apportions VAT across the
     * components of multiple-supply bundles.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTaxAdjuster(): void
    {
        Event::on(
            OrderAdjustments::class,
            OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = BundleTaxAdjuster::class;
            }
        );
    }

    /**
     * Registers the `craft.bundleBuilder` Twig variable.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('bundleBuilder', BundleBuilderVariable::class);
            }
        );
    }
}

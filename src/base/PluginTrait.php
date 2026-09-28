<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\base;

use Craft;
use craft\base\Element;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\fieldlayoutelements\PurchasableAvailableForPurchaseField;
use craft\commerce\fieldlayoutelements\PurchasableFreeShippingField;
use craft\commerce\fieldlayoutelements\PurchasablePromotableField;
use craft\commerce\fieldlayoutelements\PurchasableSkuField;
use craft\commerce\services\OrderAdjustments;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\fieldlayoutelements\TitleField;
use craft\helpers\Json;
use craft\models\FieldLayout;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use johnhenry\bundlebuilder\adjusters\BundleTaxAdjuster;
use johnhenry\bundlebuilder\assets\OrderEditorAsset;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\fieldlayoutelements\BundleComponentsField;
use johnhenry\bundlebuilder\fieldlayoutelements\BundlePricingField;
use johnhenry\bundlebuilder\fields\Bundles as BundlesField;
use johnhenry\bundlebuilder\gql\interfaces\elements\Bundle as BundleInterface;
use johnhenry\bundlebuilder\gql\queries\Bundle as BundleQueries;
use johnhenry\bundlebuilder\helpers\Gql as GqlHelper;
use johnhenry\bundlebuilder\jobs\RecalculateBundlePrices;
use johnhenry\bundlebuilder\links\Bundle as BundleLink;
use johnhenry\bundlebuilder\nodetypes\Bundle as BundleNodeType;
use johnhenry\bundlebuilder\records\BundleProductRecord;
use johnhenry\bundlebuilder\variables\BundleBuilderVariable;
use johnhenry\containerdeposits\events\DefineLineItemContentsEvent;
use johnhenry\containerdeposits\services\DepositCartService;
use verbb\hyper\services\Links;
use verbb\navigation\events\RegisterElementEvent;
use verbb\navigation\events\RegisterNodeTypeEvent;
use verbb\navigation\services\Elements as NavigationElements;
use verbb\navigation\services\NodeTypes;
use yii\base\Event;

/**
 * PluginTrait
 *
 * Event-listener registration, control panel URL rules, and lifecycle
 * overrides for the main plugin class.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait PluginTrait
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<string, mixed>|null The CP nav item definition.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('bundle-builder', 'Bundle Builder');
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
     * Adds each bundle's chosen components to its line on the CP order edit
     * screen, with a variant editor on orders that aren't completed yet.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _registerOrderEditor(): void
    {
        Craft::$app->getView()->hook('cp.commerce.order.edit', function(array &$context): string {
            $order = $context['order'] ?? null;

            if (!$order instanceof Order) {
                return '';
            }

            $lines = $this->getBundleCart()->getOrderComponents($order);

            if (empty($lines)) {
                return '';
            }

            $view = Craft::$app->getView();
            $view->registerAssetBundle(OrderEditorAsset::class);
            $view->registerTranslations('bundle-builder', [
                'Bundle components',
                'Change variants',
                'Change bundle variants',
                'Save',
                'Cancel',
                'Save or discard your changes to the order before changing bundle variants.',
                'Couldn’t change the variants.',
                'Variants changed.',
            ]);
            $view->registerJs(sprintf(
                'new Craft.BundleBuilder.OrderVariantEditor(%s);',
                Json::htmlEncode([
                    'orderId' => $order->id,
                    'editable' => !$order->isCompleted && Craft::$app->getUser()->checkPermission('commerce-editOrders'),
                    'lines' => $lines,
                ]),
            ));

            return '';
        });
    }

    /**
     * Tells Container Deposits what's inside each bundle line, when it's
     * enabled, so the cans and bottles chosen for a bundle carry their deposit
     * the same as they would bought on their own.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _registerContainerDeposits(): void
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('container-deposits') || !class_exists(DefineLineItemContentsEvent::class)) {
            return;
        }

        Event::on(
            DepositCartService::class,
            DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS,
            static function(DefineLineItemContentsEvent $event): void {
                $lineItem = $event->lineItem;

                // Custom line items have no purchasable, and asking for one throws
                if ($lineItem->type !== LineItemType::Purchasable) {
                    return;
                }

                if (!$lineItem->getPurchasable() instanceof Bundle && !isset($lineItem->getSnapshot()['bundleProducts'])) {
                    return;
                }

                foreach (BundleBuilder::getInstance()->getBundleCart()->getComponentVariants($lineItem) as $component) {
                    $event->contents[] = ['purchasable' => $component['variant'], 'qty' => $component['qty']];
                }
            }
        );
    }

    /**
     * Registers the plugin's control panel URL rules.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerElementTypes(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = Bundle::class;
            }
        );
    }

    /**
     * Registers the plugin's field types.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerFieldTypes(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = BundlesField::class;
            }
        );
    }

    /**
     * Registers the bundle GraphQL interface, the `bundles`, `bundle` and
     * `bundleCount` queries, and a read scope per bundle type for schemas.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _registerGql(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event): void {
                $event->types[] = BundleInterface::class;
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event): void {
                $event->queries = array_merge($event->queries, BundleQueries::getQueries());
            }
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            function(RegisterGqlSchemaComponentsEvent $event): void {
                $components = [];

                foreach ($this->getBundleTypes()->getAllBundleTypes() as $bundleType) {
                    $scope = GqlHelper::SCOPE_BUNDLE_TYPES . '.' . $bundleType->uid . ':read';
                    $components[$scope] = [
                        'label' => Craft::t('bundle-builder', 'Query for bundles in the “{name}” bundle type', ['name' => $bundleType->name]),
                    ];
                }

                if (empty($components)) {
                    return;
                }

                $event->queries[Craft::t('bundle-builder', 'Bundles')] = $components;
            }
        );
    }

    /**
     * Registers the Bundle link type with Hyper, when Hyper is enabled.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _registerHyperLinkTypes(): void
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('hyper')) {
            return;
        }

        Event::on(
            Links::class,
            Links::EVENT_REGISTER_LINK_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = BundleLink::class;
            }
        );
    }

    /**
     * Registers the bundle element's native field layout elements.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerNativeFields(): void
    {
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
            static function(DefineFieldLayoutFieldsEvent $event): void {
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
     * Makes bundles a Navigation node type, enabled by default, when Navigation
     * is enabled. Navigation 4 needs the node type registered; Navigation 3
     * auto-discovers element types with URIs but leaves them switched off.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _registerNavigationElements(): void
    {
        if (!Craft::$app->getPlugins()->isPluginEnabled('navigation')) {
            return;
        }

        if (class_exists(NodeTypes::class)) {
            Event::on(
                NodeTypes::class,
                NodeTypes::EVENT_REGISTER_NODE_TYPES,
                static function(RegisterNodeTypeEvent $event): void {
                    if (!in_array(BundleNodeType::class, $event->types, true)) {
                        $event->types[] = BundleNodeType::class;
                    }
                }
            );

            return;
        }

        Event::on(
            NavigationElements::class,
            NavigationElements::EVENT_REGISTER_NAVIGATION_ELEMENT,
            static function(RegisterElementEvent $event): void {
                // Update the auto-discovered entry in place; appending a second
                // one would list bundles twice.
                foreach ($event->elements as &$element) {
                    if (($element['type'] ?? null) === Bundle::class) {
                        $element['default'] = true;
                        $element['button'] = Craft::t('bundle-builder', 'Add a bundle');

                        return;
                    }
                }

                unset($element);

                $event->elements[] = [
                    'label' => Craft::t('bundle-builder', 'Bundles'),
                    'button' => Craft::t('bundle-builder', 'Add a bundle'),
                    'type' => Bundle::class,
                    'sources' => [],
                    'default' => true,
                ];
            }
        );
    }

    /**
     * Registers the plugin's user permissions, scoped per bundle type.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event): void {
                $bundleTypePermissions = [];

                foreach ($this->getBundleTypes()->getAllBundleTypes() as $bundleType) {
                    $bundleTypePermissions[BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $bundleType->uid] = [
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
     * Queues a {@see RecalculateBundlePrices} job when a component product, or
     * one of its variants on its own, is saved, deleted or restored, at most
     * once per product per request. A variant saved by itself (an import, say)
     * doesn't save its product, but can still change the price or which
     * variant is a component's default.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerPricingRecalculation(): void
    {
        // Typed to the common ancestor: after-save passes a ModelEvent, the
        // others a plain Event.
        $queueRecalculation = static function(Event $event): void {
            static $queued = [];

            /** @var Product|Variant $element */
            $element = $event->sender;

            if ($element->getIsDraft() || $element->getIsRevision() || $element->propagating) {
                return;
            }

            $productId = $element instanceof Variant ? $element->getPrimaryOwnerId() : $element->id;

            if (!$productId || isset($queued[$productId])) {
                return;
            }

            if (!BundleProductRecord::find()->where(['productId' => $productId])->exists()) {
                return;
            }

            $queued[$productId] = true;
            Craft::$app->getQueue()->push(new RecalculateBundlePrices(['productId' => $productId]));
        };

        foreach ([Product::class, Variant::class] as $class) {
            Event::on($class, Element::EVENT_AFTER_SAVE, $queueRecalculation);
            Event::on($class, Element::EVENT_AFTER_DELETE, $queueRecalculation);
            Event::on($class, Element::EVENT_AFTER_RESTORE, $queueRecalculation);
        }
    }

    /**
     * Registers the bundle tax adjuster, which apportions tax across the
     * components of multiple-supply bundles.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTaxAdjuster(): void
    {
        Event::on(
            OrderAdjustments::class,
            OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = BundleTaxAdjuster::class;
            }
        );
    }

    /**
     * Registers the `craft.bundleBuilder` Twig variable.
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('bundleBuilder', BundleBuilderVariable::class);
            }
        );
    }
}

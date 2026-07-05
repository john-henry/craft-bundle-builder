<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\elements;

use Craft;
use craft\commerce\base\Purchasable;
use craft\commerce\behaviors\CurrencyAttributeBehavior;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\helpers\Purchasable as PurchasableHelper;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Purchasable as PurchasableRecord;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\validators\DateTimeValidator;
use craft\validators\UniqueValidator;
use DateTime;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\db\BundleQuery;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\migrations\Install;
use johnhenry\bundlebuilder\models\BundleProduct;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\records\BundleProductRecord;
use johnhenry\bundlebuilder\records\BundleRecord;
use yii\db\ActiveQuery;

/**
 * Bundle element.
 *
 * A first-class Commerce purchasable that groups several products together. A
 * bundle is sold as a single line item at either a fixed price or an
 * automatically discounted sum of its components; the customer chooses a variant
 * per component when the bundle is added to the cart. SKU, base price,
 * tax/shipping category and free-shipping settings are stored natively by the
 * Commerce purchasable base class; only the bundle-specific configuration lives
 * in {{%bundlebuilder_bundles}}.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read BundleType $type
 * @property-read BundleProduct[] $products
 */
class Bundle extends Purchasable
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The bundle type's ID.
     */
    public ?int $typeId = null;

    /**
     * @var string The pricing strategy ("fixed" or "automatic").
     */
    public string $pricingStrategy = PricingStrategy::Fixed->value;

    /**
     * @var string|null The automatic discount type ("percentage" or "flat").
     */
    public ?string $discountType = null;

    /**
     * @var float|null The automatic discount amount.
     */
    public ?float $discountAmount = null;

    /**
     * @var DateTime|null The date the bundle becomes available.
     */
    public ?DateTime $postDate = null;

    /**
     * @var DateTime|null The date the bundle stops being available.
     */
    public ?DateTime $expiryDate = null;

    // Private Properties
    // =========================================================================

    /**
     * @var BundleType|null Memoized bundle type.
     */
    private ?BundleType $_type = null;

    /**
     * @var BundleProduct[]|null The products that make up this bundle.
     */
    private ?array $_products = null;

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The display name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('bundle-builder', 'Bundle');
    }

    /**
     * @inheritdoc
     *
     * @return string The lowercase display name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function lowerDisplayName(): string
    {
        return Craft::t('bundle-builder', 'bundle');
    }

    /**
     * @inheritdoc
     *
     * @return string The plural display name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function pluralDisplayName(): string
    {
        return Craft::t('bundle-builder', 'Bundles');
    }

    /**
     * @inheritdoc
     *
     * @return string|null The reference handle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function refHandle(): ?string
    {
        return 'bundle';
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether elements of this type are statusable.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether elements of this type have titles.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function hasTitles(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether elements of this type can have URLs.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function hasUris(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether elements of this type support drafts.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function hasDrafts(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether changes to elements of this type are tracked (autosave).
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * A bundle never gets its own inventory item; stock is tracked and
     * decremented against its component variants instead, so it must never
     * appear on Commerce's Inventory index.
     *
     * @return bool Whether this purchasable type has inventory.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function hasInventory(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     *
     * @return BundleQuery The element query.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function find(): BundleQuery
    {
        return new BundleQuery(static::class);
    }

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

        // A bundle never tracks its own inventory; availability is derived from,
        // and stock is decremented against, its component variants.
        $this->inventoryTracked = false;
    }

    /**
     * @inheritdoc
     *
     * @return array The behaviors.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['currencyAttributes'] = [
            'class' => CurrencyAttributeBehavior::class,
            'currencyAttributes' => $this->currencyAttributes(),
        ];

        return $behaviors;
    }

    /**
     * Returns the bundle's type.
     *
     * @return BundleType The bundle type.
     * @throws \yii\base\InvalidConfigException if the bundle has no valid type.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getType(): BundleType
    {
        if ($this->_type !== null) {
            return $this->_type;
        }

        if (!$this->typeId) {
            throw new \yii\base\InvalidConfigException('Bundle is missing its type ID.');
        }

        $type = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($this->typeId);

        if (!$type) {
            throw new \yii\base\InvalidConfigException('Invalid bundle type ID: ' . $this->typeId);
        }

        return $this->_type = $type;
    }

    /**
     * @inheritdoc
     *
     * @return FieldLayout|null The field layout.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getFieldLayout(): ?FieldLayout
    {
        if (!$this->typeId) {
            return null;
        }

        return $this->getType()->getBundleFieldLayout();
    }

    /**
     * Returns the products that make up this bundle.
     *
     * @return BundleProduct[] The bundle products.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProducts(): array
    {
        if ($this->_products !== null) {
            return $this->_products;
        }

        if (!$this->id) {
            return $this->_products = [];
        }

        $this->_products = [];

        /** @var BundleProductRecord[] $records */
        $records = BundleProductRecord::find()
            ->where(['bundleId' => $this->id])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        foreach ($records as $record) {
            $this->_products[] = new BundleProduct([
                'id' => $record->id,
                'bundleId' => $record->bundleId,
                'productId' => $record->productId,
                'qty' => (int)$record->qty,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
            ]);
        }

        return $this->_products;
    }

    /**
     * Sets the products that make up this bundle.
     *
     * Accepts an array of {@see BundleProduct} models or attribute arrays
     * (e.g. `['productId' => 1, 'qty' => 2]`).
     *
     * @param array $products The bundle products.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setProducts(array $products): void
    {
        $this->_products = [];
        $sortOrder = 0;

        foreach ($products as $product) {
            if (!$product instanceof BundleProduct) {
                // Element selects post their selection as an array of IDs.
                if (isset($product['productId']) && is_array($product['productId'])) {
                    $product['productId'] = reset($product['productId']) ?: null;
                }

                $product = new BundleProduct([
                    'productId' => isset($product['productId']) ? (int)$product['productId'] : null,
                    'qty' => max(1, (int)($product['qty'] ?? 1)),
                    'sortOrder' => isset($product['sortOrder']) ? (int)$product['sortOrder'] : null,
                ]);
            }

            if (!$product->productId) {
                continue;
            }

            $product->sortOrder = $product->sortOrder ?? ++$sortOrder;
            $this->_products[] = $product;
        }
    }

    /**
     * @inheritdoc
     *
     * @return string|null The CP edit URL path.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function cpEditUrl(): ?string
    {
        $path = 'bundle-builder/bundles/' . $this->getCanonicalId();

        if ($this->slug && !str_starts_with($this->slug, '__')) {
            $path .= '-' . str_replace('/', '-', $this->slug);
        }

        return $path;
    }

    /**
     * @inheritdoc
     *
     * @return string|null The URL to redirect to after saving.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('bundle-builder/bundles');
    }

    /**
     * @inheritdoc
     *
     * @param User $user The user to check.
     * @return bool Whether the user can create drafts of the bundle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canCreateDrafts(User $user): bool
    {
        return $this->canSave($user);
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the bundle has revisions.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function hasRevisions(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * @return array The control panel breadcrumbs.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function crumbs(): array
    {
        return [
            [
                'label' => Craft::t('bundle-builder', 'Bundles'),
                'url' => UrlHelper::cpUrl('bundle-builder/bundles'),
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * @return array The supported site definitions.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSupportedSites(): array
    {
        if (!$this->typeId) {
            return [Craft::$app->getSites()->getPrimarySite()->id];
        }

        $sites = [];

        foreach ($this->getType()->getSiteSettings() as $siteSettings) {
            $sites[] = [
                'siteId' => $siteSettings->siteId,
                'enabledByDefault' => true,
            ];
        }

        if (empty($sites)) {
            $sites[] = Craft::$app->getSites()->getPrimarySite()->id;
        }

        return $sites;
    }

    /**
     * @inheritdoc
     *
     * @return string|null The URI format for the current site.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getUriFormat(): ?string
    {
        if (!$this->typeId) {
            return null;
        }

        $siteSettings = $this->getType()->getSiteSettings();

        if (!isset($siteSettings[$this->siteId]) || !$siteSettings[$this->siteId]->hasUrls) {
            return null;
        }

        return $siteSettings[$this->siteId]->uriFormat;
    }

    /**
     * @inheritdoc
     *
     * @return array|string|null The route, or null if none applies.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function route(): array|string|null
    {
        if (!$this->previewing && $this->getStatus() !== self::STATUS_ENABLED) {
            return null;
        }

        $siteSettings = $this->getType()->getSiteSettings();

        if (!isset($siteSettings[$this->siteId]) || !$siteSettings[$this->siteId]->hasUrls) {
            return null;
        }

        return [
            'templates/render', [
                'template' => (string)$siteSettings[$this->siteId]->template,
                'variables' => [
                    'bundle' => $this,
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     *
     * @return string The purchasable description.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDescription(): string
    {
        return (string)$this->title;
    }

    /**
     * @inheritdoc
     *
     * Multiple-supply bundles use a zero-rate category so Commerce's core tax
     * adjuster leaves the line untaxed; the plugin's adjuster then applies tax
     * apportioned across the components.
     *
     * @return int The tax category ID.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getTaxCategoryId(): int
    {
        if ($this->typeId && $this->getType()->taxTreatment === TaxTreatment::Multiple->value) {
            $taxCategory = Commerce::getInstance()
                ?->getTaxCategories()
                ->getTaxCategoryByHandle(Install::APPORTIONED_TAX_CATEGORY_HANDLE);

            if ($taxCategory) {
                return $taxCategory->id;
            }
        }

        return parent::getTaxCategoryId();
    }

    /**
     * @inheritdoc
     *
     * Resolves and records the customer's chosen component variants on the line
     * item (readable options + snapshot) and validates their availability.
     *
     * @param LineItem $lineItem The line item to populate.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function populateLineItem(LineItem $lineItem): void
    {
        parent::populateLineItem($lineItem);

        BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($this, $lineItem);
    }

    /**
     * @inheritdoc
     *
     * Decrements each chosen component variant's inventory once the order
     * completes (the bundle itself doesn't track inventory).
     *
     * @param Order $order The completed order.
     * @param LineItem $lineItem The bundle's line item.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function afterOrderComplete(Order $order, LineItem $lineItem): void
    {
        parent::afterOrderComplete($order, $lineItem);

        BundleBuilder::getInstance()->getBundleCart()->decrementComponentStock($lineItem);
    }

    /**
     * @inheritdoc
     *
     * A bundle is available only within its post/expiry window and while every
     * component has at least one purchasable, in-stock variant.
     *
     * @return bool Whether the bundle is available for purchase.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getIsAvailable(): bool
    {
        if (!parent::getIsAvailable()) {
            return false;
        }

        $now = time();

        if ($this->postDate && $this->postDate->getTimestamp() > $now) {
            return false;
        }

        if ($this->expiryDate && $this->expiryDate->getTimestamp() < $now) {
            return false;
        }

        foreach ($this->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            if ($product && !$this->_productHasStock($product, $bundleProduct->qty)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @param bool $static Whether the fields should be static (read-only).
     * @return string The meta fields HTML for the editor sidebar.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function metaFieldsHtml(bool $static): string
    {
        return implode('', [
            $this->slugFieldHtml($static),
            Cp::dateTimeFieldHtml([
                'label' => Craft::t('app', 'Post Date'),
                'id' => 'postDate',
                'name' => 'postDate',
                'value' => $this->postDate,
                'errors' => $this->getErrors('postDate'),
                'disabled' => $static,
            ]),
            Cp::dateTimeFieldHtml([
                'label' => Craft::t('app', 'Expiry Date'),
                'id' => 'expiryDate',
                'name' => 'expiryDate',
                'value' => $this->expiryDate,
                'errors' => $this->getErrors('expiryDate'),
                'disabled' => $static,
            ]),
            parent::metaFieldsHtml($static),
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return array The preview targets.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function previewTargets(): array
    {
        $previewTargets = $this->typeId ? $this->getType()->previewTargets : [];

        if (empty($previewTargets)) {
            return parent::previewTargets();
        }

        return array_map(static fn(array $target): array => [
            'label' => $target['label'] ?? Craft::t('bundle-builder', 'Primary bundle page'),
            'urlFormat' => $target['urlFormat'] ?? '{url}',
        ], $previewTargets);
    }

    // Authorization
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     *
     * @param User $user The user to check.
     * @return bool Whether the user can view the bundle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canView(User $user): bool
    {
        if (!$this->typeId) {
            return false;
        }

        return $user->can("bundle-builder:manageBundles:{$this->getType()->uid}");
    }

    /**
     * @inheritdoc
     *
     * @param User $user The user to check.
     * @return bool Whether the user can save the bundle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canSave(User $user): bool
    {
        return $this->canView($user);
    }

    /**
     * @inheritdoc
     *
     * @param User $user The user to check.
     * @return bool Whether the user can duplicate the bundle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canDuplicate(User $user): bool
    {
        return $this->canView($user);
    }

    /**
     * @inheritdoc
     *
     * @param User $user The user to check.
     * @return bool Whether the user can delete the bundle.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canDelete(User $user): bool
    {
        return $this->canView($user);
    }

    // Persistence
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     *
     * @param bool $isNew Whether the element is brand new.
     * @return bool Whether the element should be saved.
     * @throws \Throwable if the SKU can't be generated.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function beforeSave(bool $isNew): bool
    {
        // Ensure a slug exists before the SKU is generated, so a "{slug}" SKU
        // format (and the bundle's URL) resolve correctly.
        if (!$this->slug && $this->title) {
            $this->slug = ElementHelper::generateSlug($this->title);
        }

        // Generate a SKU if none was entered: render the bundle type's SKU format
        // if set, otherwise fall back to the slug. Only use a temporary SKU when
        // there's no slug yet (a temp SKU blocks the bundle from being purchased).
        if ($this->getSku() === '' || PurchasableHelper::isTempSku($this->getSku())) {
            $sku = null;
            $skuFormat = $this->typeId ? $this->getType()->skuFormat : null;

            if ($skuFormat) {
                try {
                    $sku = Craft::$app->getView()->renderObjectTemplate($skuFormat, $this);
                } catch (\Throwable) {
                    $sku = null;
                }
            }

            if (!$sku && $this->slug && !str_starts_with($this->slug, '__')) {
                $sku = $this->slug;
            }

            $this->setSku($sku ?: PurchasableHelper::tempSku());
        }

        // Automatic pricing: store the computed price as the base price so
        // Commerce, the cart, and catalog pricing all see a real value.
        if ($this->pricingStrategy === PricingStrategy::Automatic->value) {
            $this->setBasePrice(BundleBuilder::getInstance()->getBundlePricing()->calculatePrice($this));
        }

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     *
     * @param bool $isNew Whether the element is brand new.
     * @return void
     * @throws \Throwable if the bundle record can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating && !$this->getIsRevision()) {
            $record = $isNew ? new BundleRecord() : (BundleRecord::findOne($this->id) ?? new BundleRecord());

            $record->id = $this->id;
            $record->typeId = $this->typeId;
            $record->pricingStrategy = $this->pricingStrategy;
            $record->discountType = $this->discountType;
            $record->discountAmount = $this->discountAmount;
            $record->postDate = Db::prepareDateForDb($this->postDate);
            $record->expiryDate = Db::prepareDateForDb($this->expiryDate);
            $record->save(false);

            $this->_saveProducts();
        }

        parent::afterSave($isNew);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array The table attribute definitions.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'type' => Craft::t('bundle-builder', 'Type'),
            'sku' => Craft::t('commerce', 'SKU'),
            'price' => Craft::t('commerce', 'Price'),
            'postDate' => Craft::t('app', 'Post Date'),
            'expiryDate' => Craft::t('app', 'Expiry Date'),
            'link' => Craft::t('app', 'Link'),
        ];
    }

    /**
     * @inheritdoc
     *
     * @return array The default visible table attributes.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['type', 'sku', 'price', 'postDate', 'link'];
    }

    /**
     * @inheritdoc
     *
     * @return array The sort options.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'postDate' => Craft::t('app', 'Post Date'),
            'expiryDate' => Craft::t('app', 'Expiry Date'),
        ];
    }

    /**
     * @inheritdoc
     *
     * @param string|null $context The source context.
     * @return array The source definitions.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineSources(string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('bundle-builder', 'All bundles'),
                'criteria' => [],
            ],
        ];

        // Only list the types the user can actually manage, so a user scoped to
        // one bundle type doesn't see the others' names in the sidebar.
        foreach (BundleBuilder::getInstance()->getBundleTypes()->getEditableBundleTypes() as $bundleType) {
            $sources[] = [
                'key' => 'bundleType:' . $bundleType->uid,
                'label' => $bundleType->name,
                'data' => ['handle' => $bundleType->handle],
                'criteria' => ['typeId' => $bundleType->id],
            ];
        }

        return $sources;
    }

    /**
     * @inheritdoc
     *
     * @param string $attribute The attribute being rendered.
     * @return string The attribute HTML.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function attributeHtml(string $attribute): string
    {
        if ($attribute === 'type') {
            return $this->typeId ? $this->getType()->name : '';
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Replace Commerce's SKU uniqueness rule with one that also excludes this
        // element's canonical row, so a draft doesn't clash with its own canonical.
        $rules = array_values(array_filter($rules, static fn(array $rule): bool => !(
            ($rule[1] ?? null) === UniqueValidator::class && in_array('sku', (array)$rule[0], true)
        )));

        $canonicalId = $this->getCanonicalId();
        $purchasableTable = PurchasableRecord::tableName();

        $rules[] = [
            ['sku'],
            UniqueValidator::class,
            'targetClass' => PurchasableRecord::class,
            'caseInsensitive' => true,
            'on' => self::SCENARIO_LIVE,
            'filter' => static function(ActiveQuery $query) use ($canonicalId, $purchasableTable) {
                $query->leftJoin(['elements' => CraftTable::ELEMENTS], "[[elements.id]] = $purchasableTable.id");
                $query->andWhere(['elements.revisionId' => null, 'elements.draftId' => null]);

                if ($canonicalId) {
                    $query->andWhere(['not', ['elements.id' => $canonicalId]]);
                }
            },
        ];

        $rules[] = [['typeId'], 'required'];
        $rules[] = [['typeId'], 'number', 'integerOnly' => true];
        $rules[] = [['pricingStrategy'], 'in', 'range' => [PricingStrategy::Fixed->value, PricingStrategy::Automatic->value]];
        $rules[] = [['discountType'], 'in', 'range' => [DiscountType::Percentage->value, DiscountType::Flat->value], 'skipOnEmpty' => true];
        $rules[] = [['discountAmount'], 'number', 'min' => 0];
        $rules[] = [
            ['discountAmount'],
            'number',
            'max' => 100,
            'when' => static fn(Bundle $model): bool => $model->discountType === DiscountType::Percentage->value,
            'tooBig' => Craft::t('bundle-builder', 'A percentage discount can’t exceed 100%.'),
        ];
        $rules[] = [['postDate', 'expiryDate'], DateTimeValidator::class];

        // Attributes assignable from the native element editor's posted form.
        $rules[] = [['sku', 'pricingStrategy', 'discountType', 'discountAmount', 'basePrice', 'basePromotionalPrice', 'products', 'postDate', 'expiryDate'], 'safe'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns whether a component product has at least one purchasable variant
     * able to cover the required quantity.
     *
     * @param Product $product The component product.
     * @param int $qty The quantity required per bundle.
     * @return bool Whether the product has sufficient stock.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _productHasStock(Product $product, int $qty): bool
    {
        foreach ($product->getVariants() as $variant) {
            if (!$variant->inventoryTracked || $variant->getStock() >= $qty) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persists the bundle's product pivot rows, replacing any existing rows.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _saveProducts(): void
    {
        Db::delete('{{%bundlebuilder_products}}', ['bundleId' => $this->id]);

        foreach ($this->getProducts() as $bundleProduct) {
            $record = new BundleProductRecord();
            $record->bundleId = $this->id;
            $record->productId = $bundleProduct->productId;
            $record->qty = $bundleProduct->qty;
            $record->sortOrder = $bundleProduct->sortOrder;
            $record->save(false);
        }
    }
}

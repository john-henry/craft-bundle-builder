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
use craft\commerce\elements\Variant;
use craft\commerce\helpers\Currency;
use craft\commerce\helpers\Purchasable as PurchasableHelper;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Purchasable as PurchasableRecord;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\errors\SiteNotFoundException;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\validators\DateTimeValidator;
use craft\validators\UniqueValidator;
use DateTime;
use GraphQL\Type\Definition\Type;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\elements\db\BundleQuery;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\gql\interfaces\elements\Bundle as BundleInterface;
use johnhenry\bundlebuilder\helpers\Gql;
use johnhenry\bundlebuilder\migrations\Install;
use johnhenry\bundlebuilder\models\BundleProduct;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\records\BundleProductRecord;
use johnhenry\bundlebuilder\records\BundleRecord;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\Exception;

/**
 * Bundle element.
 *
 * A Commerce purchasable that groups several products into one line item,
 * sold at a fixed price or an automatically discounted sum of its components.
 * The customer picks a variant per component at add-to-cart time. Commerce's
 * purchasable tables hold SKU, price and categories; bundle-specific columns
 * live in {{%bundlebuilder_bundles}}.
 *
 * @property-read BundleType $type
 * @property-read null|string $postEditUrl
 * @property-read BundleProduct[] $products
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function trackChanges(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     *
     * Stock is tracked against the component variants, so bundles stay off
     * Commerce's Inventory index.
     *
     * @return bool Whether this purchasable type has inventory.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function find(): BundleQuery
    {
        return new BundleQuery(static::class);
    }

    /**
     * Returns the GraphQL type name for bundles of the given type.
     *
     * @param BundleType $bundleType The bundle type.
     * @return string The GraphQL type name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function gqlTypeName(BundleType $bundleType): string
    {
        return sprintf('%s_Bundle', $bundleType->handle);
    }

    /**
     * Returns the GraphQL type name for bundles of the given type, matching
     * Commerce's product and variant API.
     *
     * @param mixed $context The bundle type.
     * @return string The GraphQL type name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function gqlTypeNameByContext(mixed $context): string
    {
        /** @var BundleType $context */
        return static::gqlTypeName($context);
    }

    /**
     * @inheritdoc
     *
     * @param mixed $context The bundle type.
     * @return string[] The schema scopes needed to read bundles of the type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function gqlScopesByContext(mixed $context): array
    {
        /** @var BundleType $context */
        return [Gql::SCOPE_BUNDLE_TYPES . '.' . $context->uid];
    }

    /**
     * @inheritdoc
     *
     * @return Type The bundle GraphQL interface.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function baseGqlType(): Type
    {
        return BundleInterface::getType();
    }

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

        $this->inventoryTracked = false;
    }

    /**
     * @inheritdoc
     *
     * @return array<array-key, class-string|array{class: class-string, ...}> The behaviors.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @inheritdoc
     *
     * A bundle's type is set when it's created and never changed from the
     * editor, so a posted `typeId` is ignored. Without this, someone allowed
     * to edit bundles of one type could move a bundle into a type they can't
     * manage.
     *
     * @param array<string, mixed> $values The posted attribute values.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function setAttributesFromRequest(array $values): void
    {
        unset($values['typeId']);

        parent::setAttributesFromRequest($values);
    }

    /**
     * Returns the bundle's type.
     *
     * @return BundleType The bundle type.
     * @throws InvalidConfigException if the bundle has no valid type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getType(): BundleType
    {
        // Resolved again if typeId has changed, so permission checks after a
        // change are made against the type the bundle is actually in.
        if ($this->_type !== null && $this->_type->id === (int)$this->typeId) {
            return $this->_type;
        }

        if (!$this->typeId) {
            throw new InvalidConfigException('Bundle is missing its type ID.');
        }

        $type = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($this->typeId);

        if (!$type) {
            throw new InvalidConfigException('Invalid bundle type ID: ' . $this->typeId);
        }

        return $this->_type = $type;
    }

    /**
     * @inheritdoc
     *
     * @return FieldLayout|null The field layout.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @inheritdoc
     *
     * @return string The GraphQL type name for the bundle's type.
     * @throws InvalidConfigException if the bundle has no valid type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getGqlTypeName(): string
    {
        return static::gqlTypeName($this->getType());
    }

    /**
     * Sets a new bundle's status from its bundle type's Default Status for the
     * bundle's site. When the bundle can exist in more than one site, the
     * default applies to this site only, as Craft does for entries.
     *
     * @return void
     * @throws InvalidConfigException if the bundle's type is invalid.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function applyDefaultStatus(): void
    {
        $siteSettings = $this->typeId ? ($this->getType()->getSiteSettings()[$this->siteId] ?? null) : null;
        $enabled = $siteSettings->enabledByDefault ?? true;

        if (Craft::$app->getIsMultiSite() && count($this->getSupportedSites()) > 1) {
            $this->enabled = true;
            $this->setEnabledForSite($enabled);

            return;
        }

        $this->enabled = $enabled;
        $this->setEnabledForSite(true);
    }

    /**
     * Returns the products that make up this bundle.
     *
     * @return BundleProduct[] The bundle products.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProducts(): array
    {
        if ($this->_products !== null) {
            return $this->_products;
        }

        // A duplicate (a copy, a new draft or revision, or a draft or revision
        // being applied) takes its source's components. It can't load its own
        // by ID: a draft or revision being applied carries the live bundle's ID.
        if ($this->duplicateOf instanceof self) {
            $this->setProducts(array_map(static fn(BundleProduct $bundleProduct): array => [
                'productId' => $bundleProduct->productId,
                'qty' => $bundleProduct->qty,
                'variantIds' => $bundleProduct->variantIds,
                'sortOrder' => $bundleProduct->sortOrder,
            ], $this->duplicateOf->getProducts()));

            return $this->_products ?? [];
        }

        if (!$this->id) {
            return [];
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
                'siteId' => $this->siteId,
                'bundleId' => $record->bundleId,
                'productId' => $record->productId,
                'qty' => (int)$record->qty,
                'variantIds' => $record->variantIds !== null ? array_map('intval', Json::decode($record->variantIds)) : null,
                'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
            ]);
        }

        return $this->_products;
    }

    /**
     * Sets the products that make up this bundle.
     *
     * Accepts an array of {@see BundleProduct} models or attribute arrays
     * (e.g. `['productId' => 1, 'qty' => 2, 'variantIds' => [3, 4]]`). An empty
     * string, as the editor posts when every row is removed, clears the
     * components. A `variantIds` of `'*'` or null offers every variant; an
     * empty string, as the editor posts with none ticked, offers none.
     *
     * @param array<int, BundleProduct|array<string, mixed>>|string $products The bundle products.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setProducts(array|string $products): void
    {
        if (!is_array($products)) {
            $products = [];
        }

        $this->_products = [];
        $sortOrder = 0;

        foreach ($products as $product) {
            if (!$product instanceof BundleProduct) {
                // Element selects post their selection as an array of IDs.
                if (isset($product['productId']) && is_array($product['productId'])) {
                    $product['productId'] = reset($product['productId']) ?: null;
                }

                $product = new BundleProduct([
                    'siteId' => $this->siteId,
                    'productId' => isset($product['productId']) ? (int)$product['productId'] : null,
                    'qty' => max(1, (int)($product['qty'] ?? 1)),
                    'variantIds' => $this->_normalizeVariantIds($product['variantIds'] ?? null),
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canCreateDrafts(User $user): bool
    {
        return $this->canSave($user);
    }

    /**
     * @inheritdoc
     *
     * Revisions are opt-in per bundle type, as they are for Commerce product
     * types.
     *
     * @return bool Whether the bundle has revisions.
     * @throws InvalidConfigException if the bundle's type is invalid.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function hasRevisions(): bool
    {
        return $this->typeId && $this->getType()->enableVersioning;
    }

    /**
     * @inheritdoc
     *
     * Saves a revision after each save of the live bundle, as Craft does for
     * entries. Drafts, revisions, propagation and bulk resaves don't create one.
     *
     * @param bool $isNew Whether the element is brand new.
     * @return void
     * @throws Throwable if the revision can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function afterPropagate(bool $isNew): void
    {
        parent::afterPropagate($isNew);

        if (
            $this->id &&
            !$this->propagating &&
            !$this->resaving &&
            !$this->getIsDraft() &&
            !$this->getIsRevision() &&
            $this->hasRevisions()
        ) {
            Craft::$app->getRevisions()->createRevision($this, $this->revisionCreatorId, $this->revisionNotes);
        }
    }

    /**
     * @inheritdoc
     *
     * @return list<array{label: string, url: string}> The control panel breadcrumbs.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @return list<int|array{siteId: int, enabledByDefault: bool}> The supported site IDs or site definitions.
     * @throws SiteNotFoundException|InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
                'enabledByDefault' => $siteSettings->enabledByDefault,
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @return array{0: string, 1: array{template: string, variables: array<string, mixed>}}|string|null The route, or null if none applies.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function route(): array|string|null
    {
        // Scheduled and expired bundles aren't shown, except in preview
        if (!$this->previewing && ($this->getStatus() !== self::STATUS_ENABLED || !$this->_isWithinDates())) {
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
     * Renders the bundle type's description format when set, falling back to
     * the title if it's blank or fails to render.
     *
     * @return string The purchasable description.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getDescription(): string
    {
        $format = $this->typeId ? $this->getType()->descriptionFormat : null;

        if ($format) {
            try {
                $rendered = Craft::$app->getView()->renderSandboxedObjectTemplate($format, $this);

                if ($rendered !== '') {
                    return $rendered;
                }
            } catch (Throwable $e) {
                Craft::warning("Couldn't render the description format for bundle {$this->id}: {$e->getMessage()}", 'bundle-builder');
            }
        }

        return (string)$this->title;
    }

    /**
     * Returns the ID of the zero-rate tax category given to multiple-supply
     * bundle line items, or null if it doesn't exist.
     *
     * @return int|null The apportioned tax category ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function apportionedTaxCategoryId(): ?int
    {
        return Commerce::getInstance()->getTaxCategories()
            ->getTaxCategoryByHandle(Install::APPORTIONED_TAX_CATEGORY_HANDLE)
            ?->id;
    }

    /**
     * @inheritdoc
     *
     * Resolves the customer's chosen component variants into the line item's
     * snapshot and, on an automatically priced bundle, adjusts the line's
     * price for them.
     *
     * @param LineItem $lineItem The line item to populate.
     * @return void
     * @throws InvalidConfigException
     * @throws Throwable if a component's price can't be read.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function populateLineItem(LineItem $lineItem): void
    {
        parent::populateLineItem($lineItem);

        $plugin = BundleBuilder::getInstance();
        $plugin->getBundleCart()->applyToLineItem($this, $lineItem);

        // An automatic bundle's price follows the variants chosen: the stored
        // price is for the defaults, so the line takes the difference.
        $adjustment = $plugin->getBundlePricing()->getSelectionsAdjustment($this, $plugin->getBundleCart()->getSelections($lineItem));

        if ($adjustment !== 0.0) {
            $price = $lineItem->getPrice();
            $promotionalPrice = $lineItem->getPromotionalPrice();
            $lineItem->setPrice(max(0.0, $price + $adjustment));

            // Scaled, so a percentage promotion takes the same share off the
            // difference as off the rest of the bundle
            if ($promotionalPrice !== null) {
                $promotionalAdjustment = $price > 0 ? $adjustment * $promotionalPrice / $price : $adjustment;
                $lineItem->setPromotionalPrice(max(0.0, Currency::round($promotionalPrice + $promotionalAdjustment, $this->getStore()->getCurrency())));
            }

            $snapshot = $lineItem->getSnapshot();
            $snapshot['price'] = $lineItem->getPrice();
            $lineItem->setSnapshot($snapshot);
        }

        // Multiple-supply lines take the zero-rate category, so Commerce's core
        // adjuster leaves them to the bundle tax adjuster. Set on the line, not
        // the bundle, so it's never saved as the bundle's own category.
        if ($this->typeId && $this->getType()->taxTreatment === TaxTreatment::Multiple->value) {
            $apportionedId = self::apportionedTaxCategoryId();

            if ($apportionedId !== null) {
                $lineItem->taxCategoryId = $apportionedId;
            }
        }
    }

    /**
     * @inheritdoc
     *
     * Adds a stock check for the component variants, counted across every line
     * in the order that uses them.
     *
     * @param LineItem $lineItem The line item being validated.
     * @return array<int, array<int|string, mixed>> The validation rules.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getLineItemRules(LineItem $lineItem): array
    {
        $rules = parent::getLineItemRules($lineItem);

        if ($lineItem->getOrder()?->isCompleted) {
            return $rules;
        }

        // Not static: Commerce rebinds line item rule closures to the purchasable.
        $rules[] = [
            'qty',
            function(string $attribute) use ($lineItem): void {
                foreach (BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem) as $error) {
                    $lineItem->addError($attribute, $error);
                }
            },
        ];

        return $rules;
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function afterOrderComplete(Order $order, LineItem $lineItem): void
    {
        parent::afterOrderComplete($order, $lineItem);

        BundleBuilder::getInstance()->getBundleCart()->decrementComponentStock($lineItem);
    }

    /**
     * Returns the lowest and highest this bundle's sale price can be across
     * the variants its components offer, for "from" prices. Both are the sale
     * price on a fixed price bundle.
     *
     * @return array{min: float, max: float} The price range.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getPriceRange(): array
    {
        return BundleBuilder::getInstance()->getBundlePricing()->getPriceRange($this);
    }

    /**
     * Returns how much choosing a variant for a component changes this
     * bundle's price, rounded to the store currency. Zero on a fixed price
     * bundle, and for the component's default variant.
     *
     * @param BundleProduct $bundleProduct The component.
     * @param Variant $variant The variant.
     * @return float The price change.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getVariantPriceAdjustment(BundleProduct $bundleProduct, Variant $variant): float
    {
        $adjustment = BundleBuilder::getInstance()->getBundlePricing()->getVariantAdjustment($this, $bundleProduct, $variant);

        return Currency::round($adjustment, $this->getStore()->getCurrency());
    }

    /**
     * @inheritdoc
     *
     * A bundle is available only if it isn't a draft or revision, within its
     * post/expiry window, and while every component has at least one
     * purchasable, in-stock variant it offers.
     *
     * @return bool Whether the bundle is available for purchase.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getIsAvailable(): bool
    {
        // Drafts and revisions keep a real SKU and their own stored price, and
        // Commerce will look them up by ID when a cart posts one.
        if ($this->getIsDraft() || $this->getIsRevision()) {
            return false;
        }

        if (!parent::getIsAvailable() || !$this->_isWithinDates()) {
            return false;
        }

        $bundleProducts = $this->getProducts();

        if (empty($bundleProducts)) {
            return false;
        }

        foreach ($bundleProducts as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            // A missing component counts as out of stock.
            if (!$product || !$this->_componentHasStock($bundleProduct)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * The slug field is left out when the bundle type hides it; the slug is
     * still generated from the title.
     *
     * @param bool $static Whether the fields should be static (read-only).
     * @return string The meta fields HTML for the editor sidebar.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function metaFieldsHtml(bool $static): string
    {
        $showSlugField = !$this->typeId || $this->getType()->showSlugField;

        return implode('', [
            $showSlugField ? $this->slugFieldHtml($static) : '',
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
     * @return array<array-key, array{label: string, urlFormat: string}> The preview targets.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function canView(User $user): bool
    {
        if (!$this->typeId) {
            return false;
        }

        return $user->can(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $this->getType()->uid);
    }

    /**
     * @inheritdoc
     *
     * @param User $user The user to check.
     * @return bool Whether the user can save the bundle.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @throws Throwable if the SKU can't be generated.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function beforeSave(bool $isNew): bool
    {
        // The slug must exist before a "{slug}" SKU format is rendered.
        if (!$this->slug && $this->title) {
            $this->slug = ElementHelper::generateSlug($this->title);
        }

        // No SKU entered: use the type's SKU format, then the slug. A temporary
        // SKU is the last resort, as it blocks the bundle from being purchased.
        if ($this->getSku() === '' || PurchasableHelper::isTempSku($this->getSku())) {
            $sku = null;
            $skuFormat = $this->typeId ? $this->getType()->skuFormat : null;

            if ($skuFormat) {
                try {
                    $sku = Craft::$app->getView()->renderSandboxedObjectTemplate($skuFormat, $this);
                } catch (Throwable) {
                    $sku = null;
                }
            }

            if (!$sku && $this->slug && !str_starts_with($this->slug, '__')) {
                $sku = $this->slug;
            }

            $this->setSku($sku ?: PurchasableHelper::tempSku());
        }

        // Store the automatic price as the base price, so Commerce, the cart
        // and catalog pricing all see a real value.
        // An automatic bundle has no promotional price of its own; one left
        // from when it was fixed price would cap it and skew variant pricing.
        if ($this->pricingStrategy === PricingStrategy::Automatic->value) {
            $this->setBasePrice(BundleBuilder::getInstance()->getBundlePricing()->calculatePrice($this));
            $this->setBasePromotionalPrice(null);
        }

        return parent::beforeSave($isNew);
    }

    /**
     * @inheritdoc
     *
     * @param bool $isNew Whether the element is brand new.
     * @return void
     * @throws Throwable if the bundle record can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function afterSave(bool $isNew): void
    {
        // Revisions keep their own bundle row and components, so a revision can
        // be loaded and reverted to.
        if (!$this->propagating) {
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

        // Commerce gives any duplicate a temporary SKU, exempting only nested
        // purchasables (variants). A bundle keeps its SKU when a draft or
        // revision is made from it, or applied back to it; otherwise the draft
        // takes the temporary SKU, and applying it replaces the live one. Only
        // a genuine copy, such as "Save as a new bundle", needs a new SKU.
        $keepSku = $this->duplicateOf !== null && (
            $this->duplicateOf->getCanonicalId() === $this->id ||
            ($this->getIsDerivative() && $this->duplicateOf->id === $this->getCanonicalId())
        );
        $sku = $this->getSku();

        parent::afterSave($isNew);

        if ($keepSku && $this->getSku() !== $sku) {
            $this->setSku($sku);
            Db::update(PurchasableRecord::tableName(), ['sku' => $sku], ['id' => $this->id]);
        }
    }

    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validates the bundle's components: a live bundle needs at least one,
     * each product can appear only once, and a component limited to specific
     * variants must offer at least one of its product's. The cart keys each
     * customer's variant choice by product, so a repeated product can't carry
     * its own choice; its quantity covers more than one.
     *
     * @param string $attribute The attribute being validated.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function validateProducts(string $attribute): void
    {
        $productIds = array_map(static fn(BundleProduct $product): ?int => $product->productId, $this->getProducts());

        if (empty($productIds) && $this->getScenario() === self::SCENARIO_LIVE) {
            $this->addError($attribute, Craft::t('bundle-builder', 'Add at least one product to the bundle.'));

            return;
        }

        if (count($productIds) !== count(array_unique($productIds))) {
            $this->addError($attribute, Craft::t('bundle-builder', 'Each product can only be added once. Use Qty to include more than one.'));
        }

        if ($this->getScenario() === self::SCENARIO_LIVE && $this->typeId) {
            $this->_validateComponentSources($attribute, array_values(array_filter($productIds)));
        }

        // Component IDs must be products, not a product's draft or revision; a
        // deleted product stays, flagged in the editor
        $productIds = array_values(array_filter($productIds));
        $draftIds = $productIds ? (new Query())
            ->select(['id'])
            ->from(CraftTable::ELEMENTS)
            ->where(['id' => $productIds])
            ->andWhere(['or', ['not', ['draftId' => null]], ['not', ['revisionId' => null]]])
            ->column() : [];

        if ($draftIds) {
            $this->addError($attribute, Craft::t('bundle-builder', 'Only products can be bundle components, not drafts or revisions of them.'));
        }

        // A component limited to specific variants needs at least one a
        // customer can buy, e.g. not nothing ticked, or only not-for-sale ones
        foreach ($this->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            if ($bundleProduct->variantIds === null || !$product) {
                continue;
            }

            if (!$bundleProduct->getVariants()) {
                $this->addError($attribute, Craft::t('bundle-builder', 'Choose at least one variant of “{product}” that’s available for purchase.', [
                    'product' => $product->title,
                ]));
            }
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<string, string> The table attribute definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @return string[] The default visible table attributes.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['type', 'sku', 'price', 'postDate', 'link'];
    }

    /**
     * @inheritdoc
     *
     * @return array<string, string> The sort options.
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @return list<array{key: string, label: string, data?: array{handle: string}, criteria: array<string, mixed>}> The source definitions.
     * @throws InvalidConfigException|Throwable
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('bundle-builder', 'All bundles'),
                // Only the types the user can manage, the same as the sources below
                'criteria' => ['typeId' => array_map(
                    static fn(BundleType $bundleType): int => (int)$bundleType->id,
                    BundleBuilder::getInstance()->getBundleTypes()->getEditableBundleTypes(),
                ) ?: [0]],
            ],
        ];

        // Only the types the user can manage, so other types' names stay hidden.
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
     * @author John Henry Donovan <info@johnhenry.ie>
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
     * @return array<int, array<int|string, mixed>> The validation rules.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Swap Commerce's SKU uniqueness rule for one that excludes the canonical
        // element, so a draft doesn't clash with its own canonical.
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
        $rules[] = [['products'], 'validateProducts', 'skipOnEmpty' => false];

        // Attributes assignable from the native element editor's posted form.
        $rules[] = [['sku', 'pricingStrategy', 'discountType', 'discountAmount', 'basePrice', 'basePromotionalPrice', 'products', 'postDate', 'expiryDate'], 'safe'];

        return $rules;
    }

    // Private Methods
    // =========================================================================

    /**
     * Adds an error for each component product outside the bundle type's
     * Component Sources. Deleted products are left alone: their rows stay so
     * the bundle can be fixed, and they already make it unavailable.
     *
     * @param string $attribute The attribute being validated.
     * @param int[] $productIds The component product IDs.
     * @return void
     * @throws InvalidConfigException if the bundle's type is invalid.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _validateComponentSources(string $attribute, array $productIds): void
    {
        $sources = $this->getType()->componentSources;

        if ($sources === '*' || empty($productIds)) {
            return;
        }

        $bundleTypes = BundleBuilder::getInstance()->getBundleTypes();
        $existing = $bundleTypes->getProductIdsInSources($productIds, '*');
        $allowed = $bundleTypes->getProductIdsInSources($existing, $sources);

        foreach ($this->getProducts() as $bundleProduct) {
            if (in_array($bundleProduct->productId, $existing, true) && !in_array($bundleProduct->productId, $allowed, true)) {
                $this->addError($attribute, Craft::t('bundle-builder', '“{product}” isn’t in one of this bundle type’s component sources.', [
                    'product' => $bundleProduct->getProduct()->title ?? $bundleProduct->productId,
                ]));
            }
        }
    }

    /**
     * Returns whether at least one of the variants a component offers can
     * cover the quantity it needs per bundle.
     *
     * @param BundleProduct $bundleProduct The component.
     * @return bool Whether the component has sufficient stock.
     * @throws InvalidConfigException
     * @throws Throwable if an out-of-stock purchasing event handler throws.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _componentHasStock(BundleProduct $bundleProduct): bool
    {
        $cart = BundleBuilder::getInstance()->getBundleCart();

        foreach ($bundleProduct->getVariants() as $variant) {
            if ($cart->canVariantCover($variant, $bundleProduct->qty)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether now is within the bundle's post and expiry dates.
     *
     * @return bool Whether the bundle is live by date.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _isWithinDates(): bool
    {
        $now = time();

        if ($this->postDate && $this->postDate->getTimestamp() > $now) {
            return false;
        }

        return !$this->expiryDate || $this->expiryDate->getTimestamp() >= $now;
    }

    /**
     * Normalises a component's posted or stored allowed variants: null for
     * all (the editor's "All" option posts `*`), otherwise a list of IDs. An
     * empty string, as the editor posts with nothing ticked, is an empty list.
     *
     * @param mixed $variantIds The posted or stored value.
     * @return int[]|null The variant IDs, or null for all.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _normalizeVariantIds(mixed $variantIds): ?array
    {
        if ($variantIds === null || $variantIds === '*') {
            return null;
        }

        if (!is_array($variantIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $variantIds))));
    }

    /**
     * Persists the bundle's product pivot rows, replacing any existing rows.
     *
     * @return void
     * @throws Exception
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _saveProducts(): void
    {
        // Read before deleting: if they aren't loaded yet, they load from the
        // rows about to be deleted.
        $products = $this->getProducts();

        Db::delete('{{%bundlebuilder_products}}', ['bundleId' => $this->id]);

        foreach ($products as $bundleProduct) {
            $record = new BundleProductRecord();
            $record->bundleId = $this->id;
            $record->productId = $bundleProduct->productId;
            $record->qty = $bundleProduct->qty;
            $record->variantIds = $bundleProduct->variantIds !== null ? Json::encode($bundleProduct->variantIds) : null;
            $record->sortOrder = $bundleProduct->sortOrder;
            $record->save(false);
        }
    }
}

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\models;

use craft\base\Model;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use yii\base\InvalidConfigException;

/**
 * Bundle product model.
 *
 * Represents a single product included in a bundle, with the quantity required,
 * the variants the customer can choose from and its sort order. The customer
 * chooses which of those variants to buy when the bundle is added to the cart.
 *
 * @property Product|null $product
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleProduct extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The bundle product record ID.
     */
    public ?int $id = null;

    /**
     * @var int|null The bundle's ID.
     */
    public ?int $bundleId = null;

    /**
     * @var int|null The included product's ID.
     */
    public ?int $productId = null;

    /**
     * @var int The quantity of the product required by the bundle.
     */
    public int $qty = 1;

    /**
     * @var int[]|null The IDs of the variants the customer can choose from, or
     * null for all of the product's variants, including any added later.
     */
    public ?array $variantIds = null;

    /**
     * @var int|null The product's sort order within the bundle.
     */
    public ?int $sortOrder = null;

    /**
     * @var int|null The site the product and its variants are read in: the
     * bundle's. Not saved.
     */
    public ?int $siteId = null;

    /**
     * @var int|null The customer whose catalog prices the variants are read
     * at, while the component is priced for an order; null for the current
     * user. Not saved.
     */
    public ?int $customerId = null;

    // Private Properties
    // =========================================================================

    /**
     * @var Product|null Memoized product element.
     */
    private ?Product $_product = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the included product element, or null if it can't be resolved.
     *
     * @return Product|null The product, or null.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProduct(): ?Product
    {
        if ($this->_product === null && $this->productId) {
            $this->_product = Commerce::getInstance()->getProducts()->getProductById($this->productId, $this->siteId);
        }

        return $this->_product;
    }

    /**
     * Sets the included product element, for callers that have already
     * loaded it, so it isn't fetched again.
     *
     * @param Product|null $product The product.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function setProduct(?Product $product): void
    {
        $this->_product = $product;
    }

    /**
     * Returns the variants the customer can choose from: the allowed ones that
     * are available for purchase, in the product's order.
     *
     * @return Variant[] The choosable variants.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getVariants(): array
    {
        $product = $this->getProduct();

        if (!$product) {
            return [];
        }

        // Priced for an order's customer, whose catalog prices may differ from
        // whoever's logged in (an admin editing the order, say)
        $all = $this->customerId !== null
            ? Variant::find()
                ->productId($product->id)
                ->siteId($product->siteId)
                ->forCustomer($this->customerId)
                ->orderBy(['sortOrder' => SORT_ASC])
                ->all()
            : $product->getVariants()->all();

        $variants = [];

        foreach ($all as $variant) {
            if ($variant->availableForPurchase && $this->allowsVariant((int)$variant->id)) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /**
     * Returns the variant chosen when the customer doesn't pick one: the
     * product's default if the bundle offers it, otherwise the first variant
     * the bundle offers.
     *
     * @return Variant|null The default variant, or null if none can be chosen.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getDefaultVariant(): ?Variant
    {
        $variants = $this->getVariants();
        $default = $this->getProduct()?->getDefaultVariant();

        foreach ($variants as $variant) {
            if ($default && (int)$variant->id === (int)$default->id) {
                return $variant;
            }
        }

        return $variants[0] ?? null;
    }

    /**
     * Returns whether the bundle offers the given variant of this product.
     *
     * @param int $variantId The variant ID.
     * @return bool Whether the variant is allowed.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function allowsVariant(int $variantId): bool
    {
        return $this->variantIds === null || in_array($variantId, $this->variantIds, true);
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
        $rules[] = [['productId', 'qty'], 'required'];
        $rules[] = [['id', 'bundleId', 'productId', 'sortOrder'], 'number', 'integerOnly' => true];
        $rules[] = [['qty'], 'number', 'integerOnly' => true, 'min' => 1];

        return $rules;
    }
}

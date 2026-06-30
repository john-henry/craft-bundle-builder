<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\models;

use craft\base\Model;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;

/**
 * Bundle product model.
 *
 * Represents a single product included in a bundle, with the quantity required
 * and its sort order. The customer chooses which of the product's variants to
 * buy when the bundle is added to the cart.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 *
 * @property-read Product|null $product
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
     * @var int|null The product's sort order within the bundle.
     */
    public ?int $sortOrder = null;

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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getProduct(): ?Product
    {
        if ($this->_product === null && $this->productId) {
            $this->_product = Commerce::getInstance()->getProducts()->getProductById($this->productId);
        }

        return $this->_product;
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
        $rules[] = [['productId', 'qty'], 'required'];
        $rules[] = [['id', 'bundleId', 'productId', 'sortOrder'], 'number', 'integerOnly' => true];
        $rules[] = [['qty'], 'number', 'integerOnly' => true, 'min' => 1];

        return $rules;
    }
}

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryUpdateQuantityType;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use johnhenry\bundlebuilder\elements\Bundle;

/**
 * Bundle cart service.
 *
 * Resolves the variant a customer chose for each of a bundle's components when
 * the bundle is added to the cart, validates the choices and their stock, then
 * writes them to the line item as human-readable options and a machine-readable
 * snapshot that the order (and inventory decrement) can rely on.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleCart extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Resolves and validates the chosen component variants for a bundle line
     * item, then stores them as readable options plus a snapshot.
     *
     * @param Bundle $bundle The bundle being added.
     * @param LineItem $lineItem The line item to configure.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function applyToLineItem(Bundle $bundle, LineItem $lineItem): void
    {
        $posted = $this->_postedMap($lineItem);
        $lineQty = max(1, (int)$lineItem->qty);

        $selections = [];
        $options = [];

        foreach ($bundle->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            if (!$product) {
                continue;
            }

            $variant = $this->_chooseVariant($product, $posted[$product->id] ?? null);

            if (!$variant) {
                continue;
            }

            $selections[] = [
                'productId' => (int)$product->id,
                'variantId' => (int)$variant->id,
                'qty' => $bundleProduct->qty,
                'productTitle' => (string)$product->title,
                'variantTitle' => (string)$variant->title,
            ];

            $options[(string)$product->title] = (string)$variant->title;

            if ($variant->inventoryTracked && $variant->getStock() < ($bundleProduct->qty * $lineQty)) {
                $lineItem->addError('options', Craft::t('bundle-builder', '“{product}” isn’t available in the requested quantity.', [
                    'product' => $product->title,
                ]));
            }
        }

        $lineItem->setOptions($options);

        $snapshot = $lineItem->getSnapshot();
        $snapshot['bundleProducts'] = $selections;
        $lineItem->setSnapshot($snapshot);
    }

    /**
     * Returns the bundle component selections recorded on a line item snapshot.
     *
     * @param LineItem $lineItem The line item to read.
     * @return array The component selections.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSelections(LineItem $lineItem): array
    {
        return $lineItem->getSnapshot()['bundleProducts'] ?? [];
    }

    /**
     * Decrements the available inventory of each chosen component variant when a
     * bundle line item's order completes. Untracked variants are skipped.
     *
     * @param LineItem $lineItem The completed bundle line item.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function decrementComponentStock(LineItem $lineItem): void
    {
        // Commerce invokes the purchasable's afterOrderComplete() once, during the
        // order's single markAsComplete() transition, so this runs once per line.
        $lineQty = max(1, (int)$lineItem->qty);
        $inventory = Commerce::getInstance()->getInventory();

        foreach ($this->getSelections($lineItem) as $selection) {
            if (empty($selection['variantId'])) {
                continue;
            }

            /** @var Variant|null $variant */
            $variant = Variant::find()->id((int)$selection['variantId'])->status(null)->one();

            if (!$variant || !$variant->inventoryTracked || !$variant->inventoryItemId) {
                continue;
            }

            $needed = (int)($selection['qty'] ?? 1) * $lineQty;

            if ($needed <= 0) {
                continue;
            }

            $inventory->updatePurchasableInventoryLevel($variant, -$needed, [
                'updateAction' => InventoryUpdateQuantityType::ADJUST,
                'note' => Craft::t('bundle-builder', 'Bundle order: {sku}', ['sku' => $lineItem->getSku()]),
            ]);
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the posted product → variant map for a line item, falling back to
     * the snapshot when the raw posted options are no longer present.
     *
     * @param LineItem $lineItem The line item to read.
     * @return array The product ID → variant ID map.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _postedMap(LineItem $lineItem): array
    {
        $options = $lineItem->getOptions();

        if (isset($options['bundleProducts']) && is_array($options['bundleProducts'])) {
            return $options['bundleProducts'];
        }

        $map = [];

        foreach ($this->getSelections($lineItem) as $selection) {
            if (isset($selection['productId'], $selection['variantId'])) {
                $map[$selection['productId']] = $selection['variantId'];
            }
        }

        return $map;
    }

    /**
     * Chooses the variant for a component: the requested variant if it belongs to
     * the product, otherwise the product's default variant.
     *
     * @param Product $product The component product.
     * @param mixed $variantId The requested variant ID, if any.
     * @return Variant|null The chosen variant, or null if the product has none.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _chooseVariant(Product $product, mixed $variantId): ?Variant
    {
        $variants = $product->getVariants();

        if ($variantId) {
            foreach ($variants as $variant) {
                if ((int)$variant->id === (int)$variantId) {
                    return $variant;
                }
            }
        }

        return $product->getDefaultVariant() ?? ($variants[0] ?? null);
    }
}

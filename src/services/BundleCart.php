<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use Craft;
use craft\base\Component;
use craft\commerce\collections\InventoryMovementCollection;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
use craft\commerce\enums\InventoryTransactionType;
use craft\commerce\enums\LineItemType;
use craft\commerce\helpers\LineItem as LineItemHelper;
use craft\commerce\models\inventory\InventoryCommittedMovement;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleProduct;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Bundle cart service.
 *
 * Resolves the variant a customer chose for each of a bundle's components,
 * records the selections in the line item snapshot, and checks their stock.
 *
 * The posted choices stay in the line item's options untouched: Commerce
 * rebuilds the snapshot on every cart refresh, so the options are the only
 * place they survive, and leaving them as posted keeps identical selections
 * merging into one line.
 *
 * @phpstan-type Selection array{productId?: int, variantId?: int, qty?: int, productTitle?: string, variantTitle?: string, variantSku?: string}
 * @phpstan-type VariantChoice array{id: int, title: string, sku: string}
 * @phpstan-type OrderComponent array{productId: int, variantId: int, productTitle: string, variantTitle: string, sku: string, qtyPerBundle: int, totalQty: int, cpEditUrl: string|null, variants: list<VariantChoice>}
 * @phpstan-type OrderBundleLine array{lineItemId: int|null, sku: string, choices: array<int, int>, description: string, qty: int, components: list<OrderComponent>}
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleCart extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Resolves the chosen component variants for a bundle line item and
     * records them in its snapshot. Stock is checked when the line item is
     * validated; see {@see getStockErrors()}.
     *
     * @param Bundle $bundle The bundle being added.
     * @param LineItem $lineItem The line item to configure.
     * @return void
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function applyToLineItem(Bundle $bundle, LineItem $lineItem): void
    {
        $snapshot = $lineItem->getSnapshot();
        $snapshot['bundleProducts'] = $this->resolveSelections($bundle, $lineItem);
        $lineItem->setSnapshot($snapshot);
    }

    /**
     * Works out the variant chosen for each of a bundle line's components from
     * its current options, without changing the line: the posted variant if
     * the component offers it, otherwise the component's default. Variants are
     * priced for the order's customer.
     *
     * @param Bundle $bundle The line's bundle.
     * @param LineItem $lineItem The line item.
     * @return list<Selection> The component selections.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function resolveSelections(Bundle $bundle, LineItem $lineItem): array
    {
        $posted = $this->_postedMap($lineItem);
        $customerId = $lineItem->getOrder()?->getCustomerId();
        $selections = [];

        foreach ($bundle->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            if (!$product) {
                continue;
            }

            $bundleProduct->customerId = $customerId;
            $variant = $this->_chooseVariant($bundleProduct, $posted[$product->id] ?? null);

            if (!$variant) {
                continue;
            }

            $selections[] = [
                'productId' => (int)$product->id,
                'variantId' => (int)$variant->id,
                'qty' => $bundleProduct->qty,
                'productTitle' => (string)$product->title,
                'variantTitle' => (string)$variant->title,
                'variantSku' => $variant->getSku(),
            ];
        }

        return $selections;
    }

    /**
     * Returns the chosen components of every bundle line on an order, for the
     * order edit screen. Titles and SKUs come from the line's snapshot, so they
     * show what was bought even if the products have changed since. On an
     * incomplete order the components come from the bundle as it is now, each
     * with the variants it can be switched to.
     *
     * @param Order $order The order.
     * @return list<OrderBundleLine> The bundle lines and their components.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getOrderComponents(Order $order): array
    {
        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            $selections = $this->getSelections($lineItem);

            if (empty($selections)) {
                continue;
            }

            $lineQty = max(1, (int)$lineItem->qty);
            $bundle = !$order->isCompleted && $lineItem->type === LineItemType::Purchasable
                ? $lineItem->getPurchasable()
                : null;

            $lines[] = [
                'lineItemId' => $lineItem->id,
                'sku' => $lineItem->getSku(),
                'choices' => $this->_choiceIds($lineItem),
                'description' => $lineItem->getDescription(),
                'qty' => $lineQty,
                'components' => $bundle instanceof Bundle
                    ? $this->_currentComponents($bundle, $selections, $lineItem, $lineQty)
                    : $this->_snapshotComponents($selections, $lineQty),
            ];
        }

        return $lines;
    }

    /**
     * Changes the variants chosen for a bundle line on an incomplete order,
     * then saves the order. The line is refreshed before saving, so the
     * component stock check runs against the new choices.
     *
     * @param Order $order The order.
     * @param int $lineItemId The bundle line item's ID.
     * @param array<int|string, mixed> $variantIds Posted variant IDs keyed by product ID.
     * @return string[] The errors, empty when the change was saved.
     * @throws Throwable if the order can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function changeVariants(Order $order, int $lineItemId, array $variantIds): array
    {
        if ($order->isCompleted) {
            return [Craft::t('bundle-builder', 'Variants can only be changed before the order is completed.')];
        }

        $lineItem = null;

        foreach ($order->getLineItems() as $item) {
            if ($item->type === LineItemType::Purchasable && $item->id === $lineItemId) {
                $lineItem = $item;
                break;
            }
        }

        $bundle = $lineItem?->getPurchasable();

        if (!$lineItem || !$bundle instanceof Bundle) {
            return [Craft::t('bundle-builder', 'That bundle line isn’t on this order.')];
        }

        $map = [];

        foreach ($bundle->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            // A deleted component makes the bundle unavailable, which the
            // refresh below reports.
            if (!$product) {
                continue;
            }

            $bundleProduct->customerId = $order->getCustomerId();

            $productId = (int)$product->id;
            $variantId = (int)($variantIds[$productId] ?? 0);
            $selectableIds = array_map(static fn(Variant $variant): int => (int)$variant->id, $bundleProduct->getVariants());

            if (!in_array($variantId, $selectableIds, true)) {
                return [Craft::t('bundle-builder', 'Choose a variant for “{product}”.', [
                    'product' => $product->title,
                ])];
            }

            // Strings, as a cart form posts them, so the options signature
            // matches if the customer adds the same choices again.
            $map[$productId] = (string)$variantId;
        }

        $options = ['bundleProducts' => $map] + $lineItem->getOptions();
        $signature = LineItemHelper::generateOptionsSignature($options);

        foreach ($order->getLineItems() as $item) {
            if ($item !== $lineItem && $item->purchasableId === $lineItem->purchasableId && $item->getOptionsSignature() === $signature) {
                return [Craft::t('bundle-builder', 'Another line already has these choices. Change its quantity instead.')];
            }
        }

        $lineItem->setOptions($options);

        // Commerce drops a line whose bundle is no longer available when the
        // order is saved, so don't report that as a change of variants.
        if (!$lineItem->refresh()) {
            return [Craft::t('bundle-builder', 'This bundle is no longer available, so its variants can’t be changed.')];
        }

        if (!Craft::$app->getElements()->saveElement($order)) {
            return $order->getErrorSummary(true) ?: [Craft::t('bundle-builder', 'Couldn’t save the order.')];
        }

        return [];
    }

    /**
     * Returns a stock error for each of a bundle line's components that can't
     * cover what the whole order needs of its variant, counting other bundle
     * lines and the variant bought on its own. Choices are worked out from
     * each line's current options, not its snapshot: when a cart update
     * changes a line's variants, the snapshot isn't rebuilt until after the
     * order is validated.
     *
     * @param LineItem $lineItem The bundle line item.
     * @return string[] The errors, empty when every component is covered.
     * @throws Throwable if an out-of-stock purchasing event handler throws.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getStockErrors(LineItem $lineItem): array
    {
        $lineItems = $lineItem->getOrder()?->getLineItems() ?? [];

        if (!in_array($lineItem, $lineItems, true)) {
            $lineItems[] = $lineItem;
        }

        $required = $this->_requiredByVariant($lineItems);
        $errors = [];

        foreach ($this->_currentSelections($lineItem) as $selection) {
            $variantId = (int)($selection['variantId'] ?? 0);
            /** @var Variant|null $variant */
            $variant = $variantId ? Variant::find()->id($variantId)->status(null)->one() : null;

            if (!$variant || !$this->canVariantCover($variant, $required[$variantId] ?? 0)) {
                $errors[] = Craft::t('bundle-builder', '“{product}” isn’t available in the requested quantity.', [
                    'product' => $selection['productTitle'] ?? $variant?->getProduct()->title ?? '',
                ]);
            }
        }

        return $errors;
    }

    /**
     * Returns whether a component variant can cover the given quantity: it
     * isn't stock-tracked, has enough stock, or allows out-of-stock purchases.
     *
     * @param Variant $variant The component variant.
     * @param int $qty The quantity required.
     * @return bool Whether the variant can cover the quantity.
     * @throws Throwable if an out-of-stock purchasing event handler throws.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function canVariantCover(Variant $variant, int $qty): bool
    {
        if (!$variant->inventoryTracked || $variant->getStock() >= $qty) {
            return true;
        }

        return $variant->getIsOutOfStockPurchasingAllowed();
    }

    /**
     * Returns the bundle component selections recorded on a line item snapshot.
     *
     * @param LineItem $lineItem The line item to read.
     * @return list<Selection> The component selections.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSelections(LineItem $lineItem): array
    {
        return $lineItem->getSnapshot()['bundleProducts'] ?? [];
    }

    /**
     * Returns the variant chosen for each component of a bundle line, with how
     * many of it one bundle holds. Read the way the stock check reads them, so
     * a change of variant counts before the line's snapshot catches up.
     * Components whose variant no longer exists are left out.
     *
     * @param LineItem $lineItem The bundle line item.
     * @return list<array{variant: Variant, qty: int}> The chosen variants.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getComponentVariants(LineItem $lineItem): array
    {
        $qtyByVariant = [];
        foreach ($this->_currentSelections($lineItem) as $selection) {
            $variantId = (int)($selection['variantId'] ?? 0);
            if ($variantId) {
                $qtyByVariant[$variantId] = ($qtyByVariant[$variantId] ?? 0) + (int)($selection['qty'] ?? 1);
            }
        }

        if (!$qtyByVariant) {
            return [];
        }

        $components = [];
        /** @var Variant $variant */
        foreach (Variant::find()->id(array_keys($qtyByVariant))->status(null)->all() as $variant) {
            $components[] = ['variant' => $variant, 'qty' => $qtyByVariant[$variant->id]];
        }

        return $components;
    }

    /**
     * Commits the stock of each chosen component variant when a bundle line
     * item's order completes, the way Commerce commits a variant bought on its
     * own: from the first inventory location with enough available, or the
     * first location if none has, moved from available to committed against
     * the bundle's line. Untracked variants are skipped.
     *
     * @param LineItem $lineItem The completed bundle line item.
     * @return void
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function decrementComponentStock(LineItem $lineItem): void
    {
        // Commerce invokes the purchasable's afterOrderComplete() once, during the
        // order's single markAsComplete() transition, so this runs once per line.
        $lineQty = max(1, (int)$lineItem->qty);
        $commerce = Commerce::getInstance();
        $movements = InventoryMovementCollection::make();
        $variants = [];

        foreach ($this->getSelections($lineItem) as $selection) {
            if (empty($selection['variantId'])) {
                continue;
            }

            /** @var Variant|null $variant */
            $variant = Variant::find()->id((int)$selection['variantId'])->status(null)->one();
            $needed = (int)($selection['qty'] ?? 1) * $lineQty;

            if (!$variant || !$variant->inventoryTracked || !$variant->inventoryItemId || $needed <= 0) {
                continue;
            }

            $levels = $variant->getInventoryLevels();
            $level = $levels->first(static fn($level): bool => $level->availableTotal >= $needed) ?? $levels->first();

            if (!$level) {
                continue;
            }

            $movement = new InventoryCommittedMovement();
            $movement->inventoryItemId = $level->inventoryItemId;
            $movement->fromInventoryLocation = $level->getInventoryLocation();
            $movement->toInventoryLocation = $level->getInventoryLocation();
            $movement->fromInventoryTransactionType = InventoryTransactionType::AVAILABLE;
            $movement->toInventoryTransactionType = InventoryTransactionType::COMMITTED;
            $movement->quantity = $needed;
            $movement->lineItemId = $lineItem->id;

            $movements->push($movement);
            $variants[] = $variant;
        }

        if ($movements->isEmpty()) {
            return;
        }

        // This runs after the order is already marked complete, so a failure
        // mustn't throw out of the order-complete. Log it instead.
        try {
            $commerce->getInventory()->executeInventoryMovements($movements);

            foreach ($variants as $variant) {
                $commerce->getPurchasables()->updateStoreStockCache($variant, true);
            }
        } catch (Throwable $e) {
            Craft::error(
                "Couldn't commit component stock for bundle order {$lineItem->getSku()}: {$e->getMessage()}",
                'bundle-builder',
            );
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Totals the quantity of each variant needed across the given line items:
     * bundle components times the line quantity, plus variants bought directly.
     *
     * @param LineItem[] $lineItems The order's line items.
     * @return array<int, int> Quantities keyed by variant ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _requiredByVariant(array $lineItems): array
    {
        $required = [];

        foreach ($lineItems as $item) {
            // Custom line items have no purchasable, and Commerce throws if asked for one.
            if ($item->type !== LineItemType::Purchasable) {
                continue;
            }

            $lineQty = max(1, (int)$item->qty);

            if (!$item->getPurchasable() instanceof Bundle) {
                if ($item->purchasableId) {
                    $required[$item->purchasableId] = ($required[$item->purchasableId] ?? 0) + $lineQty;
                }
                continue;
            }

            foreach ($this->_currentSelections($item) as $selection) {
                $variantId = (int)($selection['variantId'] ?? 0);

                if ($variantId) {
                    $required[$variantId] = ($required[$variantId] ?? 0) + (int)($selection['qty'] ?? 1) * $lineQty;
                }
            }
        }

        return $required;
    }

    /**
     * Returns a bundle line's selections as its current options resolve, or
     * its snapshot's if the line's bundle can't be loaded.
     *
     * @param LineItem $lineItem The bundle line item.
     * @return list<Selection> The component selections.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _currentSelections(LineItem $lineItem): array
    {
        $bundle = $lineItem->type === LineItemType::Purchasable ? $lineItem->getPurchasable() : null;

        return $bundle instanceof Bundle ? $this->resolveSelections($bundle, $lineItem) : $this->getSelections($lineItem);
    }

    /**
     * Returns the posted product → variant map from a line item's options.
     *
     * @param LineItem $lineItem The line item to read.
     * @return array<int|string, mixed> The product ID → variant ID map.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _postedMap(LineItem $lineItem): array
    {
        $options = $lineItem->getOptions();

        $map = $options['bundleProducts'] ?? null;

        return is_array($map) ? $map : [];
    }

    /**
     * Chooses the variant for a component: the requested variant if the
     * component offers it, otherwise the component's default.
     *
     * @param BundleProduct $bundleProduct The component.
     * @param mixed $variantId The requested variant ID, if any.
     * @return Variant|null The chosen variant, or null if the component offers none.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _chooseVariant(BundleProduct $bundleProduct, mixed $variantId): ?Variant
    {
        if ($variantId) {
            foreach ($bundleProduct->getVariants() as $variant) {
                if ((int)$variant->id === (int)$variantId) {
                    return $variant;
                }
            }
        }

        return $bundleProduct->getDefaultVariant();
    }

    /**
     * Returns a line's posted choices as integer product and variant IDs,
     * dropping anything that isn't one. The editor only needs the IDs, and
     * they go into the page's JavaScript.
     *
     * @param LineItem $lineItem The line item.
     * @return array<int, int> Variant IDs keyed by product ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _choiceIds(LineItem $lineItem): array
    {
        $ids = [];

        foreach ($this->_postedMap($lineItem) as $productId => $variantId) {
            if (is_numeric($productId) && is_numeric($variantId)) {
                $ids[(int)$productId] = (int)$variantId;
            }
        }

        return $ids;
    }

    /**
     * Builds an incomplete order line's editor components from the bundle's
     * current components, so the editor shows every component a change has to
     * cover, including one added since the cart was last refreshed.
     *
     * @param Bundle $bundle The line's bundle.
     * @param Selection[] $selections The line's snapshot selections.
     * @param LineItem $lineItem The line item.
     * @param int $lineQty The line quantity.
     * @return list<OrderComponent> The components.
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _currentComponents(Bundle $bundle, array $selections, LineItem $lineItem, int $lineQty): array
    {
        $posted = $this->_choiceIds($lineItem);
        $selectedByProduct = [];

        foreach ($selections as $selection) {
            $selectedByProduct[(int)($selection['productId'] ?? 0)] = (int)($selection['variantId'] ?? 0);
        }

        $components = [];

        foreach ($bundle->getProducts() as $bundleProduct) {
            $product = $bundleProduct->getProduct();

            if (!$product) {
                continue;
            }

            $bundleProduct->customerId = $lineItem->getOrder()?->getCustomerId();
            $selectable = $bundleProduct->getVariants();
            $wantedId = $selectedByProduct[(int)$product->id] ?? $posted[(int)$product->id] ?? null;
            $variant = $this->_chooseVariant($bundleProduct, $wantedId);

            $components[] = [
                'productId' => (int)$product->id,
                'variantId' => (int)$variant?->id,
                'productTitle' => (string)$product->title,
                'variantTitle' => (string)$variant?->title,
                'sku' => (string)$variant?->getSku(),
                'qtyPerBundle' => $bundleProduct->qty,
                'totalQty' => $bundleProduct->qty * $lineQty,
                'cpEditUrl' => $product->getCpEditUrl(),
                'variants' => array_map(static fn(Variant $choice): array => [
                    'id' => (int)$choice->id,
                    'title' => (string)$choice->title,
                    'sku' => $choice->getSku(),
                ], $selectable),
            ];
        }

        return $components;
    }

    /**
     * Builds a line's editor components from its snapshot: what was bought,
     * even if the products have changed since. There's nothing to switch to.
     *
     * @param Selection[] $selections The line's snapshot selections.
     * @param int $lineQty The line quantity.
     * @return list<OrderComponent> The components.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _snapshotComponents(array $selections, int $lineQty): array
    {
        $components = [];

        foreach ($selections as $selection) {
            $variantId = (int)($selection['variantId'] ?? 0);
            /** @var Variant|null $variant */
            $variant = $variantId ? Variant::find()->id($variantId)->status(null)->trashed(null)->one() : null;
            $qtyPerBundle = (int)($selection['qty'] ?? 1);

            $components[] = [
                'productId' => (int)($selection['productId'] ?? 0),
                'variantId' => $variantId,
                'productTitle' => $selection['productTitle'] ?? '',
                'variantTitle' => $selection['variantTitle'] ?? '',
                'sku' => $selection['variantSku'] ?? $variant?->getSku() ?? '',
                'qtyPerBundle' => $qtyPerBundle,
                'totalQty' => $qtyPerBundle * $lineQty,
                'cpEditUrl' => $variant?->getOwner()?->getCpEditUrl(),
                'variants' => [],
            ];
        }

        return $components;
    }
}

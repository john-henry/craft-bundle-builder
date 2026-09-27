<?php

/**
 * Coverage for BundleCart. applyToLineItem() is tested against real saved
 * Commerce products (the add-to-cart path that resolves variants, writes
 * readable options + a snapshot, and validates stock). getSelections() and the
 * decrementComponentStock() guard paths are tested with hand-built line items.
 * The full inventory-decrement against live stock is left to manual QA.
 */

use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;

/**
 * Builds a LineItem carrying the given bundle component selections in its
 * snapshot, mirroring what applyToLineItem() writes.
 *
 * @param array<int, array<string, mixed>> $selections
 */
function bundleLineItem(array $selections): LineItem
{
    $lineItem = new LineItem();
    $lineItem->qty = 1;

    $snapshot = $lineItem->getSnapshot() ?? [];
    $snapshot['bundleProducts'] = $selections;
    $lineItem->setSnapshot($snapshot);

    return $lineItem;
}

// ---------------------------------------------------------------------------
// applyToLineItem(): real products
// ---------------------------------------------------------------------------

describe('BundleCart::applyToLineItem()', function () {
    it('leaves the posted options alone and snapshots the chosen variants', function () {
        $product = makeProduct(10.0);
        $bundle = bundleWithComponents([['product' => $product, 'qty' => 2]]);

        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setOptions(['giftNote' => 'Happy birthday']);
        $lineItem->setSnapshot([]);

        BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

        $variant = Commerce::getInstance()->getProducts()
            ->getProductById($product->id)?->getDefaultVariant();

        expect($lineItem->getOptions())->toBe(['giftNote' => 'Happy birthday']);

        $selections = BundleBuilder::getInstance()->getBundleCart()->getSelections($lineItem);
        expect($selections)->toHaveCount(1);
        expect($selections[0]['productId'])->toBe((int)$product->id);
        expect($selections[0]['variantId'])->toBe((int)$variant?->id);
        expect($selections[0]['qty'])->toBe(2);
    });
});

// ---------------------------------------------------------------------------
// getSelections()
// ---------------------------------------------------------------------------

describe('BundleCart::getSelections()', function () {
    it('returns an empty array when the snapshot has no bundle products', function () {
        $lineItem = new LineItem();
        $lineItem->setSnapshot([]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getSelections($lineItem))
            ->toBeArray()
            ->toBeEmpty();
    });

    it('returns the selections recorded on the snapshot', function () {
        $selections = [
            ['productId' => 1, 'variantId' => 11, 'qty' => 2, 'productTitle' => 'Mug', 'variantTitle' => 'Blue'],
            ['productId' => 2, 'variantId' => 22, 'qty' => 1, 'productTitle' => 'Spoon', 'variantTitle' => 'Steel'],
        ];

        $result = BundleBuilder::getInstance()->getBundleCart()
            ->getSelections(bundleLineItem($selections));

        expect($result)->toHaveCount(2);
        expect($result[0]['variantId'])->toBe(11);
        expect($result[1]['qty'])->toBe(1);
    });
});

// ---------------------------------------------------------------------------
// decrementComponentStock()
// ---------------------------------------------------------------------------

describe('BundleCart::decrementComponentStock()', function () {
    it('does nothing when the line item has no selections', function () {
        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setSnapshot([]);

        BundleBuilder::getInstance()->getBundleCart()->decrementComponentStock($lineItem);

        expect(true)->toBeTrue();
    });

    it('skips selections that carry no variant id', function () {
        $lineItem = bundleLineItem([
            ['productId' => 1, 'qty' => 1],
        ]);

        BundleBuilder::getInstance()->getBundleCart()->decrementComponentStock($lineItem);

        expect(true)->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// getStockErrors(): counted across the whole order
// ---------------------------------------------------------------------------

/**
 * Builds a populated bundle line item for the given bundle and quantity.
 */
function bundleCartLine(Bundle $bundle, int $qty): LineItem
{
    $lineItem = new LineItem();
    $lineItem->qty = $qty;
    $lineItem->setOptions([]);
    $lineItem->setSnapshot([]);
    $lineItem->setPurchasable($bundle);

    BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

    return $lineItem;
}

describe('BundleCart::getStockErrors()', function () {
    it('reports a tracked component that is short', function () {
        $bundle = bundleWithComponents([['product' => makeProduct(10.0, stock: 1), 'qty' => 5]]);
        $lineItem = bundleCartLine($bundle, 1);
        orderWithLineItems([$lineItem]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem))->toHaveCount(1);
    });

    it('reports nothing when stock is sufficient', function () {
        $bundle = bundleWithComponents([['product' => makeProduct(10.0, stock: 10), 'qty' => 2]]);
        $lineItem = bundleCartLine($bundle, 1);
        orderWithLineItems([$lineItem]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem))->toBe([]);
    });

    it('multiplies the required stock by the line item quantity', function () {
        // 3 per bundle × 2 bundles = 6 needed, only 5 in stock.
        $bundle = bundleWithComponents([['product' => makeProduct(10.0, stock: 5), 'qty' => 3]]);
        $lineItem = bundleCartLine($bundle, 2);
        orderWithLineItems([$lineItem]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem))->toHaveCount(1);
    });

    it('counts the same variant across every line in the order', function () {
        // 3 + 3 across two bundle lines = 6 needed, only 5 in stock; either
        // line alone would fit.
        $product = makeProduct(10.0, stock: 5);
        $first = bundleCartLine(bundleWithComponents([['product' => $product, 'qty' => 3]]), 1);
        $second = bundleCartLine(bundleWithComponents([['product' => $product, 'qty' => 3]]), 1);
        orderWithLineItems([$first, $second]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($first))->toHaveCount(1);
    });

    it('reports nothing when the component allows out-of-stock purchases', function () {
        $product = makeProduct(10.0, stock: 0, allowOutOfStockPurchases: true);
        $lineItem = bundleCartLine(bundleWithComponents([['product' => $product, 'qty' => 2]]), 1);
        orderWithLineItems([$lineItem]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem))->toBe([]);
    });

    it('fails line item validation when a component is short', function () {
        $bundle = makeSavedBundle(makeBundleType('Short', 'short' . StringHelper::randomString(6)), [
            ['product' => makeProduct(10.0, stock: 1), 'qty' => 5],
        ]);
        $lineItem = bundleCartLine($bundle, 1);
        $lineItem->purchasableId = $bundle->id;
        orderWithLineItems([$lineItem]);

        $lineItem->validate();

        expect(implode(' ', $lineItem->getErrors('qty')))->toContain('isn’t available in the requested quantity');
    });
});

describe('BundleCart::getStockErrors() after the variants change', function () {
    it('checks the newly chosen variant, not the one in the snapshot', function () {
        $product = makeProduct(10.0, stock: 10);
        $soldOut = addVariant($product, 10.0, stock: 0);
        $bundle = makeSavedBundle(makeBundleType('Switch ' . StringHelper::randomString(6), 'switch' . StringHelper::randomString(6)), [
            ['product' => $product, 'qty' => 1],
        ]);

        // The snapshot has the in-stock default; a cart update then posts the
        // sold-out variant, validated before the snapshot is rebuilt
        $lineItem = bundleCartLine($bundle, 1);
        $lineItem->setOptions(['bundleProducts' => [$product->id => (string)$soldOut->id]]);
        orderWithLineItems([$lineItem]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem))->toHaveCount(1);
    });
});

describe('BundleCart::decrementComponentStock() with tracked stock', function () {
    it('takes the components’ stock from what’s available', function () {
        $product = makeProduct(10.0, stock: 10);
        $bundle = makeSavedBundle(makeBundleType('Commit ' . StringHelper::randomString(6), 'commit' . StringHelper::randomString(6)), [
            ['product' => $product, 'qty' => 2],
        ]);
        $lineItem = bundleCartLine($bundle, 3);

        BundleBuilder::getInstance()->getBundleCart()->decrementComponentStock($lineItem);

        $variant = Commerce::getInstance()->getProducts()->getProductById($product->id)?->getDefaultVariant();
        expect($variant->getStock())->toBe(4);
    });
});


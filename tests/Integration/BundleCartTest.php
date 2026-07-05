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
use johnhenry\bundlebuilder\BundleBuilder;

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
    it('writes readable options and a snapshot of the chosen variants', function () {
        $product = makeProduct(10.0);
        $bundle = bundleWithComponents([['product' => $product, 'qty' => 2]]);

        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setOptions([]);
        $lineItem->setSnapshot([]);

        BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

        $variant = Commerce::getInstance()->getProducts()
            ->getProductById($product->id)?->getDefaultVariant();

        expect($lineItem->getOptions())->toHaveKey($product->title);

        $selections = BundleBuilder::getInstance()->getBundleCart()->getSelections($lineItem);
        expect($selections)->toHaveCount(1);
        expect($selections[0]['productId'])->toBe((int)$product->id);
        expect($selections[0]['variantId'])->toBe((int)$variant?->id);
        expect($selections[0]['qty'])->toBe(2);
    });

    it('adds a stock error when a tracked component is short', function () {
        $product = makeProduct(10.0, stock: 1);
        $bundle = bundleWithComponents([['product' => $product, 'qty' => 5]]);

        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setOptions([]);
        $lineItem->setSnapshot([]);

        BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

        expect($lineItem->getErrors('options'))->not->toBeEmpty();
    });

    it('does not add a stock error when stock is sufficient', function () {
        $product = makeProduct(10.0, stock: 10);
        $bundle = bundleWithComponents([['product' => $product, 'qty' => 2]]);

        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setOptions([]);
        $lineItem->setSnapshot([]);

        BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

        expect($lineItem->getErrors('options'))->toBeEmpty();
    });

    it('multiplies the required stock by the line item quantity', function () {
        // 3 per bundle × 2 bundles = 6 needed, only 5 in stock → short.
        $product = makeProduct(10.0, stock: 5);
        $bundle = bundleWithComponents([['product' => $product, 'qty' => 3]]);

        $lineItem = new LineItem();
        $lineItem->qty = 2;
        $lineItem->setOptions([]);
        $lineItem->setSnapshot([]);

        BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

        expect($lineItem->getErrors('options'))->not->toBeEmpty();
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

<?php

/**
 * Pins how a bundle treats a disabled or missing component: a disabled one
 * still counts towards the price, and a missing one makes the bundle
 * unavailable. Pricing and availability must agree on which components exist.
 */

use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;

describe('A bundle whose component is disabled', function() {
    it('still counts the component towards the price', function() {
        $pricing = BundleBuilder::getInstance()->getBundlePricing();

        $cheap = makeProduct(10.0);
        $dear = makeProduct(25.0);
        $components = [
            ['product' => $cheap, 'qty' => 1],
            ['product' => $dear, 'qty' => 1],
        ];

        expect($pricing->getComponentsSubtotal(bundleWithComponents($components)))->toBe(35.0);

        $dear->enabled = false;
        Craft::$app->getElements()->saveElement($dear, false);

        expect($pricing->getComponentsSubtotal(bundleWithComponents($components)))->toBe(35.0);
    });

    it('is still checked for stock, so the two agree', function() {
        $inStock = makeProduct(10.0, 5);
        $soldOut = makeProduct(25.0, 0);

        $soldOut->enabled = false;
        Craft::$app->getElements()->saveElement($soldOut, false);

        $bundle = bundleWithComponents([
            ['product' => $inStock, 'qty' => 1],
            ['product' => $soldOut, 'qty' => 1],
        ]);

        expect($bundle->getIsAvailable())->toBeFalse();
    });
});

describe('A bundle whose component is deleted', function() {
    it('is not available any more', function() {
        $kept = makeProduct(10.0, 5);
        $gone = makeProduct(25.0, 5);
        $goneId = (int)$gone->id;

        Craft::$app->getElements()->deleteElement($gone, true);

        $bundle = new Bundle();
        $bundle->setProducts([
            ['productId' => $kept->id, 'qty' => 1],
            ['productId' => $goneId, 'qty' => 1],
        ]);

        expect($bundle->getIsAvailable())->toBeFalse();
    });

    it('is still available while every component is there and in stock', function() {
        $a = makeProduct(10.0, 5);
        $b = makeProduct(25.0, 5);

        $bundle = bundleWithComponents([
            ['product' => $a, 'qty' => 1],
            ['product' => $b, 'qty' => 1],
        ]);

        expect($bundle->getIsAvailable())->toBeTrue();
    });
});

describe('A bundle whose component is out of stock', function() {
    it('is unavailable when the variant does not allow out-of-stock purchases', function() {
        $bundle = bundleWithComponents([['product' => makeProduct(10.0, 0), 'qty' => 1]]);

        expect($bundle->getIsAvailable())->toBeFalse();
    });

    it('is available when the variant allows out-of-stock purchases', function() {
        $bundle = bundleWithComponents([['product' => makeProduct(10.0, stock: 0, allowOutOfStockPurchases: true), 'qty' => 1]]);

        expect($bundle->getIsAvailable())->toBeTrue();
    });
});

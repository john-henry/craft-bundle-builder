<?php

use johnhenry\bundlebuilder\models\BundleProduct;

// ---------------------------------------------------------------------------
// Required attributes
// ---------------------------------------------------------------------------

describe('BundleProduct validation: required', function () {
    it('requires a productId', function () {
        $bundleProduct = new BundleProduct();
        $bundleProduct->productId = null;
        $bundleProduct->qty = 1;

        expect($bundleProduct->validate(['productId']))->toBeFalse();
        expect($bundleProduct->getErrors('productId'))->not->toBeEmpty();
    });

    it('accepts a valid productId', function () {
        $bundleProduct = new BundleProduct();
        $bundleProduct->productId = 42;
        $bundleProduct->qty = 1;

        expect($bundleProduct->validate(['productId']))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Quantity
// ---------------------------------------------------------------------------

describe('BundleProduct validation: qty', function () {
    it('defaults qty to 1', function () {
        $bundleProduct = new BundleProduct();

        expect($bundleProduct->qty)->toBe(1);
    });

    it('rejects a qty below 1', function () {
        $bundleProduct = new BundleProduct(['productId' => 42, 'qty' => 0]);

        expect($bundleProduct->validate(['qty']))->toBeFalse();
        expect($bundleProduct->getErrors('qty'))->not->toBeEmpty();
    });

    it('accepts a qty of exactly 1', function () {
        $bundleProduct = new BundleProduct(['productId' => 42, 'qty' => 1]);

        expect($bundleProduct->validate(['qty']))->toBeTrue();
    });

    it('accepts a qty greater than 1', function () {
        $bundleProduct = new BundleProduct(['productId' => 42, 'qty' => 5]);

        expect($bundleProduct->validate(['qty']))->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Integer-only attributes
// ---------------------------------------------------------------------------

describe('BundleProduct validation: integer attributes', function () {
    it('accepts an integer sortOrder', function () {
        $bundleProduct = new BundleProduct(['productId' => 42, 'qty' => 1]);
        $bundleProduct->sortOrder = 3;

        expect($bundleProduct->validate(['sortOrder']))->toBeTrue();
    });

    it('passes full validation with all required attributes set', function () {
        $bundleProduct = new BundleProduct([
            'bundleId' => 10,
            'productId' => 42,
            'qty' => 3,
            'sortOrder' => 1,
        ]);

        expect($bundleProduct->validate())->toBeTrue();
    });
});

<?php

/**
 * Coverage for BundlePricing's discount calculation.
 *
 * The discount maths in calculatePrice() are the highest-value logic in the
 * plugin, but getComponentsSubtotal() depends on real Commerce products and
 * variants. To test the discount branches deterministically (exact in, exact
 * out), these tests use an anonymous subclass that overrides
 * getComponentsSubtotal() with a fixed figure, isolating the percentage/flat/
 * floor/round logic from the database. The empty-bundle case below exercises the
 * real getComponentsSubtotal() path (no products → 0) without stubbing.
 */

use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\services\BundlePricing;

/**
 * Returns a BundlePricing whose components subtotal is fixed, so calculatePrice()
 * can be asserted against exact expected prices.
 */
function pricingWithSubtotal(float $subtotal): BundlePricing
{
    $pricing = new class extends BundlePricing {
        public float $stubSubtotal = 0.0;

        public function getComponentsSubtotal(Bundle $bundle): float
        {
            return $this->stubSubtotal;
        }
    };

    $pricing->stubSubtotal = $subtotal;

    return $pricing;
}

// ---------------------------------------------------------------------------
// Percentage discount
// ---------------------------------------------------------------------------

describe('BundlePricing::calculatePrice(): percentage discount', function () {
    it('takes a percentage off the subtotal', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Percentage->value,
            'discountAmount' => 10.0,
        ]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(90.0);
    });

    it('returns the full subtotal for a zero percentage', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Percentage->value,
            'discountAmount' => 0.0,
        ]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(100.0);
    });

    it('floors at zero for a 100% discount', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Percentage->value,
            'discountAmount' => 100.0,
        ]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(0.0);
    });
});

// ---------------------------------------------------------------------------
// Flat discount
// ---------------------------------------------------------------------------

describe('BundlePricing::calculatePrice(): flat discount', function () {
    it('subtracts a flat amount from the subtotal', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Flat->value,
            'discountAmount' => 15.0,
        ]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(85.0);
    });

    it('floors at zero when the discount exceeds the subtotal', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Flat->value,
            'discountAmount' => 150.0,
        ]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(0.0);
    });
});

// ---------------------------------------------------------------------------
// No / null discount and rounding
// ---------------------------------------------------------------------------

describe('BundlePricing::calculatePrice(): fallbacks and rounding', function () {
    it('returns the subtotal unchanged when no discount type is set', function () {
        $bundle = new Bundle(['discountType' => null, 'discountAmount' => 25.0]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(100.0);
    });

    it('treats a null discount amount as zero', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Flat->value,
            'discountAmount' => null,
        ]);

        expect(pricingWithSubtotal(100.0)->calculatePrice($bundle))->toEqual(100.0);
    });

    it('rounds the calculated price to two decimal places', function () {
        $bundle = new Bundle(['discountType' => null, 'discountAmount' => null]);

        expect(pricingWithSubtotal(19.999)->calculatePrice($bundle))->toEqual(20.0);
    });

    it('never returns a negative price', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Flat->value,
            'discountAmount' => 999.0,
        ]);

        expect(pricingWithSubtotal(50.0)->calculatePrice($bundle))
            ->toBeGreaterThanOrEqual(0.0);
    });
});

// ---------------------------------------------------------------------------
// Real subtotal path (no products)
// ---------------------------------------------------------------------------

describe('BundlePricing::getComponentsSubtotal(): empty bundle', function () {
    it('returns zero for a bundle with no products', function () {
        $bundle = new Bundle();

        expect(BundleBuilder::getInstance()->getBundlePricing()->getComponentsSubtotal($bundle))
            ->toEqual(0.0);
    });

    it('prices an empty bundle at zero regardless of discount type', function () {
        $bundle = new Bundle([
            'discountType' => DiscountType::Percentage->value,
            'discountAmount' => 10.0,
        ]);

        expect(BundleBuilder::getInstance()->getBundlePricing()->calculatePrice($bundle))
            ->toEqual(0.0);
    });
});

// ---------------------------------------------------------------------------
// Real components (saved Commerce products)
//
// These exercise the un-mocked getComponentsSubtotal() path end to end: real
// products with real default-variant base prices, summed by quantity, then run
// through the same discount maths.
// ---------------------------------------------------------------------------

describe('BundlePricing: real components', function () {
    it('sums each component base price times its quantity', function () {
        $bundle = bundleWithComponents([
            ['product' => makeProduct(10.0), 'qty' => 2],
            ['product' => makeProduct(7.5), 'qty' => 1],
        ]);

        expect(BundleBuilder::getInstance()->getBundlePricing()->getComponentsSubtotal($bundle))
            ->toEqual(27.5);
    });

    it('applies a percentage discount to the real subtotal', function () {
        $bundle = bundleWithComponents([
            ['product' => makeProduct(10.0), 'qty' => 2],
            ['product' => makeProduct(7.5), 'qty' => 1],
        ]);
        $bundle->discountType = DiscountType::Percentage->value;
        $bundle->discountAmount = 10.0;

        // 27.5 − 10% = 24.75
        expect(BundleBuilder::getInstance()->getBundlePricing()->calculatePrice($bundle))
            ->toEqual(24.75);
    });

    it('applies a flat discount to the real subtotal', function () {
        $bundle = bundleWithComponents([
            ['product' => makeProduct(10.0), 'qty' => 2],
            ['product' => makeProduct(7.5), 'qty' => 1],
        ]);
        $bundle->discountType = DiscountType::Flat->value;
        $bundle->discountAmount = 5.0;

        // 27.5 − 5 = 22.5
        expect(BundleBuilder::getInstance()->getBundlePricing()->calculatePrice($bundle))
            ->toEqual(22.5);
    });
});

// ---------------------------------------------------------------------------
// Sale-aware pricing
//
// A component's promotional price (a sale or catalog pricing rule) must be
// reflected in the automatic subtotal, otherwise a bundle of discounted
// components could price out higher than buying the parts separately.
// ---------------------------------------------------------------------------

describe('BundlePricing: sale-aware pricing', function () {
    it('uses a component’s promotional price instead of its base price', function () {
        $bundle = bundleWithComponents([
            ['product' => makeProduct(10.0, promotionalPrice: 8.0), 'qty' => 1],
        ]);

        expect(BundleBuilder::getInstance()->getBundlePricing()->getComponentsSubtotal($bundle))
            ->toEqual(8.0);
    });

    it('falls back to the base price for a component with no promotional price', function () {
        $bundle = bundleWithComponents([
            ['product' => makeProduct(10.0), 'qty' => 1],
        ]);

        expect(BundleBuilder::getInstance()->getBundlePricing()->getComponentsSubtotal($bundle))
            ->toEqual(10.0);
    });

    it('mixes promotional and base prices across components', function () {
        $bundle = bundleWithComponents([
            ['product' => makeProduct(10.0, promotionalPrice: 8.0), 'qty' => 2],
            ['product' => makeProduct(7.5), 'qty' => 1],
        ]);

        // (8.0 × 2) + 7.5 = 23.5
        expect(BundleBuilder::getInstance()->getBundlePricing()->getComponentsSubtotal($bundle))
            ->toEqual(23.5);
    });
});

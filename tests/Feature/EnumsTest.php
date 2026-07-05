<?php

use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\enums\TaxTreatment;

// ---------------------------------------------------------------------------
// DiscountType
// ---------------------------------------------------------------------------

describe('DiscountType backing values', function () {
    it('exposes the expected string values', function () {
        expect(DiscountType::Percentage->value)->toBe('percentage');
        expect(DiscountType::Flat->value)->toBe('flat');
    });

    it('resolves a case from its backing value', function () {
        expect(DiscountType::from('percentage'))->toBe(DiscountType::Percentage);
        expect(DiscountType::from('flat'))->toBe(DiscountType::Flat);
    });

    it('has exactly two cases', function () {
        expect(DiscountType::cases())->toHaveCount(2);
    });
});

// ---------------------------------------------------------------------------
// PricingStrategy
// ---------------------------------------------------------------------------

describe('PricingStrategy backing values', function () {
    it('exposes the expected string values', function () {
        expect(PricingStrategy::Fixed->value)->toBe('fixed');
        expect(PricingStrategy::Automatic->value)->toBe('automatic');
    });

    it('resolves a case from its backing value', function () {
        expect(PricingStrategy::from('fixed'))->toBe(PricingStrategy::Fixed);
        expect(PricingStrategy::from('automatic'))->toBe(PricingStrategy::Automatic);
    });

    it('has exactly two cases', function () {
        expect(PricingStrategy::cases())->toHaveCount(2);
    });
});

// ---------------------------------------------------------------------------
// TaxTreatment
// ---------------------------------------------------------------------------

describe('TaxTreatment backing values', function () {
    it('exposes the expected string values', function () {
        expect(TaxTreatment::Composite->value)->toBe('composite');
        expect(TaxTreatment::Multiple->value)->toBe('multiple');
    });

    it('resolves a case from its backing value', function () {
        expect(TaxTreatment::from('composite'))->toBe(TaxTreatment::Composite);
        expect(TaxTreatment::from('multiple'))->toBe(TaxTreatment::Multiple);
    });

    it('has exactly two cases', function () {
        expect(TaxTreatment::cases())->toHaveCount(2);
    });
});

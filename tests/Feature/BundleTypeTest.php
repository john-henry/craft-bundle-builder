<?php

use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\models\BundleType;

// ---------------------------------------------------------------------------
// Defaults
// ---------------------------------------------------------------------------

describe('BundleType defaults', function () {
    it('defaults the tax treatment to composite', function () {
        $bundleType = new BundleType();

        expect($bundleType->taxTreatment)->toBe(TaxTreatment::Composite->value);
    });

    it('shows the slug field by default', function () {
        $bundleType = new BundleType();

        expect($bundleType->showSlugField)->toBeTrue();
    });

    it('casts to its name', function () {
        $bundleType = new BundleType(['name' => 'Gift Set']);

        expect((string)$bundleType)->toBe('Gift Set');
    });
});

// ---------------------------------------------------------------------------
// Tax treatment range
//
// Validating only the taxTreatment attribute runs the `in` rule in isolation;
// the name/handle UniqueValidator (which needs the database) is not triggered,
// so this stays a pure Feature test.
// ---------------------------------------------------------------------------

describe('BundleType validation: taxTreatment', function () {
    it('accepts the composite treatment', function () {
        $bundleType = new BundleType(['taxTreatment' => TaxTreatment::Composite->value]);

        expect($bundleType->validate(['taxTreatment']))->toBeTrue();
    });

    it('accepts the multiple treatment', function () {
        $bundleType = new BundleType(['taxTreatment' => TaxTreatment::Multiple->value]);

        expect($bundleType->validate(['taxTreatment']))->toBeTrue();
    });

    it('rejects an unknown treatment', function () {
        $bundleType = new BundleType(['taxTreatment' => 'somethingElse']);

        expect($bundleType->validate(['taxTreatment']))->toBeFalse();
        expect($bundleType->getErrors('taxTreatment'))->not->toBeEmpty();
    });
});

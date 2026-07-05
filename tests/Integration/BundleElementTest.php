<?php

/**
 * Coverage for the Bundle element's tax-category resolution.
 *
 * A multiple-supply bundle has to report the zero-rate "apportioned" tax
 * category so Commerce's own tax adjuster leaves the line alone and this
 * plugin's adjuster can split the tax across components. A composite-supply
 * bundle reports its own category and is taxed the normal way. If that swap
 * ever regressed, multiple-supply bundles would be taxed twice, so it's worth
 * pinning down.
 */

use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\migrations\Install;

describe('Bundle::getTaxCategoryId()', function () {
    it('returns the apportioned zero-rate category for a multiple-supply bundle', function () {
        $bundleType = makeBundleType('Multi', 'multi' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $apportioned = Commerce::getInstance()->getTaxCategories()
            ->getTaxCategoryByHandle(Install::APPORTIONED_TAX_CATEGORY_HANDLE);

        expect($apportioned)->not->toBeNull();
        expect($bundle->getTaxCategoryId())->toBe($apportioned->id);
    });

    it('reports its own category for a composite-supply bundle', function () {
        $bundleType = makeBundleType('Comp', 'comp' . StringHelper::randomString(6), TaxTreatment::Composite->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $apportioned = Commerce::getInstance()->getTaxCategories()
            ->getTaxCategoryByHandle(Install::APPORTIONED_TAX_CATEGORY_HANDLE);

        expect($bundle->getTaxCategoryId())->not->toBe($apportioned->id);
    });
});

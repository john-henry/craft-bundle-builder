<?php

/**
 * Coverage for passing bundle query params as a criteria array.
 *
 * `craft.bundleBuilder.bundles()` and `getBundlesForProduct()` apply criteria
 * with `Craft::configure()`, which sets each key as a property. `type` and
 * `taxCategory` exist only as param methods, so the query has to route them
 * to those methods or the assignment throws.
 */

use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\variables\BundleBuilderVariable;

describe('Bundle query criteria arrays', function () {
    it('accepts a bundle type handle as the type criterion', function () {
        $type = makeBundleType('Criteria', 'criteria' . StringHelper::randomString(6));

        $query = (new BundleBuilderVariable())->bundles(['type' => $type->handle, 'limit' => 4]);

        expect($query->typeId)->toBe([$type->id])
            ->and($query->limit)->toBe(4);
    });

    it('matches nothing for an unknown bundle type handle', function () {
        $query = (new BundleBuilderVariable())->bundles(['type' => 'noSuchType' . StringHelper::randomString(6)]);

        expect($query->typeId)->toBe(0);
    });

    it('accepts a tax category as the taxCategory criterion', function () {
        $taxCategory = Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory();

        $query = (new BundleBuilderVariable())->bundles(['taxCategory' => $taxCategory]);

        expect($query->taxCategoryId)->toBe([$taxCategory->id]);
    });

    it('filters a product’s bundles by type', function () {
        $type = makeBundleType('Criteria', 'criteria' . StringHelper::randomString(6));
        $otherType = makeBundleType('Other', 'other' . StringHelper::randomString(6));
        $component = makeProduct(10.0);
        $bundle = makeSavedBundle($type, [['product' => $component, 'qty' => 1]]);
        $variable = new BundleBuilderVariable();

        $matching = $variable->getBundlesForProduct($component, ['type' => $type->handle, 'status' => null]);
        $other = $variable->getBundlesForProduct($component, ['type' => $otherType->handle, 'status' => null]);

        expect(array_map(static fn($found) => $found->id, $matching))->toBe([$bundle->id])
            ->and($other)->toBe([]);
    });
});

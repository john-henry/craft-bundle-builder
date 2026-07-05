<?php

/**
 * Coverage for the BundleTypes service: persistence, lookup, memoization,
 * deletion, and the database-backed validation rules (required name/handle and
 * the unique handle constraint) that can't run in a Feature test.
 */

use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\models\BundleType;

// ---------------------------------------------------------------------------
// Persistence and lookup
// ---------------------------------------------------------------------------

describe('BundleTypes::saveBundleType()', function () {
    it('assigns an id and uid on first save', function () {
        $type = makeBundleType('Starter Kit', 'starterKit');

        expect($type->id)->not->toBeNull();
        expect($type->uid)->not->toBeNull();
    });

    it('persists a field layout', function () {
        $type = makeBundleType('Starter Kit', 'starterKit');

        expect($type->fieldLayoutId)->not->toBeNull();
    });

    it('stores the chosen tax treatment', function () {
        $type = makeBundleType('VAT Mix', 'vatMix', TaxTreatment::Multiple->value);

        $loaded = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($type->id);

        expect($loaded?->taxTreatment)->toBe(TaxTreatment::Multiple->value);
    });
});

describe('BundleTypes lookups', function () {
    it('finds a saved type by id', function () {
        $type = makeBundleType('Starter Kit', 'starterKit');

        $loaded = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($type->id);

        expect($loaded)->not->toBeNull();
        expect($loaded?->handle)->toBe('starterKit');
    });

    it('finds a saved type by handle', function () {
        makeBundleType('Starter Kit', 'starterKit');

        $loaded = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeByHandle('starterKit');

        expect($loaded)->not->toBeNull();
        expect($loaded?->name)->toBe('Starter Kit');
    });

    it('returns null for an unknown id', function () {
        expect(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById(PHP_INT_MAX))
            ->toBeNull();
    });

    it('returns null for an unknown handle', function () {
        expect(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeByHandle('nopeNotHere'))
            ->toBeNull();
    });

    it('includes the saved type in getAllBundleTypes()', function () {
        $type = makeBundleType('Starter Kit', 'starterKit');

        $all = BundleBuilder::getInstance()->getBundleTypes()->getAllBundleTypes();

        expect($all)->toHaveKey($type->id);
    });
});

// ---------------------------------------------------------------------------
// Deletion
// ---------------------------------------------------------------------------

describe('BundleTypes::deleteBundleTypeById()', function () {
    it('removes the type', function () {
        $type = makeBundleType('Starter Kit', 'starterKit');

        $deleted = BundleBuilder::getInstance()->getBundleTypes()->deleteBundleTypeById($type->id);
        resetBundleTypesCache();

        expect($deleted)->toBeTrue();
        expect(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($type->id))
            ->toBeNull();
    });

    it('returns false when the type does not exist', function () {
        expect(BundleBuilder::getInstance()->getBundleTypes()->deleteBundleTypeById(PHP_INT_MAX))
            ->toBeFalse();
    });
});

// ---------------------------------------------------------------------------
// Database-backed validation
// ---------------------------------------------------------------------------

describe('BundleType validation: database-backed rules', function () {
    it('requires a name', function () {
        $type = new BundleType();
        $type->name = null;
        $type->handle = 'starterKit';

        expect($type->validate(['name']))->toBeFalse();
        expect($type->getErrors('name'))->not->toBeEmpty();
    });

    it('requires a handle', function () {
        $type = new BundleType();
        $type->name = 'Starter Kit';
        $type->handle = null;

        expect($type->validate(['handle']))->toBeFalse();
        expect($type->getErrors('handle'))->not->toBeEmpty();
    });

    it('rejects a duplicate handle', function () {
        makeBundleType('Starter Kit', 'starterKit');

        $duplicate = new BundleType();
        $duplicate->name = 'Another Kit';
        $duplicate->handle = 'starterKit';

        expect($duplicate->validate(['handle']))->toBeFalse();
        expect($duplicate->getErrors('handle'))->not->toBeEmpty();
    });

    it('rejects a duplicate name', function () {
        makeBundleType('Starter Kit', 'starterKit');

        $duplicate = new BundleType();
        $duplicate->name = 'Starter Kit';
        $duplicate->handle = 'anotherKit';

        expect($duplicate->validate(['name']))->toBeFalse();
        expect($duplicate->getErrors('name'))->not->toBeEmpty();
    });
});

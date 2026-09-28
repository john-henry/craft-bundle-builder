<?php

/**
 * The Bundles relation field in GraphQL: it's in a schema only when the
 * schema can read a bundle type, and returns only bundles of the types it can
 * read, eager-loaded or not.
 */

use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\models\GqlSchema;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\fields\Bundles;
use johnhenry\bundlebuilder\helpers\Gql as GqlHelper;

it('is left out of schemas that can’t read any bundle type', function () {
    makeBundleType();

    expect((new Bundles())->includeInGqlSchema(new GqlSchema(['scope' => []])))->toBeFalse();
});

it('is included in schemas that can read a bundle type', function () {
    $type = makeBundleType();
    $schema = new GqlSchema(['scope' => [GqlHelper::SCOPE_BUNDLE_TYPES . '.' . $type->uid . ':read']]);

    expect((new Bundles())->includeInGqlSchema($schema))->toBeTrue();
});

describe('in a query', function () {
    beforeEach(function () {
        // Saving a field writes project config, which the rollback doesn't
        // reach on disk.
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;

        $this->handle = 'relatedBundles' . StringHelper::randomString(6);
        $field = new Bundles(['name' => 'Related bundles', 'handle' => $this->handle]);
        expect(Craft::$app->getFields()->saveField($field))->toBeTrue();

        $this->type = makeBundleType();
        $layout = FieldLayout::createFromConfig([
            'tabs' => [[
                'name' => 'Content',
                'elements' => [['type' => CustomField::class, 'fieldUid' => $field->uid]],
            ]],
        ]);
        $layout->type = Bundle::class;
        $this->type->setFieldLayout($layout);
        expect(BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($this->type))->toBeTrue();
        resetBundleTypesCache();
    });

    afterEach(function () {
        Craft::$app->getProjectConfig()->writeYamlAutomatically = true;
        Craft::$app->getGql()->flushCaches();
    });

    it('returns only related bundles of the types the schema can read', function () {
        $product = makeProduct();
        $hiddenType = makeBundleType('Hidden Kit', 'hiddenKit');
        $readable = makeSavedBundle($this->type, [['product' => $product, 'qty' => 1]]);
        $hidden = makeSavedBundle($hiddenType, [['product' => $product, 'qty' => 1]]);

        $owner = makeSavedBundle($this->type, [['product' => $product, 'qty' => 1]]);
        $owner->setFieldValue($this->handle, [$readable->id, $hidden->id]);
        withFileCacheWarningsSuppressed(static fn() => Craft::$app->getElements()->saveElement($owner, false));

        $result = runBundleGql(bundleGqlSchema([$this->type]), <<<GQL
            {
              bundles(id: {$owner->id}) { ... on starterKit_Bundle { {$this->handle} { id } } }
              bundle(id: {$owner->id}) { ... on starterKit_Bundle { {$this->handle} { id } } }
            }
            GQL);

        $expected = [$this->handle => [['id' => (string)$readable->id]]];

        expect($result)->not->toHaveKey('errors')
            ->and($result['data'])->toBe(['bundles' => [$expected], 'bundle' => $expected]);
    });
});

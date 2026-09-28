<?php

/**
 * Bundles over GraphQL: the queries, the per-bundle-type GraphQL types, the
 * components, and the read scope each bundle type gets in a schema.
 *
 * Queries run in-process against unsaved schemas, through runBundleGql().
 */

use johnhenry\bundlebuilder\enums\PricingStrategy;

afterEach(function () {
    Craft::$app->getGql()->flushCaches();
});

it('queries bundles with their components', function () {
    $type = makeBundleType();
    $product = makeProduct(12.0);
    $extra = addVariant($product, 15.0);
    $bundle = makeSavedBundle($type, [['product' => $product, 'qty' => 2]], PricingStrategy::Automatic->value);
    $defaultSku = $product->getDefaultVariant()->sku;

    $result = runBundleGql(bundleGqlSchema([$type]), <<<GQL
        {
          bundles(id: {$bundle->id}) {
            title
            sku
            price
            components { qty product { title } variants { sku } defaultVariant { sku } }
          }
        }
        GQL);

    expect($result)->not->toHaveKey('errors')
        ->and($result['data']['bundles'])->toBe([[
            'title' => $bundle->title,
            'sku' => $bundle->sku,
            'price' => 24.0,
            'components' => [[
                'qty' => 2,
                'product' => ['title' => $product->title],
                'variants' => [['sku' => $defaultSku], ['sku' => $extra->sku]],
                'defaultVariant' => ['sku' => $defaultSku],
            ]],
        ]]);
});

it('counts the bundles a query matches', function () {
    $type = makeBundleType();
    $product = makeProduct();
    makeSavedBundle($type, [['product' => $product, 'qty' => 1]]);
    makeSavedBundle($type, [['product' => $product, 'qty' => 1]]);

    $result = runBundleGql(bundleGqlSchema([$type]), '{ bundleCount(type: "starterKit") }');

    expect($result['data']['bundleCount'] ?? null)->toBe(2);
});

it('resolves each bundle to its bundle type’s GraphQL type', function () {
    $type = makeBundleType();
    $bundle = makeSavedBundle($type, [['product' => makeProduct(), 'qty' => 1]]);

    $result = runBundleGql(bundleGqlSchema([$type]), <<<GQL
        {
          bundle(id: {$bundle->id}) {
            __typename
            ... on starterKit_Bundle { bundleTypeHandle bundleTypeId }
          }
        }
        GQL);

    expect($result['data']['bundle'] ?? null)->toBe([
        '__typename' => 'starterKit_Bundle',
        'bundleTypeHandle' => 'starterKit',
        'bundleTypeId' => $type->id,
    ]);
});

it('only returns bundles of the bundle types the schema can read', function () {
    $readable = makeBundleType();
    $hidden = makeBundleType('Hidden Kit', 'hiddenKit');
    $product = makeProduct();
    $readableBundle = makeSavedBundle($readable, [['product' => $product, 'qty' => 1]]);
    $hiddenBundle = makeSavedBundle($hidden, [['product' => $product, 'qty' => 1]]);

    $result = runBundleGql(bundleGqlSchema([$readable]), <<<GQL
        {
          bundles(id: [{$readableBundle->id}, {$hiddenBundle->id}]) { id }
          hidden: bundle(id: {$hiddenBundle->id}) { id }
        }
        GQL);

    expect($result['data'])->toBe([
        'bundles' => [['id' => (string)$readableBundle->id]],
        'hidden' => null,
    ]);
});

it('has no bundle queries for a schema without a bundle type scope', function () {
    makeSavedBundle(makeBundleType(), [['product' => makeProduct(), 'qty' => 1]]);

    $result = runBundleGql(bundleGqlSchema([]), '{ bundles { id } }');

    expect($result['errors'][0]['message'] ?? '')->toContain('Cannot query field "bundles"');
});

it('leaves out component products the schema can’t read', function () {
    $type = makeBundleType();
    $bundle = makeSavedBundle($type, [['product' => makeProduct(), 'qty' => 3]]);

    $result = runBundleGql(bundleGqlSchema([$type], withProductTypes: false), <<<GQL
        {
          bundle(id: {$bundle->id}) {
            components { qty product { id } variants { id } defaultVariant { id } }
          }
        }
        GQL);

    expect($result['data']['bundle']['components'] ?? null)->toBe([[
        'qty' => 3,
        'product' => null,
        'variants' => [],
        'defaultVariant' => null,
    ]]);
});

it('filters bundles by several bundle type handles', function () {
    $kits = makeBundleType();
    $hampers = makeBundleType('Hamper Kits', 'hamperKits');
    $product = makeProduct();
    makeSavedBundle($kits, [['product' => $product, 'qty' => 1]]);
    makeSavedBundle($hampers, [['product' => $product, 'qty' => 1]]);

    $result = runBundleGql(bundleGqlSchema([$kits, $hampers]), <<<'GQL'
        {
          both: bundleCount(type: ["starterKit", "hamperKits"])
          unknown: bundleCount(type: ["nope"])
        }
        GQL);

    expect($result['data'] ?? null)->toBe(['both' => 2, 'unknown' => 0]);
});

<?php

/**
 * Pins bundle revisions: opt-in per bundle type, as for Commerce product types,
 * holding the components they had, restorable by reverting, and not created by
 * automatic price recalculation.
 */

use craft\elements\User;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleProduct;
use johnhenry\bundlebuilder\models\BundleType;

/**
 * Saves a bundle type with versioning switched on or off.
 */
function versionedBundleType(bool $enableVersioning): BundleType
{
    $bundleType = makeBundleType('Versioned', 'versioned' . StringHelper::randomString(6));
    $bundleType->enableVersioning = $enableVersioning;
    BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($bundleType);
    resetBundleTypesCache();

    return BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($bundleType->id);
}

/**
 * Returns a bundle's revisions, newest first.
 *
 * @return Bundle[]
 */
function bundleRevisions(Bundle $bundle): array
{
    return Bundle::find()->revisionOf($bundle->id)->status(null)->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->all();
}

/**
 * Returns a bundle's components as [productId, qty] pairs.
 *
 * @return array<int, array{0: int|null, 1: int}>
 */
function componentPairs(Bundle $bundle): array
{
    return array_map(static fn(BundleProduct $product): array => [$product->productId, $product->qty], $bundle->getProducts());
}

describe('Bundle revisions', function () {
    it('are off by default, as for product types', function () {
        $bundle = makeSavedBundle(versionedBundleType(false), [['product' => makeProduct(10.0), 'qty' => 1]]);

        expect($bundle->hasRevisions())->toBeFalse()
            ->and(bundleRevisions($bundle))->toBe([]);
    });

    it('are saved with the bundle’s components when the type enables versioning', function () {
        $product = makeProduct(10.0);
        $bundle = makeSavedBundle(versionedBundleType(true), [['product' => $product, 'qty' => 2]]);
        $revisions = bundleRevisions($bundle);

        expect($bundle->hasRevisions())->toBeTrue()
            ->and($revisions)->toHaveCount(1)
            ->and(componentPairs($revisions[0]))->toBe([[(int)$product->id, 2]]);
    });

    it('restore the components and keep the SKU when reverted to', function () {
        $first = makeProduct(10.0);
        $second = makeProduct(20.0);
        $bundle = makeSavedBundle(versionedBundleType(true), [['product' => $first, 'qty' => 1]]);
        $sku = $bundle->getSku();
        $original = bundleRevisions($bundle)[0];

        $bundle->setProducts([['productId' => $second->id, 'qty' => 3]]);
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($bundle, false));

        $userId = (int)User::find()->status(null)->one()->id;
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getRevisions()->revertToRevision($original, $userId));

        $live = Bundle::find()->id($bundle->id)->status(null)->one();

        expect(componentPairs($live))->toBe([[(int)$first->id, 1]])
            ->and($live->getSku())->toBe($sku);
    });

    it('aren’t created by automatic price recalculation', function () {
        $bundle = makeSavedBundle(versionedBundleType(true), [['product' => makeProduct(10.0), 'qty' => 1]]);
        $before = count(bundleRevisions($bundle));

        withFileCacheWarningsSuppressed(fn() => BundleBuilder::getInstance()->getBundlePricing()->recalculateBundle(
            Bundle::find()->id($bundle->id)->status(null)->one(),
        ));

        expect(bundleRevisions($bundle))->toHaveCount($before);
    });
});

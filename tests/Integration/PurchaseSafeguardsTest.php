<?php

/**
 * Coverage for what stops a bundle being bought, edited or shown when it
 * shouldn't be.
 *
 * Drafts and revisions keep a real SKU and their own stored price, and
 * Commerce looks a posted purchasable up with drafts and revisions included,
 * so they must never be available. A bundle's type can't be changed by
 * posting one, which would move it past its type's permission. Components
 * must be real products, and a bundle outside its post and expiry dates has
 * no page.
 */

use craft\commerce\elements\Product;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;

describe('A bundle draft or revision', function () {
    it('is never available to buy', function () {
        $bundle = makeSavedBundle(makeBundleType('Safeguards ' . StringHelper::randomString(6), 'safeguards' . StringHelper::randomString(6)), [
            ['product' => makeProduct(10.0), 'qty' => 1],
        ]);

        expect($bundle->getIsAvailable())->toBeTrue();

        $revisionId = Craft::$app->getRevisions()->createRevision($bundle);
        $revision = Bundle::find()->id($revisionId)->revisions()->status(null)->one();
        expect($revision)->not->toBeNull();
        expect($revision->getIsAvailable())->toBeFalse();

        $draft = Craft::$app->getDrafts()->createDraft($bundle, provisional: true);
        expect($draft->getIsAvailable())->toBeFalse();
    });
});

describe('A bundle’s type', function () {
    it('can’t be changed by posted attributes', function () {
        $first = makeBundleType('Type A ' . StringHelper::randomString(6), 'typeA' . StringHelper::randomString(6));
        $second = makeBundleType('Type B ' . StringHelper::randomString(6), 'typeB' . StringHelper::randomString(6));
        $bundle = makeSavedBundle($first, [['product' => makeProduct(10.0), 'qty' => 1]]);

        $bundle->setAttributesFromRequest(['typeId' => $second->id, 'title' => 'Renamed']);

        expect($bundle->typeId)->toBe($first->id);
        expect($bundle->title)->toBe('Renamed');
    });

    it('is looked up again when typeId changes', function () {
        $first = makeBundleType('Type C ' . StringHelper::randomString(6), 'typeC' . StringHelper::randomString(6));
        $second = makeBundleType('Type D ' . StringHelper::randomString(6), 'typeD' . StringHelper::randomString(6));
        $bundle = makeSavedBundle($first, [['product' => makeProduct(10.0), 'qty' => 1]]);

        expect($bundle->getType()->id)->toBe($first->id);

        $bundle->typeId = $second->id;

        expect($bundle->getType()->id)->toBe($second->id);
    });
});

describe('A bundle component', function () {
    it('can’t be a product’s draft', function () {
        $product = makeProduct(10.0);
        $draft = Craft::$app->getDrafts()->createDraft(Product::find()->id($product->id)->one());

        $bundle = new Bundle();
        $bundle->setProducts([['productId' => $draft->id, 'qty' => 1]]);
        $bundle->validateProducts('products');

        expect($bundle->getErrors('products'))->toHaveCount(1);
    });
});

describe('A scheduled or expired bundle', function () {
    it('has no page outside its dates', function () {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $type = new BundleType();
        $type->name = 'Dated ' . StringHelper::randomString(6);
        $type->handle = 'dated' . StringHelper::randomString(6);
        $type->setSiteSettings([$siteId => new BundleTypeSite([
            'siteId' => $siteId,
            'hasUrls' => true,
            'uriFormat' => 'bundles/{slug}',
            'template' => 'bundles/_bundle',
        ])]);

        if (!BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type)) {
            throw new RuntimeException('Could not save bundle type: ' . implode(', ', $type->getErrorSummary(true)));
        }

        resetBundleTypesCache();
        $bundle = makeSavedBundle($type, [['product' => makeProduct(10.0), 'qty' => 1]]);

        expect($bundle->getRoute())->not->toBeNull();

        $bundle->postDate = DateTimeHelper::toDateTime('+1 week');
        expect($bundle->getRoute())->toBeNull();

        $bundle->postDate = DateTimeHelper::toDateTime('-1 week');
        $bundle->expiryDate = DateTimeHelper::toDateTime('-1 day');
        expect($bundle->getRoute())->toBeNull();
    });
});

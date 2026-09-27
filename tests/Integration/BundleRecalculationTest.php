<?php

/**
 * Coverage for keeping automatic bundle prices in sync with their components.
 *
 * These exercise the service methods the queued RecalculateBundlePrices job is
 * built on: finding the bundles a product belongs to, and re-saving an
 * automatic bundle so its stored price picks up a component's new price. A
 * product that isn't used in any bundle resolves to no ids, which is what keeps
 * the batched job from touching every bundle.
 */

use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\models\BundleType;

// ---------------------------------------------------------------------------
// getBundleIdsForProduct()
// ---------------------------------------------------------------------------

describe('BundlePricing::getBundleIdsForProduct()', function () {
    it('returns the ids of bundles that use the product', function () {
        $type = makeBundleType('Auto', 'auto' . StringHelper::randomString(6));
        $component = makeProduct(10.0);
        $bundle = makeSavedBundle($type, [['product' => $component, 'qty' => 1]]);

        expect(BundleBuilder::getInstance()->getBundlePricing()->getBundleIdsForProduct($component->id))
            ->toBe([$bundle->id]);
    });

    it('returns nothing for a product used in no bundle', function () {
        $unused = makeProduct(5.0);

        expect(BundleBuilder::getInstance()->getBundlePricing()->getBundleIdsForProduct($unused->id))
            ->toBe([]);
    });
});

// ---------------------------------------------------------------------------
// recalculateBundlesForProduct() / recalculateBundle()
// ---------------------------------------------------------------------------

describe('BundlePricing recalculation', function () {
    it('recomputes an automatic bundle’s stored price when its component price changes', function () {
        $type = makeBundleType('Auto', 'auto' . StringHelper::randomString(6));
        $component = makeProduct(10.0);
        $bundle = makeSavedBundle($type, [['product' => $component, 'qty' => 2]]);

        expect((float)$bundle->getBasePrice())->toEqual(20.0);

        $variant = $component->getDefaultVariant();
        $variant->setBasePrice(12.0);
        withFileCacheWarningsSuppressed(static fn() => Craft::$app->getElements()->saveElement($variant, false));

        BundleBuilder::getInstance()->getBundlePricing()->recalculateBundlesForProduct($component->id);

        $reloaded = Bundle::find()->id($bundle->id)->status(null)->one();
        expect((float)$reloaded->getBasePrice())->toEqual(24.0);
    });

    it('leaves a fixed-price bundle’s stored price alone', function () {
        $type = makeBundleType('Fixed', 'fixed' . StringHelper::randomString(6));
        $component = makeProduct(10.0);
        $bundle = makeSavedBundle($type, [['product' => $component, 'qty' => 1]], PricingStrategy::Fixed->value);

        $bundle->setBasePrice(99.0);
        withFileCacheWarningsSuppressed(static fn() => Craft::$app->getElements()->saveElement($bundle, false));

        BundleBuilder::getInstance()->getBundlePricing()->recalculateBundle($bundle);

        $reloaded = Bundle::find()->id($bundle->id)->status(null)->one();
        expect((float)$reloaded->getBasePrice())->toEqual(99.0);
    });
});

describe('A component variant saved on its own', function () {
    it('recalculates the automatic bundles that use its product', function () {
        $product = makeProduct(10.0);
        $bundle = makeSavedBundle(makeBundleType('Variant Save ' . StringHelper::randomString(6), 'variantSave' . StringHelper::randomString(6)), [
            ['product' => $product, 'qty' => 1],
        ]);
        expect((float)$bundle->getBasePrice())->toBe(10.0);

        $variant = \craft\commerce\Plugin::getInstance()->getProducts()->getProductById($product->id)?->getDefaultVariant();
        $variant->setBasePrice(14.0);
        Craft::$app->getElements()->saveElement($variant, false);
        Craft::$app->getQueue()->run();

        expect((float)Bundle::find()->id($bundle->id)->status(null)->one()->getBasePrice())->toBe(14.0);
    });
});


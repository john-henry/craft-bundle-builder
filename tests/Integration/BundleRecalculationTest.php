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

/**
 * Saves a bundle of the given type with the given component products, at the
 * given pricing strategy. Rolled back with the test transaction.
 *
 * @param array<int, array{product: \craft\commerce\elements\Product, qty: int}> $components
 */
function makeSavedBundle(
    BundleType $type,
    array $components,
    string $pricingStrategy = PricingStrategy::Automatic->value,
): Bundle {
    return withFileCacheWarningsSuppressed(static function () use ($type, $components, $pricingStrategy): Bundle {
        $bundle = new Bundle();
        $bundle->typeId = $type->id;
        $bundle->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle->title = 'Test Bundle ' . StringHelper::randomString(6);
        $bundle->enabled = true;
        $bundle->pricingStrategy = $pricingStrategy;
        $bundle->setProducts(array_map(static fn(array $component): array => [
            'productId' => $component['product']->id,
            'qty' => $component['qty'],
        ], $components));

        if (!Craft::$app->getElements()->saveElement($bundle, false)) {
            throw new RuntimeException('Could not save bundle: ' . implode(', ', $bundle->getErrorSummary(true)));
        }

        return $bundle;
    });
}

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

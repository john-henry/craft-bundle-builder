<?php

/**
 * Coverage for what Bundle Builder tells Container Deposits about a bundle
 * line: the variant chosen for each component and how many one bundle holds.
 *
 * The bundle line's own purchasable has no deposit type, so without these
 * contents the cans and flagons inside a bundle would go out with no deposit
 * charged.
 */

use craft\commerce\models\LineItem;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\containerdeposits\ContainerDeposits;
use johnhenry\containerdeposits\events\DefineLineItemContentsEvent;
use johnhenry\containerdeposits\services\DepositCartService;

/**
 * Builds a bundle line item the way the add-to-cart path does.
 */
function depositsBundleLine(Bundle $bundle, int $qty = 1): LineItem
{
    $lineItem = new LineItem();
    $lineItem->qty = $qty;
    $lineItem->setOptions([]);
    $lineItem->setSnapshot([]);
    $lineItem->setPurchasable($bundle);

    BundleBuilder::getInstance()->getBundleCart()->applyToLineItem($bundle, $lineItem);

    return $lineItem;
}

/**
 * Fires Container Deposits' contents event for a line item and returns what
 * the listeners added, as variant ID => qty.
 *
 * @return array<int, int>
 */
function depositContentsFor(LineItem $lineItem): array
{
    $event = new DefineLineItemContentsEvent(['lineItem' => $lineItem]);
    ContainerDeposits::getInstance()->depositCart->trigger(DepositCartService::EVENT_DEFINE_LINE_ITEM_CONTENTS, $event);

    $contents = [];
    foreach ($event->contents as $content) {
        $contents[(int)$content['purchasable']->id] = $content['qty'];
    }

    return $contents;
}

describe('BundleCart::getComponentVariants()', function () {
    it('returns each chosen variant with how many one bundle holds', function () {
        $cans = makeProduct(3.0);
        $flagon = makeProduct(12.0);
        $bundle = makeSavedBundle(makeBundleType('Crate ' . StringHelper::randomString(6), 'crate' . StringHelper::randomString(6)), [
            ['product' => $cans, 'qty' => 6],
            ['product' => $flagon, 'qty' => 1],
        ]);

        $components = BundleBuilder::getInstance()->getBundleCart()->getComponentVariants(depositsBundleLine($bundle));
        $byVariant = [];
        foreach ($components as $component) {
            $byVariant[(int)$component['variant']->id] = $component['qty'];
        }

        expect($byVariant)->toBe([
            (int)$cans->getDefaultVariant()->id => 6,
            (int)$flagon->getDefaultVariant()->id => 1,
        ]);
    });

    it('follows a change of variant before the snapshot is rebuilt', function () {
        $product = makeProduct(3.0);
        $bottle = addVariant($product, 3.6);
        $bundle = makeSavedBundle(makeBundleType('Swap ' . StringHelper::randomString(6), 'swap' . StringHelper::randomString(6)), [
            ['product' => $product, 'qty' => 2],
        ]);

        $lineItem = depositsBundleLine($bundle);
        $lineItem->setOptions(['bundleProducts' => [$product->id => (string)$bottle->id]]);

        $components = BundleBuilder::getInstance()->getBundleCart()->getComponentVariants($lineItem);

        expect($components)->toHaveCount(1);
        expect((int)$components[0]['variant']->id)->toBe((int)$bottle->id);
        expect($components[0]['qty'])->toBe(2);
    });
});

describe('The Container Deposits contents event', function () {
    beforeEach(function () {
        if (!Craft::$app->getPlugins()->isPluginEnabled('container-deposits')) {
            $this->markTestSkipped('Container Deposits isn’t enabled in the test database.');
        }
    });

    it('lists a bundle line’s chosen variants', function () {
        $cans = makeProduct(3.0);
        $bundle = makeSavedBundle(makeBundleType('Pack ' . StringHelper::randomString(6), 'pack' . StringHelper::randomString(6)), [
            ['product' => $cans, 'qty' => 4],
        ]);

        expect(depositContentsFor(depositsBundleLine($bundle, 3)))->toBe([(int)$cans->getDefaultVariant()->id => 4]);
    });

    it('leaves a line that isn’t a bundle alone', function () {
        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setPurchasable(makeProduct(3.0)->getDefaultVariant());

        expect(depositContentsFor($lineItem))->toBe([]);
    });
});

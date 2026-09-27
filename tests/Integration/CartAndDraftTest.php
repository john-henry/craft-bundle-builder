<?php

/**
 * End-to-end cover for two paths that unit-level tests can't reach.
 *
 * The cart path goes through Commerce's own line item resolution and order
 * save, which refreshes every line from its purchasable and rebuilds the
 * snapshot, so the customer's variant choice has to survive that. The draft
 * path goes through Craft's apply-draft, which duplicates the draft over the
 * canonical bundle and must not leave it with a temporary SKU.
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\helpers\Purchasable as PurchasableHelper;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\PricingStrategy;

describe('A bundle in the cart', function () {
    it('keeps the chosen variant through order saves and reloads', function () {
        $product = makeProduct(10.0);
        $chosen = addVariant($product, 12.0);
        $bundle = makeSavedBundle(makeBundleType('Cart', 'cart' . StringHelper::randomString(6)), [
            ['product' => $product, 'qty' => 1],
        ]);

        $commerce = Commerce::getInstance();
        $options = ['bundleProducts' => [$product->id => (string)$chosen->id]];

        $order = new Order();
        $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
        $order->currency = 'USD';
        $order->addLineItem($commerce->getLineItems()->resolveLineItem($order, $bundle->id, $options));

        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($order, false));

        // Load, save and load again: each save refreshes the line from the bundle.
        $reloaded = $commerce->getOrders()->getOrderById($order->id);
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($reloaded, false));
        $reloaded = $commerce->getOrders()->getOrderById($order->id);

        $lineItem = $reloaded->getLineItems()[0];
        $selections = BundleBuilder::getInstance()->getBundleCart()->getSelections($lineItem);

        expect($selections)->toHaveCount(1)
            ->and($selections[0]['variantId'])->toBe((int)$chosen->id);

        // The same selection added again resolves to the existing line.
        expect($commerce->getLineItems()->resolveLineItem($reloaded, $bundle->id, $options)->id)
            ->toBe($lineItem->id);
    });
});

describe('Applying a bundle draft', function () {
    it('keeps the live bundle’s SKU and availability', function () {
        $bundle = makeSavedBundle(makeBundleType('Drafts', 'drafts' . StringHelper::randomString(6)), [
            ['product' => makeProduct(10.0, 5), 'qty' => 1],
        ]);
        $sku = $bundle->getSku();

        expect(PurchasableHelper::isTempSku($sku))->toBeFalse();

        $userId = (int)User::find()->status(null)->one()->id;

        withFileCacheWarningsSuppressed(function () use ($bundle, $userId) {
            $draft = Craft::$app->getDrafts()->createDraft($bundle, $userId);
            $draft->title = 'Changed title';
            Craft::$app->getElements()->saveElement($draft, false);
            Craft::$app->getDrafts()->applyDraft($draft);
        });

        $live = Bundle::find()->id($bundle->id)->status(null)->one();
        $storedSku = (new Query())
            ->select('sku')
            ->from('{{%commerce_purchasables}}')
            ->where(['id' => $bundle->id])
            ->scalar();

        expect($live->title)->toBe('Changed title')
            ->and($live->getSku())->toBe($sku)
            ->and($storedSku)->toBe($sku)
            ->and($live->getIsAvailable())->toBeTrue();
    });
});

describe('Applying a bundle draft that changes the components', function () {
    it('puts the draft’s components on a fixed-price live bundle', function () {
        $first = makeProduct(10.0);
        $second = makeProduct(20.0);
        $bundle = makeSavedBundle(
            makeBundleType('DraftComponents', 'draftComponents' . StringHelper::randomString(6)),
            [['product' => $first, 'qty' => 1]],
            PricingStrategy::Fixed->value,
        );
        $userId = (int)User::find()->status(null)->one()->id;

        withFileCacheWarningsSuppressed(function () use ($bundle, $second, $userId) {
            $draft = Craft::$app->getDrafts()->createDraft($bundle, $userId);
            $draft->setProducts([['productId' => $second->id, 'qty' => 2]]);
            Craft::$app->getElements()->saveElement($draft, false);

            // A fresh copy of the draft, as the CP works with, so nothing is preloaded.
            $fresh = Bundle::find()->draftId($draft->draftId)->status(null)->one();
            Craft::$app->getDrafts()->applyDraft($fresh);
        });

        $live = Bundle::find()->id($bundle->id)->status(null)->one();
        $components = array_map(
            static fn($product): array => [$product->productId, $product->qty],
            $live->getProducts(),
        );

        expect($components)->toBe([[(int)$second->id, 2]]);
    });
});

describe('A bundle with a hand-entered SKU', function () {
    it('keeps it through creating and applying a draft', function () {
        $bundle = makeSavedBundle(makeBundleType('ManualSku', 'manualSku' . StringHelper::randomString(6)), [
            ['product' => makeProduct(10.0, 5), 'qty' => 1],
        ]);
        $bundle->setSku('MANUAL-' . StringHelper::randomString(6));
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($bundle, false));
        $sku = $bundle->getSku();
        $userId = (int)User::find()->status(null)->one()->id;

        withFileCacheWarningsSuppressed(function () use ($bundle, $userId) {
            $draft = Craft::$app->getDrafts()->createDraft($bundle, $userId);
            $draft->title = 'Renamed';
            Craft::$app->getElements()->saveElement($draft, false);
            Craft::$app->getDrafts()->applyDraft($draft);
        });

        $live = Bundle::find()->id($bundle->id)->status(null)->one();

        expect($live->title)->toBe('Renamed')
            ->and($live->getSku())->toBe($sku);
    });
});


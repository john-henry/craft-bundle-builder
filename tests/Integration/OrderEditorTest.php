<?php

/**
 * Pins the CP order editor: the data behind each bundle line's component list
 * and variant choices, the hook that hands it to the page, and changing a
 * line's variants on an incomplete order.
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use craft\web\View;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;

/**
 * Builds a line item carrying the given selections in its snapshot.
 *
 * @param array<int, array<string, mixed>> $selections
 */
function panelLine(array $selections, int $qty, string $description = 'Flavour Pack'): LineItem
{
    $lineItem = new LineItem();
    $lineItem->qty = $qty;
    $lineItem->setDescription($description);
    $lineItem->setSnapshot(['bundleProducts' => $selections]);

    return $lineItem;
}

/**
 * Saves a cart holding one line of the given bundle, with the given choices.
 *
 * @param array<int, string> $choices Variant IDs keyed by product ID.
 */
function editorCart(Bundle $bundle, array $choices): Order
{
    $commerce = Commerce::getInstance();
    $order = new Order();
    $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
    $order->currency = 'USD';
    $order->addLineItem($commerce->getLineItems()->resolveLineItem($order, $bundle->id, ['bundleProducts' => $choices]));

    withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($order, false));

    return $commerce->getOrders()->getOrderById($order->id);
}

describe('The CP order editor data', function () {
    it('lists each bundle line’s components with quantities, and skips other lines', function () {
        $product = makeProduct(10.0);
        $variant = $product->getDefaultVariant();

        $order = orderWithLineItems([
            panelLine([[
                'productId' => $product->id,
                'variantId' => $variant->id,
                'qty' => 2,
                'productTitle' => 'Ballpoint Pen',
                'variantTitle' => 'Black',
                'variantSku' => 'PEN-BLK',
            ]], 3),
            panelLine([], 1, 'A plain product'),
        ]);

        $lines = BundleBuilder::getInstance()->getBundleCart()->getOrderComponents($order);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['qty'])->toBe(3)
            ->and($lines[0]['components'][0])->toMatchArray([
                'productId' => (int)$product->id,
                'variantId' => (int)$variant->id,
                'productTitle' => 'Ballpoint Pen',
                'variantTitle' => 'Black',
                'sku' => 'PEN-BLK',
                'qtyPerBundle' => 2,
                'totalQty' => 6,
            ]);
    });

    it('falls back to the variant’s SKU for snapshots saved before SKUs were recorded', function () {
        $product = makeProduct(10.0);
        $variant = $product->getDefaultVariant();

        $order = orderWithLineItems([
            panelLine([['productId' => $product->id, 'variantId' => $variant->id, 'qty' => 1]], 1),
        ]);

        $lines = BundleBuilder::getInstance()->getBundleCart()->getOrderComponents($order);

        expect($lines[0]['components'][0]['sku'])->toBe($variant->getSku());
    });

    it('hands the page its data through the order edit hook only when the order has a bundle', function () {
        (fn() => $this->_registerOrderEditor())->call(BundleBuilder::getInstance());

        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $plainContext = ['order' => orderWithLineItems([panelLine([], 1, 'A plain product')])];
        $view->startJsBuffer();
        $view->invokeHook('cp.commerce.order.edit', $plainContext);
        $plainJs = (string)$view->clearJsBuffer(false);

        $bundleContext = ['order' => orderWithLineItems([
            panelLine([['productTitle' => 'Ballpoint Pen', 'variantTitle' => 'Black', 'qty' => 1]], 1),
        ])];
        $view->startJsBuffer();
        $view->invokeHook('cp.commerce.order.edit', $bundleContext);
        $bundleJs = (string)$view->clearJsBuffer(false);

        expect($plainJs)->not->toContain('OrderVariantEditor')
            ->and($bundleJs)->toContain('Craft.BundleBuilder.OrderVariantEditor(')
            ->and($bundleJs)->toContain('Ballpoint Pen');
    });
});

describe('Changing a bundle line’s variants', function () {
    it('switches the variant on an incomplete order', function () {
        $product = makeProduct(10.0);
        $default = $product->getDefaultVariant();
        $other = addVariant($product, 12.0);
        $bundle = makeSavedBundle(makeBundleType('Edit', 'edit' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);
        $order = editorCart($bundle, [$product->id => (string)$default->id]);
        $lineItemId = $order->getLineItems()[0]->id;

        $errors = withFileCacheWarningsSuppressed(fn() => BundleBuilder::getInstance()->getBundleCart()
            ->changeVariants($order, $lineItemId, [$product->id => (string)$other->id]));

        $reloaded = Commerce::getInstance()->getOrders()->getOrderById($order->id);
        $selections = BundleBuilder::getInstance()->getBundleCart()->getSelections($reloaded->getLineItems()[0]);

        $editorLine = BundleBuilder::getInstance()->getBundleCart()->getOrderComponents($reloaded)[0];

        expect($errors)->toBe([])
            ->and($editorLine['choices'])->toBe([(int)$product->id => (int)$other->id])
            ->and($selections[0]['variantId'])->toBe((int)$other->id)
            ->and($reloaded->getLineItems()[0]->getOptions()['bundleProducts'])->toBe([$product->id => (string)$other->id])
            // An automatic bundle is re-priced for the new variant
            ->and($reloaded->getLineItems()[0]->getPrice())->toBe(12.0);
    });

    it('refuses a variant from a different product', function () {
        $product = makeProduct(10.0);
        $stranger = makeProduct(5.0)->getDefaultVariant();
        $bundle = makeSavedBundle(makeBundleType('Wrong', 'wrong' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);
        $order = editorCart($bundle, [$product->id => (string)$product->getDefaultVariant()->id]);

        $errors = BundleBuilder::getInstance()->getBundleCart()
            ->changeVariants($order, $order->getLineItems()[0]->id, [$product->id => (string)$stranger->id]);

        expect($errors)->not->toBeEmpty();
    });

    it('refuses a change that would duplicate another line', function () {
        $product = makeProduct(10.0);
        $default = $product->getDefaultVariant();
        $other = addVariant($product, 12.0);
        $bundle = makeSavedBundle(makeBundleType('Twin', 'twin' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);

        $order = editorCart($bundle, [$product->id => (string)$default->id]);
        $order->addLineItem(Commerce::getInstance()->getLineItems()
            ->resolveLineItem($order, $bundle->id, ['bundleProducts' => [$product->id => (string)$other->id]]));
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($order, false));
        $order = Commerce::getInstance()->getOrders()->getOrderById($order->id);

        // The line still on the default variant, switched to the other line's choice.
        $defaultLine = array_values(array_filter(
            $order->getLineItems(),
            static fn(LineItem $line): bool => $line->getOptions()['bundleProducts'][$product->id] === (string)$default->id,
        ))[0];
        $errors = BundleBuilder::getInstance()->getBundleCart()
            ->changeVariants($order, $defaultLine->id, [$product->id => (string)$other->id]);

        expect($errors)->toContain('Another line already has these choices. Change its quantity instead.');
    });

    it('refuses a change the new variant’s stock can’t cover', function () {
        $product = makeProduct(10.0);
        $default = $product->getDefaultVariant();
        $soldOut = addVariant($product, 12.0, 0);
        $bundle = makeSavedBundle(makeBundleType('Stock', 'stock' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);
        $order = editorCart($bundle, [$product->id => (string)$default->id]);

        $errors = withFileCacheWarningsSuppressed(fn() => BundleBuilder::getInstance()->getBundleCart()
            ->changeVariants($order, $order->getLineItems()[0]->id, [$product->id => (string)$soldOut->id]));

        expect($errors)->not->toBeEmpty();
    });

    it('refuses a bundle that’s no longer available', function () {
        $product = makeProduct(10.0);
        $default = $product->getDefaultVariant();
        $other = addVariant($product, 12.0);
        $bundle = makeSavedBundle(makeBundleType('Gone', 'gone' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);
        $order = editorCart($bundle, [$product->id => (string)$default->id]);

        $bundle->enabled = false;
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($bundle, false));

        // Commerce memoizes purchasables per request; a real request starts afresh.
        $purchasables = Commerce::getInstance()->getPurchasables();
        (fn() => $this->_purchasableById = null)->call($purchasables);
        $order = Commerce::getInstance()->getOrders()->getOrderById($order->id);

        $errors = BundleBuilder::getInstance()->getBundleCart()
            ->changeVariants($order, $order->getLineItems()[0]->id, [$product->id => (string)$other->id]);

        expect($errors)->toBe(['This bundle is no longer available, so its variants can’t be changed.']);
    });

    it('refuses a completed order', function () {
        $order = new Order();
        $order->isCompleted = true;

        $errors = BundleBuilder::getInstance()->getBundleCart()->changeVariants($order, 1, []);

        expect($errors)->toBe(['Variants can only be changed before the order is completed.']);
    });
});

describe('Variants that can’t be bought', function () {
    /**
     * Adds a variant, then makes it unbuyable in the given way.
     */
    function unbuyableVariant(Product $product, string $how): Variant
    {
        $variant = addVariant($product, 12.0);

        if ($how === 'disabled') {
            $variant->enabled = false;
        } else {
            $variant->availableForPurchase = false;
        }

        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($variant, false));

        return $variant;
    }

    it('are refused by the variant editor and left out of its choices', function (string $how) {
        $product = makeProduct(10.0);
        $default = $product->getDefaultVariant();
        $blocked = unbuyableVariant($product, $how);
        $bundle = makeSavedBundle(makeBundleType('Unbuyable', 'unbuyable' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);
        $order = editorCart($bundle, [$product->id => (string)$default->id]);

        $choices = array_column(BundleBuilder::getInstance()->getBundleCart()->getOrderComponents($order)[0]['components'][0]['variants'], 'id');
        $errors = BundleBuilder::getInstance()->getBundleCart()
            ->changeVariants($order, $order->getLineItems()[0]->id, [$product->id => (string)$blocked->id]);

        expect($choices)->not->toContain((int)$blocked->id)
            ->and($errors)->not->toBeEmpty();
    })->with(['disabled', 'not available for purchase']);

    it('fall back to the default variant when a cart form posts one', function () {
        $product = makeProduct(10.0);
        $default = $product->getDefaultVariant();
        $blocked = unbuyableVariant($product, 'not available for purchase');
        $bundle = makeSavedBundle(makeBundleType('Fallback', 'fallback' . StringHelper::randomString(6)), [['product' => $product, 'qty' => 1]]);
        $order = editorCart($bundle, [$product->id => (string)$blocked->id]);

        $selections = BundleBuilder::getInstance()->getBundleCart()->getSelections($order->getLineItems()[0]);

        expect($selections[0]['variantId'])->toBe((int)$default->id);
    });
});

describe('The editor data on an incomplete order', function () {
    it('includes a component added to the bundle since the cart was saved', function () {
        $first = makeProduct(10.0);
        $second = makeProduct(20.0);
        $bundle = makeSavedBundle(makeBundleType('Grown', 'grown' . StringHelper::randomString(6)), [['product' => $first, 'qty' => 1]]);
        $order = editorCart($bundle, [$first->id => (string)$first->getDefaultVariant()->id]);

        $bundle->setProducts([['productId' => $first->id, 'qty' => 1], ['productId' => $second->id, 'qty' => 1]]);
        withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->saveElement($bundle, false));
        $purchasables = Commerce::getInstance()->getPurchasables();
        (fn() => $this->_purchasableById = null)->call($purchasables);
        $order = Commerce::getInstance()->getOrders()->getOrderById($order->id);

        $components = BundleBuilder::getInstance()->getBundleCart()->getOrderComponents($order)[0]['components'];

        expect(array_column($components, 'productId'))->toBe([(int)$first->id, (int)$second->id])
            ->and($components[1]['variantId'])->toBe((int)$second->getDefaultVariant()->id);
    });

    it('passes the posted choices as integer IDs only', function () {
        $lineItem = panelLine([['productTitle' => 'Pen', 'variantTitle' => 'Black', 'qty' => 1]], 1);
        $lineItem->setOptions(['bundleProducts' => ['97' => '99', 'x' => '<script>', '5' => 'nope']]);

        $lines = BundleBuilder::getInstance()->getBundleCart()->getOrderComponents(orderWithLineItems([$lineItem]));

        expect($lines[0]['choices'])->toBe([97 => 99]);
    });
});


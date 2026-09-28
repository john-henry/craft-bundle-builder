<?php

/**
 * Coverage for variant-aware bundle pricing and the variants each component
 * offers.
 *
 * An automatic bundle's stored price is for each component's default variant;
 * a cart line adjusts it for the variants actually chosen, less the bundle's
 * percentage discount. A fixed price bundle never moves. A component can limit
 * the variants the customer chooses from, which the cart, the default choice,
 * the stored price and availability all respect.
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\DiscountType;
use johnhenry\bundlebuilder\enums\PricingStrategy;

/**
 * Saves a bundle with one component of the given product, at the given
 * pricing, discount and allowed variants.
 *
 * @param int[]|null $variantIds
 */
function variantBundle(
    Product $product,
    int $qty = 1,
    string $pricingStrategy = PricingStrategy::Automatic->value,
    ?string $discountType = null,
    float $discountAmount = 0.0,
    ?array $variantIds = null,
    float $fixedPrice = 50.0,
): Bundle {
    return withFileCacheWarningsSuppressed(static function () use ($product, $qty, $pricingStrategy, $discountType, $discountAmount, $variantIds, $fixedPrice): Bundle {
        $bundle = new Bundle();
        $bundle->typeId = makeBundleType('Variants ' . StringHelper::randomString(6), 'variants' . StringHelper::randomString(6))->id;
        $bundle->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle->title = 'Variant Bundle ' . StringHelper::randomString(6);
        $bundle->enabled = true;
        $bundle->pricingStrategy = $pricingStrategy;
        $bundle->discountType = $discountType;
        $bundle->discountAmount = $discountAmount;

        if ($pricingStrategy === PricingStrategy::Fixed->value) {
            $bundle->setBasePrice($fixedPrice);
        }

        $bundle->setProducts([[
            'productId' => $product->id,
            'qty' => $qty,
            'variantIds' => $variantIds,
        ]]);

        if (!Craft::$app->getElements()->saveElement($bundle, false)) {
            throw new RuntimeException('Could not save bundle: ' . implode(', ', $bundle->getErrorSummary(true)));
        }

        return Bundle::find()->id($bundle->id)->status(null)->one();
    });
}

/**
 * Resolves a cart line for a bundle with the given component choice.
 */
function variantLine(Bundle $bundle, Product $product, ?int $variantId): LineItem
{
    $commerce = Commerce::getInstance();
    $order = new Order();
    $order->storeId = $commerce->getStores()->getPrimaryStore()->id;
    $order->currency = 'USD';

    $options = $variantId ? ['bundleProducts' => [$product->id => (string)$variantId]] : [];

    return $commerce->getLineItems()->resolveLineItem($order, $bundle->id, $options);
}

describe('An automatic bundle in the cart', function () {
    it('costs more for a dearer variant, less the percentage discount', function () {
        $product = makeProduct(10.0);
        $dearer = addVariant($product, 20.0);
        $bundle = variantBundle($product, qty: 2, discountType: DiscountType::Percentage->value, discountAmount: 10.0);

        // Defaults: 2 × $10 less 10%
        expect((float)$bundle->getBasePrice())->toBe(18.0);
        expect(variantLine($bundle, $product, null)->getPrice())->toBe(18.0);

        // 2 × $10 dearer, less 10%, on top
        expect(variantLine($bundle, $product, (int)$dearer->id)->getPrice())->toBe(36.0);
    });

    it('costs less for a cheaper variant', function () {
        $product = makeProduct(10.0);
        $cheaper = addVariant($product, 6.0);
        $bundle = variantBundle($product, discountType: DiscountType::Percentage->value, discountAmount: 50.0);

        expect(variantLine($bundle, $product, (int)$cheaper->id)->getPrice())->toBe(3.0);
    });

    it('passes the whole difference on under a flat discount', function () {
        $product = makeProduct(10.0);
        $dearer = addVariant($product, 14.0);
        $bundle = variantBundle($product, discountType: DiscountType::Flat->value, discountAmount: 2.0);

        expect((float)$bundle->getBasePrice())->toBe(8.0);
        expect(variantLine($bundle, $product, (int)$dearer->id)->getPrice())->toBe(12.0);
    });

    it('charges the sum of the rounded differences shown beside each variant', function () {
        $first = makeProduct(3.5);
        $second = makeProduct(3.5);
        $firstFlagon = addVariant($first, 12.25);
        $secondFlagon = addVariant($second, 12.25);
        $bundle = makeSavedBundle(makeBundleType('Rounding ' . StringHelper::randomString(6), 'rounding' . StringHelper::randomString(6)), [
            ['product' => $first, 'qty' => 1],
            ['product' => $second, 'qty' => 1],
        ]);
        $bundle->discountType = DiscountType::Percentage->value;
        $bundle->discountAmount = 10.0;

        // Each difference is 8.75 less 10% = 7.875, shown as 7.88
        expect($bundle->getVariantPriceAdjustment($bundle->getProducts()[0], $firstFlagon))->toBe(7.88);

        $adjustment = \johnhenry\bundlebuilder\BundleBuilder::getInstance()->getBundlePricing()->getSelectionsAdjustment($bundle, [
            ['productId' => (int)$first->id, 'variantId' => (int)$firstFlagon->id],
            ['productId' => (int)$second->id, 'variantId' => (int)$secondFlagon->id],
        ]);

        expect($adjustment)->toBe(15.76);
    });

    it('records the adjusted price in the line snapshot', function () {
        $product = makeProduct(10.0);
        $dearer = addVariant($product, 15.0);
        $bundle = variantBundle($product);

        expect(variantLine($bundle, $product, (int)$dearer->id)->getSnapshot()['price'])->toBe(15.0);
    });
});

describe('A fixed price bundle in the cart', function () {
    it('keeps its price whichever variant is chosen', function () {
        $product = makeProduct(10.0);
        $dearer = addVariant($product, 40.0);
        $bundle = variantBundle($product, pricingStrategy: PricingStrategy::Fixed->value, fixedPrice: 25.0);

        expect(variantLine($bundle, $product, (int)$dearer->id)->getPrice())->toBe(25.0);
        expect($bundle->getVariantPriceAdjustment($bundle->getProducts()[0], $dearer))->toBe(0.0);
    });
});

describe('Bundle::getPriceRange()', function () {
    it('spans the cheapest and dearest choices', function () {
        $product = makeProduct(10.0);
        addVariant($product, 4.0);
        addVariant($product, 30.0);
        $bundle = variantBundle($product, discountType: DiscountType::Percentage->value, discountAmount: 50.0);

        expect($bundle->getPriceRange())->toBe(['min' => 2.0, 'max' => 15.0]);
    });

    it('is a single price for a fixed price bundle', function () {
        $product = makeProduct(10.0);
        addVariant($product, 30.0);
        $bundle = variantBundle($product, pricingStrategy: PricingStrategy::Fixed->value, fixedPrice: 25.0);

        expect($bundle->getPriceRange())->toBe(['min' => 25.0, 'max' => 25.0]);
    });
});

describe('A component limited to some variants', function () {
    it('defaults to the first it offers when the product default is left out', function () {
        $product = makeProduct(10.0);
        $only = addVariant($product, 16.0);
        $bundle = variantBundle($product, variantIds: [(int)$only->id]);

        expect((int)$bundle->getProducts()[0]->getDefaultVariant()?->id)->toBe((int)$only->id);
        // The stored automatic price is for the variant it offers
        expect((float)$bundle->getBasePrice())->toBe(16.0);
    });

    it('ignores a posted variant it does not offer', function () {
        $product = makeProduct(10.0);
        $offered = addVariant($product, 12.0);
        $bundle = variantBundle($product, variantIds: [(int)$offered->id]);
        $default = $product->getDefaultVariant();

        $line = variantLine($bundle, $product, (int)$default?->id);
        $selection = $line->getSnapshot()['bundleProducts'][0];

        expect($selection['variantId'])->toBe((int)$offered->id);
        expect($line->getPrice())->toBe(12.0);
    });

    it('keeps its variants through saves and copies', function () {
        $product = makeProduct(10.0);
        $offered = addVariant($product, 12.0);
        $bundle = variantBundle($product, variantIds: [(int)$offered->id]);

        expect($bundle->getProducts()[0]->variantIds)->toBe([(int)$offered->id]);

        $copy = Craft::$app->getElements()->duplicateElement($bundle);
        expect(Bundle::find()->id($copy->id)->status(null)->one()->getProducts()[0]->variantIds)->toBe([(int)$offered->id]);
    });

    it('offers every variant when posted with the All option', function () {
        $bundle = new Bundle();
        $bundle->setProducts([['productId' => 1, 'qty' => 1, 'variantIds' => '*']]);

        expect($bundle->getProducts()[0]->variantIds)->toBeNull();
    });

    it('is invalid with no variants ticked', function () {
        $product = makeProduct(10.0);
        addVariant($product, 12.0);

        $bundle = new Bundle();
        $bundle->setProducts([['productId' => $product->id, 'qty' => 1, 'variantIds' => '']]);
        $bundle->validateProducts('products');

        expect($bundle->getErrors('products'))->toHaveCount(1);
    });

    it('is invalid when none of its variants belong to its product', function () {
        $product = makeProduct(10.0);
        $other = makeProduct(10.0);

        $bundle = new Bundle();
        $bundle->setProducts([['productId' => $product->id, 'qty' => 1, 'variantIds' => [(int)$other->getDefaultVariant()?->id]]]);
        $bundle->validateProducts('products');

        expect($bundle->getErrors('products'))->toHaveCount(1);
    });

    it('makes the bundle unavailable when the variants it offers are out of stock', function () {
        $product = makeProduct(10.0, stock: 5);
        $soldOut = addVariant($product, 12.0, stock: 0);

        expect(variantBundle($product)->getIsAvailable())->toBeTrue();
        expect(variantBundle($product, variantIds: [(int)$soldOut->id])->getIsAvailable())->toBeFalse();
    });
});

describe('Variant pricing edge cases', function () {
    it('scales the difference by a promotion on the bundle', function () {
        $product = makeProduct(10.0);
        $dearer = addVariant($product, 20.0);
        $bundle = variantBundle($product);

        // A 50% promotion on the bundle: the +10 difference is halved on the sale price
        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setPrice(100.0);
        $lineItem->setPromotionalPrice(50.0);
        $lineItem->setOptions(['bundleProducts' => [$product->id => (string)$dearer->id]]);
        $bundle->populateLineItem($lineItem);

        expect($lineItem->getPrice())->toBe(110.0);
        expect($lineItem->getPromotionalPrice())->toBe(55.0);
    });

    it('keeps a price floored by a big flat discount at 0 until a choice lifts it', function () {
        $product = makeProduct(40.0);
        $slightly = addVariant($product, 45.0);
        $much = addVariant($product, 60.0);
        $bundle = variantBundle($product, discountType: DiscountType::Flat->value, discountAmount: 50.0);
        $pricing = \johnhenry\bundlebuilder\BundleBuilder::getInstance()->getBundlePricing();

        expect((float)$bundle->getBasePrice())->toBe(0.0);
        expect($pricing->getSelectionsAdjustment($bundle, [['productId' => (int)$product->id, 'variantId' => (int)$slightly->id]]))->toBe(0.0);
        expect($pricing->getSelectionsAdjustment($bundle, [['productId' => (int)$product->id, 'variantId' => (int)$much->id]]))->toBe(10.0);
    });

    it('gives no adjustment for another product’s variant', function () {
        $product = makeProduct(10.0);
        $other = makeProduct(10.0);
        $foreign = addVariant($other, 99.0);
        $bundle = variantBundle($product);

        expect($bundle->getVariantPriceAdjustment($bundle->getProducts()[0], $foreign))->toBe(0.0);
    });

    it('drops a promotional price left from when the bundle was fixed price', function () {
        $product = makeProduct(10.0);
        $bundle = variantBundle($product, pricingStrategy: PricingStrategy::Fixed->value, fixedPrice: 30.0);
        $bundle->setBasePromotionalPrice(20.0);
        $bundle->pricingStrategy = PricingStrategy::Automatic->value;
        Craft::$app->getElements()->saveElement($bundle, false);

        expect(Bundle::find()->id($bundle->id)->status(null)->one()->getBasePromotionalPrice())->toBeNull();
    });
});

describe('A component limited to variants that aren’t for sale', function () {
    it('is invalid', function () {
        $product = makeProduct(10.0);
        $notForSale = addVariant($product, 12.0);
        $notForSale->availableForPurchase = false;
        Craft::$app->getElements()->saveElement($notForSale, false);

        $bundle = new Bundle();
        $bundle->setProducts([['productId' => $product->id, 'qty' => 1, 'variantIds' => [(int)$notForSale->id]]]);
        $bundle->validateProducts('products');

        expect($bundle->getErrors('products'))->toHaveCount(1);
    });
});

describe('A component’s variants in a revision', function () {
    it('are kept', function () {
        $product = makeProduct(10.0);
        $offered = addVariant($product, 12.0);
        $bundle = variantBundle($product, variantIds: [(int)$offered->id]);

        $revisionId = Craft::$app->getRevisions()->createRevision($bundle);
        $revision = Bundle::find()->id($revisionId)->revisions()->status(null)->one();

        expect($revision->getProducts()[0]->variantIds)->toBe([(int)$offered->id]);
    });
});


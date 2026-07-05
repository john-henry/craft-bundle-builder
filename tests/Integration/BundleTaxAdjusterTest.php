<?php

/**
 * Coverage for BundleTaxAdjuster: the apportionment maths (component tax always
 * summing back to the tax on the whole line, no matter how the per-component
 * amounts round), the multi-rate "multiple supply" path, and the getIsTaxable()
 * gate. Every rate in this file is "everywhere" (no taxZoneId), so zone matching
 * is never in question.
 *
 * The reverse-charge exemption (a validated tax ID meaning no tax is added) is
 * covered below by seeding the same validation cache Commerce's own adjuster
 * uses. NOT covered: the removeIncluded / removeVatIncluded path that produces
 * a negative "tax removed" adjustment, since Commerce only allows a removable
 * included rate on the *default* zone (TaxRates::saveTaxRate()), which needs a
 * saved TaxZone + condition fixture this suite doesn't have yet; left to manual
 * QA until that fixture exists.
 */

use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\models\TaxCategory;
use craft\commerce\models\TaxRate;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\commerce\taxidvalidators\EuVatIdValidator;
use craft\elements\Address;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\adjusters\BundleTaxAdjuster;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;

/**
 * Saves a tax category, for assigning to a component variant.
 */
function makeTaxCategory(string $name): TaxCategory
{
    $category = new TaxCategory();
    $category->name = $name;
    $category->handle = 'cat' . StringHelper::randomString(6);

    if (!Commerce::getInstance()->getTaxCategories()->saveTaxCategory($category)) {
        throw new RuntimeException('Could not save test tax category: ' . implode(', ', $category->getErrorSummary(true)));
    }

    return $category;
}

/**
 * Saves an "everywhere" purchasable tax rate (no tax zone, so it applies
 * regardless of address) at the given rate for a tax category.
 */
function makeTaxRate(int $taxCategoryId, float $rate, bool $include = false, array $taxIdValidators = []): TaxRate
{
    $taxRate = new TaxRate();
    $taxRate->name = 'Test rate ' . StringHelper::randomString(4);
    $taxRate->rate = $rate;
    $taxRate->include = $include;
    $taxRate->taxable = TaxRateRecord::TAXABLE_PURCHASABLE;
    $taxRate->taxCategoryId = $taxCategoryId;
    $taxRate->taxIdValidators = $taxIdValidators;
    $taxRate->storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;
    $taxRate->enabled = true;

    if (!Commerce::getInstance()->getTaxRates()->saveTaxRate($taxRate)) {
        throw new RuntimeException('Could not save test tax rate: ' . implode(', ', $taxRate->getErrorSummary(true)));
    }

    return $taxRate;
}

/**
 * Builds an unsaved order carrying the given line items, at the given
 * currency, in the primary store.
 *
 * @param LineItem[] $lineItems
 */
function orderWithLineItems(array $lineItems, string $currency = 'USD'): Order
{
    $order = new Order();
    $order->storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;
    $order->currency = $currency;
    $order->setLineItems($lineItems);

    return $order;
}

/**
 * Builds a taxable "multiple supply" bundle line item carrying the given
 * component selections, at the given sale price.
 *
 * @param array<int, array<string, mixed>> $selections
 */
function bundleTaxLineItem(Bundle $bundle, array $selections, float $salePrice, int $qty = 1): LineItem
{
    $lineItem = new LineItem();
    $lineItem->qty = $qty;
    $lineItem->setPrice($salePrice);
    $lineItem->setPurchasable($bundle);

    $snapshot = $lineItem->getSnapshot() ?? [];
    $snapshot['bundleProducts'] = $selections;
    $lineItem->setSnapshot($snapshot);

    return $lineItem;
}

/**
 * Builds a real product + variant assigned to the given tax category, and
 * returns the selection row for it (ready to pass to bundleTaxLineItem()).
 *
 * @return array{selection: array, product: \craft\commerce\elements\Product}
 */
function taxableComponent(float $basePrice, int $taxCategoryId, int $qty = 1, ?float $promotionalPrice = null): array
{
    $product = makeProduct($basePrice, promotionalPrice: $promotionalPrice);
    $variant = Commerce::getInstance()->getProducts()->getProductById($product->id)?->getDefaultVariant();
    $variant->setTaxCategoryId($taxCategoryId);

    if (!Craft::$app->getElements()->saveElement($variant, false)) {
        throw new RuntimeException('Could not save test variant tax category: ' . implode(', ', $variant->getErrorSummary(true)));
    }

    return [
        'selection' => ['productId' => $product->id, 'variantId' => $variant->id, 'qty' => $qty],
        'product' => $product,
    ];
}

// ---------------------------------------------------------------------------
// Apportionment reconciliation: component tax always sums back to the line tax
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::adjust(): apportionment reconciliation', function () {
    it('reconciles component tax to exactly the tax on the whole line when every component shares one rate', function () {
        $category = makeTaxCategory('Standard');
        makeTaxRate($category->id, 0.20);

        // Deliberately uneven weights (1 : 2 : 4) against a line total that
        // doesn't divide evenly, so rounding each component's tax on its own
        // would miss the true line tax by a cent or two.
        $a = taxableComponent(1.0, $category->id);
        $b = taxableComponent(2.0, $category->id);
        $c = taxableComponent(4.0, $category->id);

        $bundleType = makeBundleType('Mixed Rate', 'mixedRate' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = bundleTaxLineItem($bundle, [$a['selection'], $b['selection'], $c['selection']], 10.00);
        $order = orderWithLineItems([$lineItem]);

        $adjustments = (new BundleTaxAdjuster())->adjust($order);

        expect($adjustments)->toHaveCount(3);

        $total = array_sum(array_map(static fn($adjustment) => $adjustment->amount, $adjustments));

        // round(10.00 * 0.20, 2): what Commerce's core adjuster would
        // compute taxing the line once, with no apportionment at all.
        expect(round($total, 2))->toBe(2.00);

        foreach ($adjustments as $adjustment) {
            expect($adjustment->type)->toBe('tax');
            expect($adjustment->included)->toBeFalse();
        }
    });

    it('apportions the line price across components reconciling exactly to the line subtotal', function () {
        $category = makeTaxCategory('Standard');
        makeTaxRate($category->id, 0.20);

        $a = taxableComponent(1.0, $category->id);
        $b = taxableComponent(2.0, $category->id);
        $c = taxableComponent(4.0, $category->id);

        $bundleType = makeBundleType('Mixed Rate', 'mixedRate' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $teller = Commerce::getInstance()->getCurrencies()->getTeller('USD');
        $components = [
            ['variant' => Commerce::getInstance()->getProducts()->getProductById($a['product']->id)->getDefaultVariant(), 'weight' => 1.0],
            ['variant' => Commerce::getInstance()->getProducts()->getProductById($b['product']->id)->getDefaultVariant(), 'weight' => 2.0],
            ['variant' => Commerce::getInstance()->getProducts()->getProductById($c['product']->id)->getDefaultVariant(), 'weight' => 4.0],
        ];

        $apportion = new ReflectionMethod(BundleTaxAdjuster::class, '_apportion');
        $apportion->setAccessible(true);
        $amounts = $apportion->invoke(new BundleTaxAdjuster(), 10.00, $components, 7.0, $teller);

        expect($amounts)->toHaveCount(3);
        expect(round(array_sum($amounts), 2))->toBe(10.00);
    });
});

// ---------------------------------------------------------------------------
// Mixed tax rates: components taxed at their own (different) rates
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::adjust(): mixed tax rates', function () {
    it('taxes each component at its own category rate, including a zero rate', function () {
        $zeroRated = makeTaxCategory('Zero Rated');
        $standard = makeTaxCategory('Standard');
        makeTaxRate($zeroRated->id, 0.0);
        makeTaxRate($standard->id, 0.20);

        $exempt = taxableComponent(4.0, $zeroRated->id);
        $taxed = taxableComponent(6.0, $standard->id);

        $bundleType = makeBundleType('Split Rate', 'splitRate' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = bundleTaxLineItem($bundle, [$exempt['selection'], $taxed['selection']], 10.00);
        $order = orderWithLineItems([$lineItem]);

        $adjustments = (new BundleTaxAdjuster())->adjust($order);

        // Only the standard-rated component produces an adjustment; the
        // zero-rated share's tax amount is 0.0, which _distribute() skips.
        expect($adjustments)->toHaveCount(1);
        expect(round($adjustments[0]->amount, 2))->toBe(1.20); // 20% of the $6 share
    });
});

// ---------------------------------------------------------------------------
// Selling-price weighting: a component on sale takes a smaller share
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::adjust(): selling-price weighting', function () {
    it('apportions by each component’s sale price, not its base price', function () {
        $standard = makeTaxCategory('Standard');
        $zeroRated = makeTaxCategory('Zero Rated');
        makeTaxRate($standard->id, 0.20);
        makeTaxRate($zeroRated->id, 0.0);

        // Standard-rated component on sale (base 6.00, sale 4.00), plus a
        // zero-rated component at 4.00. The line sells for 8.00 (the sale-price
        // sum). Weighting by sale price splits it 4:4, so the taxed component's
        // share is 4.00 and its tax is 0.80. Weighting by base price would split
        // it 6:4, giving a 4.80 share and 0.96 tax, so this pins the sale-price
        // basis down.
        $taxed = taxableComponent(6.0, $standard->id, promotionalPrice: 4.0);
        $exempt = taxableComponent(4.0, $zeroRated->id);

        $bundleType = makeBundleType('Sale Split', 'saleSplit' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = bundleTaxLineItem($bundle, [$taxed['selection'], $exempt['selection']], 8.00);
        $order = orderWithLineItems([$lineItem]);

        $adjustments = (new BundleTaxAdjuster())->adjust($order);

        expect($adjustments)->toHaveCount(1);
        expect(round($adjustments[0]->amount, 2))->toBe(0.80);
    });
});

// ---------------------------------------------------------------------------
// Line discounts: tax follows what the customer actually pays
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::adjust(): line discounts', function () {
    it('taxes the discounted line subtotal, not the pre-discount subtotal', function () {
        $category = makeTaxCategory('Standard');
        makeTaxRate($category->id, 0.20);

        $a = taxableComponent(4.0, $category->id);
        $b = taxableComponent(6.0, $category->id);

        $bundleType = makeBundleType('Discounted', 'discounted' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        // A 10.00 line carrying a 2.00 discount: tax is 20% of the 8.00 the
        // customer actually pays (0.64 + 0.96 across the two components), not
        // 20% of the full 10.00.
        $lineItem = new class extends LineItem {
            public function getDiscount(): float
            {
                return -2.0;
            }
        };
        $lineItem->qty = 1;
        $lineItem->setPrice(10.00);
        $lineItem->setPurchasable($bundle);
        $snapshot = $lineItem->getSnapshot() ?? [];
        $snapshot['bundleProducts'] = [$a['selection'], $b['selection']];
        $lineItem->setSnapshot($snapshot);

        $order = orderWithLineItems([$lineItem]);
        $adjustments = (new BundleTaxAdjuster())->adjust($order);

        $total = array_sum(array_map(static fn($adjustment) => $adjustment->amount, $adjustments));

        expect(round($total, 2))->toBe(1.60);
    });
});

// ---------------------------------------------------------------------------
// getIsTaxable() gate
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::adjust(): getIsTaxable() gate', function () {
    it('skips a multiple-supply bundle line item that is not taxable', function () {
        $category = makeTaxCategory('Standard');
        makeTaxRate($category->id, 0.20);

        $component = taxableComponent(10.0, $category->id);

        $bundleType = makeBundleType('Exempt', 'exempt' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = bundleTaxLineItem($bundle, [$component['selection']], 10.00);

        // Anonymous subclass overriding getIsTaxable(), mirroring this
        // project's own established pattern (see tests/README.md) for
        // isolating one branch of a method without a real non-taxable
        // purchasable to hand it (Bundle::getIsTaxable() always returns true,
        // inherited from Purchasable; there is currently no way to make a
        // real bundle non-taxable).
        $nonTaxableLineItem = new class extends LineItem {
            public function getIsTaxable(): bool
            {
                return false;
            }
        };
        $nonTaxableLineItem->qty = $lineItem->qty;
        $nonTaxableLineItem->setPrice($lineItem->getPrice());
        $nonTaxableLineItem->setPurchasable($bundle);
        $nonTaxableLineItem->setSnapshot($lineItem->getSnapshot());

        $order = orderWithLineItems([$nonTaxableLineItem]);

        expect((new BundleTaxAdjuster())->adjust($order))->toBeEmpty();
    });
});

// ---------------------------------------------------------------------------
// Reverse-charge exemption: a validated tax ID means no tax is added
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::adjust(): reverse-charge exemption', function () {
    it('adds no tax when the order carries a validated tax ID for a tax-ID rate', function () {
        $category = makeTaxCategory('Standard');
        makeTaxRate($category->id, 0.20, taxIdValidators: [EuVatIdValidator::class]);

        $component = taxableComponent(10.0, $category->id);

        $bundleType = makeBundleType('Reverse', 'reverse' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = bundleTaxLineItem($bundle, [$component['selection']], 10.00);

        // A business customer whose VAT ID Commerce has already validated: seed
        // the same cache key the core adjuster uses, so no external lookup runs
        // and the reverse-charge exemption applies.
        $address = new Address();
        $address->countryCode = 'IE';
        $address->organizationTaxId = 'IE1234567X';
        Craft::$app->getCache()->set('commerce:validVatId:IE1234567X', '1');

        $order = orderWithLineItems([$lineItem]);
        $order->setShippingAddress($address);

        expect((new BundleTaxAdjuster())->adjust($order))->toBeEmpty();
    });

    it('adds tax when the same order has no tax ID to exempt it', function () {
        $category = makeTaxCategory('Standard');
        makeTaxRate($category->id, 0.20, taxIdValidators: [EuVatIdValidator::class]);

        $component = taxableComponent(10.0, $category->id);

        $bundleType = makeBundleType('Reverse', 'reverse' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = bundleTaxLineItem($bundle, [$component['selection']], 10.00);

        $address = new Address();
        $address->countryCode = 'IE';

        $order = orderWithLineItems([$lineItem]);
        $order->setShippingAddress($address);

        $adjustments = (new BundleTaxAdjuster())->adjust($order);
        $total = array_sum(array_map(static fn($adjustment) => $adjustment->amount, $adjustments));

        expect(round($total, 2))->toBe(2.00);
    });
});

// ---------------------------------------------------------------------------
// Largest-remainder distribution: exact, currency-precision-aware
// ---------------------------------------------------------------------------

describe('BundleTaxAdjuster::_allocateProportionally()', function () {
    it('distributes a total proportionally, giving leftover minor units to the largest remainders', function () {
        $method = new ReflectionMethod(BundleTaxAdjuster::class, '_allocateProportionally');
        $method->setAccessible(true);

        // 3 weights (1 : 2 : 3) of a 10.00 total in USD (2-decimal):
        // exact shares 1.6667 / 3.3333 / 5.0000 → floors 1.66/3.33/5.00 in
        // cents (166/333/500 = 999), 1 cent remaining goes to the largest
        // fractional remainder (the first share, .667 vs .333 vs .0).
        $shares = $method->invoke(new BundleTaxAdjuster(), 10.00, [1.0, 2.0, 3.0], 6.0, 'USD');

        expect(round(array_sum($shares), 2))->toBe(10.00);
        expect($shares[0])->toBe(1.67);
        expect($shares[1])->toBe(3.33);
        expect($shares[2])->toBe(5.00);
    });

    it('reconciles exactly for a zero-decimal currency', function () {
        $method = new ReflectionMethod(BundleTaxAdjuster::class, '_allocateProportionally');
        $method->setAccessible(true);

        // JPY has no minor unit; the whole amount must land on whole yen.
        $shares = $method->invoke(new BundleTaxAdjuster(), 10.0, [1.0, 1.0, 1.0], 3.0, 'JPY');

        expect(array_sum($shares))->toBe(10.0);
        foreach ($shares as $share) {
            expect($share)->toBe(floor($share));
        }
    });
});

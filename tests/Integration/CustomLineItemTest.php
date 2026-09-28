<?php

/**
 * Pins that a custom line item on an order doesn't break the bundle code that
 * walks every line. Commerce throws when asked for a custom line item's
 * purchasable, and recalculation doesn't catch it, so one custom line would
 * take down saving the whole order.
 */

use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;
use craft\commerce\records\TaxRate as TaxRateRecord;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\adjusters\BundleTaxAdjuster;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;

/**
 * Builds a custom line item, as the CP's "Add a custom line item" makes.
 */
function customLine(?int $id = null): LineItem
{
    $lineItem = new LineItem();
    $lineItem->type = LineItemType::Custom;
    $lineItem->id = $id;
    $lineItem->qty = 1;
    $lineItem->setDescription('Gift wrap');
    $lineItem->setSku('GIFT-WRAP');
    $lineItem->setPrice(5.0);

    return $lineItem;
}

describe('A custom line item on an order', function () {
    it('doesn’t stop the bundle tax adjuster', function () {
        $standard = makeTaxCategory('Standard');
        makeTaxRate($standard->id, 0.20, taxable: TaxRateRecord::TAXABLE_PRICE);
        $component = taxableComponent(10.0, $standard->id);

        $bundle = new Bundle();
        $bundle->typeId = makeBundleType('Custom', 'custom' . StringHelper::randomString(6), TaxTreatment::Multiple->value)->id;

        $order = orderWithLineItems([customLine(), bundleTaxLineItem($bundle, [$component['selection']], 10.00)]);

        expect((new BundleTaxAdjuster())->adjust($order))->toHaveCount(1);
    });

    it('doesn’t stop the component stock check', function () {
        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setSnapshot([]);
        orderWithLineItems([customLine(), $lineItem]);

        expect(BundleBuilder::getInstance()->getBundleCart()->getStockErrors($lineItem))->toBe([]);
    });

    it('isn’t taken for a bundle line by the variant editor', function () {
        $order = orderWithLineItems([customLine(5)]);

        expect(BundleBuilder::getInstance()->getBundleCart()->changeVariants($order, 5, []))
            ->toBe(['That bundle line isn’t on this order.']);
    });
});

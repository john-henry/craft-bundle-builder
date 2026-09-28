<?php

/**
 * Pins what happens to a bundle's components through duplication, editing and
 * component deletion, and the tax category clean-up migration.
 */

use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\web\View;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\migrations\m260925_010000_reset_bundle_tax_category;
use johnhenry\bundlebuilder\models\BundleProduct;

describe('Duplicating a bundle', function () {
    it('copies its components', function () {
        $a = makeProduct(10.0);
        $b = makeProduct(25.0);
        $bundle = makeSavedBundle(makeBundleType('Dupe', 'dupe' . StringHelper::randomString(6)), [
            ['product' => $a, 'qty' => 1],
            ['product' => $b, 'qty' => 2],
        ]);

        // A fresh query result, as the index's Duplicate action works from.
        $source = Bundle::find()->id($bundle->id)->status(null)->one();
        $copy = withFileCacheWarningsSuppressed(fn() => Craft::$app->getElements()->duplicateElement($source));

        $copied = Bundle::find()->id($copy->id)->status(null)->one();
        $components = array_map(
            static fn(BundleProduct $product): array => [$product->productId, $product->qty],
            $copied->getProducts(),
        );

        expect($components)->toBe([[(int)$a->id, 1], [(int)$b->id, 2]])
            ->and($copied->price)->toBeGreaterThan(0.0);
    });
});

describe('Bundle components', function () {
    it('makes a bundle with no components unavailable', function () {
        $bundle = new Bundle();
        $bundle->setProducts([]);

        expect($bundle->getIsAvailable())->toBeFalse();
    });

    it('requires at least one component on a live bundle', function () {
        $bundle = new Bundle();
        $bundle->setProducts([]);
        $bundle->setScenario(Bundle::SCENARIO_LIVE);

        $bundle->validate(['products']);

        expect($bundle->getErrors('products'))->not->toBeEmpty();
    });

    it('clears the components when the editor posts an empty value', function () {
        $bundle = new Bundle();
        $bundle->setProducts([['productId' => 1, 'qty' => 1]]);
        $bundle->setProducts('');

        expect($bundle->getProducts())->toBe([]);
    });

    it('keeps a deleted product’s row in the editor so it isn’t dropped on save', function () {
        $product = makeProduct(10.0);
        $productId = (int)$product->id;
        Craft::$app->getElements()->deleteElement($product);

        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = $view->renderTemplate('bundle-builder/bundles/_product-row', [
            'index' => 0,
            'bundleProduct' => new BundleProduct(['productId' => $productId, 'qty' => 1]),
        ]);

        expect($html)->toContain('name="products[0][productId]" value="' . $productId . '"')
            ->and($html)->toContain('This product has been deleted');
    });
});

describe('The tax category clean-up migration', function () {
    it('moves bundles off the apportioned category onto the store default', function () {
        $bundle = makeSavedBundle(makeBundleType('Reset', 'reset' . StringHelper::randomString(6)), [
            ['product' => makeProduct(10.0), 'qty' => 1],
        ]);

        Craft::$app->getDb()->createCommand()
            ->update('{{%commerce_purchasables}}', ['taxCategoryId' => Bundle::apportionedTaxCategoryId()], ['id' => $bundle->id])
            ->execute();

        (new m260925_010000_reset_bundle_tax_category())->safeUp();

        $stored = (int)(new Query())
            ->select('taxCategoryId')
            ->from('{{%commerce_purchasables}}')
            ->where(['id' => $bundle->id])
            ->scalar();

        expect($stored)->toBe((int)Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory()->id);
    });
});

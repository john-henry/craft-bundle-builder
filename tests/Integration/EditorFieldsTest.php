<?php

/**
 * Pins the editor fields' JS wiring under a namespace, as in the slideout
 * editor: each field's container id is namespaced, and the init call has to
 * target that id or the component picker and pricing toggle do nothing.
 */

use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\helpers\StringHelper;
use craft\web\View;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\fieldlayoutelements\BundleComponentsField;
use johnhenry\bundlebuilder\fieldlayoutelements\BundlePricingField;
use johnhenry\bundlebuilder\models\BundleType;

/**
 * Renders a field's input HTML under the given namespace and returns the
 * HTML plus every registered JS snippet.
 *
 * @return array{html: string, js: string}
 */
function renderNamespacedInput(object $field, Bundle $bundle, string $namespace): array
{
    $view = Craft::$app->getView();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);
    $view->startJsBuffer();

    $html = $view->namespaceInputs(fn() => (fn() => $this->inputHtml($bundle, false))->call($field), $namespace);
    $js = (string)$view->clearJsBuffer(false);

    return ['html' => $html, 'js' => $js];
}

describe('Bundle editor fields', function () {
    beforeEach(function () {
        $bundleType = makeBundleType('Editor', 'editor' . StringHelper::randomString(6));
        $this->bundle = new Bundle();
        $this->bundle->typeId = $bundleType->id;
    });

    it('initialises the component picker against its namespaced container', function () {
        $out = renderNamespacedInput(new BundleComponentsField(), $this->bundle, 'slideout1');

        expect($out['html'])->toContain('id="slideout1-bundle-products"')
            ->and($out['js'])->toContain('Craft.BundleBuilder.ComponentsInput("#slideout1-bundle-products"')
            ->and($out['js'])->toContain('"namespace":"slideout1"');
    });

    it('initialises the pricing toggle against its namespaced container', function () {
        $out = renderNamespacedInput(new BundlePricingField(), $this->bundle, 'slideout1');

        expect($out['html'])->toContain('id="slideout1-bundle-pricing"')
            ->and($out['js'])->toContain('Craft.BundleBuilder.PricingInput("#slideout1-bundle-pricing")');
    });
});

describe('The pricing field', function () {
    /**
     * Renders the pricing field for a bundle on the given strategy.
     */
    function pricingHtml(string $strategy): string
    {
        $bundle = new Bundle();
        $bundle->typeId = makeBundleType('Pricing', 'pricing' . StringHelper::randomString(6))->id;
        $bundle->pricingStrategy = $strategy;

        return renderNamespacedInput(new BundlePricingField(), $bundle, 'main')['html'];
    }

    it('renders the automatic inputs hidden for a fixed-price bundle', function () {
        $html = pricingHtml(PricingStrategy::Fixed->value);

        expect($html)->toMatch('/data-pricing="fixed"(?![^>]*hidden)/')
            ->and($html)->toMatch('/data-pricing="automatic"[^>]*class="hidden"|class="hidden"[^>]*data-pricing="automatic"/');
    });

    it('renders the fixed-price inputs hidden for an automatic bundle', function () {
        $html = pricingHtml(PricingStrategy::Automatic->value);

        expect($html)->toMatch('/data-pricing="automatic"(?![^>]*hidden)/')
            ->and($html)->toMatch('/data-pricing="fixed"[^>]*class="hidden"|class="hidden"[^>]*data-pricing="fixed"/');
    });
});

describe('The components field', function () {
    it('greys out the other rows’ products in each row’s product picker', function () {
        $first = makeProduct(10.0);
        $second = makeProduct(20.0);
        $bundle = makeSavedBundle(makeBundleType('Pickers', 'pickers' . StringHelper::randomString(6)), [
            ['product' => $first, 'qty' => 1],
            ['product' => $second, 'qty' => 1],
        ]);

        $js = renderNamespacedInput(new BundleComponentsField(), $bundle, 'main')['js'];

        expect($js)->toContain('"disabledElementIds":[' . $second->id . ']')
            ->and($js)->toContain('"disabledElementIds":[' . $first->id . ']');
    });
});

describe('Component Sources', function () {
    /**
     * Saves a bundle type limited to the given component sources.
     *
     * @param string|string[] $sources
     */
    function typeWithSources(string|array $sources): BundleType
    {
        $bundleType = makeBundleType('Sources', 'sources' . StringHelper::randomString(6));
        $bundleType->componentSources = $sources;
        BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($bundleType);
        resetBundleTypesCache();

        return BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($bundleType->id);
    }

    it('default to all sources', function () {
        expect(makeBundleType('AllSources', 'allSources' . StringHelper::randomString(6))->componentSources)->toBe('*');
    });

    it('are saved and loaded with the bundle type', function () {
        expect(typeWithSources(['productType:abc', 'productType:def'])->componentSources)
            ->toBe(['productType:abc', 'productType:def']);
    });

    it('limit each row’s product picker', function () {
        $bundleType = typeWithSources(['productType:abc']);
        $bundle = makeSavedBundle($bundleType, [['product' => makeProduct(10.0), 'qty' => 1]]);

        $js = renderNamespacedInput(new BundleComponentsField(), $bundle, 'main')['js'];

        expect($js)->toContain('"sources":["productType:abc"]');
    });
});

describe('Component Sources on save', function () {
    /**
     * Returns the first two product types' IDs and source keys.
     *
     * @return array<int, array{id: int, source: string}>
     */
    function twoProductTypes(): array
    {
        $types = array_slice(Commerce::getInstance()->getProductTypes()->getAllProductTypes(), 0, 2);

        if (count($types) < 2) {
            test()->markTestSkipped('The test database needs two product types.');
        }

        return array_map(static fn($type): array => ['id' => (int)$type->id, 'source' => 'productType:' . $type->uid], $types);
    }

    /**
     * Validates a live bundle of a type limited to the given sources, holding
     * the given products.
     *
     * @param string[] $sources
     * @param Product[] $products
     * @return string[] The products errors.
     */
    function sourceErrors(array $sources, array $products): array
    {
        $bundle = new Bundle();
        $bundle->typeId = typeWithSources($sources)->id;
        $bundle->setProducts(array_map(static fn($product): array => ['productId' => $product->id, 'qty' => 1], $products));
        $bundle->setScenario(Bundle::SCENARIO_LIVE);
        $bundle->validate(['products']);

        return $bundle->getErrors('products');
    }

    it('accepts products from an allowed source', function () {
        [$allowed] = twoProductTypes();

        expect(sourceErrors([$allowed['source']], [makeProduct(10.0, typeId: $allowed['id'])]))->toBe([]);
    });

    it('refuses a product from another source', function () {
        [$allowed, $other] = twoProductTypes();
        $outsider = makeProduct(10.0, typeId: $other['id']);

        expect(sourceErrors([$allowed['source']], [$outsider]))
            ->toBe(['“' . $outsider->title . '” isn’t in one of this bundle type’s component sources.']);
    });

    it('leaves a deleted component to the availability check', function () {
        [$allowed, $other] = twoProductTypes();
        $gone = makeProduct(10.0, typeId: $other['id']);
        Craft::$app->getElements()->deleteElement($gone);

        expect(sourceErrors([$allowed['source']], [makeProduct(10.0, typeId: $allowed['id']), $gone]))->toBe([]);
    });

    it('doesn’t check drafts', function () {
        [$allowed, $other] = twoProductTypes();
        $bundle = new Bundle();
        $bundle->typeId = typeWithSources([$allowed['source']])->id;
        $bundle->setProducts([['productId' => makeProduct(10.0, typeId: $other['id'])->id, 'qty' => 1]]);
        $bundle->setScenario(Bundle::SCENARIO_ESSENTIALS);
        $bundle->validate(['products']);

        expect($bundle->getErrors('products'))->toBe([]);
    });
});


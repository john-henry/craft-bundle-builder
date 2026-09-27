<?php

/**
 * Coverage for the Bundle element's tax category, description and product
 * validation.
 *
 * A multiple-supply line item has to carry the zero-rate "apportioned" tax
 * category so Commerce's own tax adjuster leaves it alone and this plugin's
 * adjuster can split the tax across components. The category goes on the line
 * only: saved on the bundle, it would outlive a switch back to composite.
 */

use craft\commerce\models\LineItem;
use craft\helpers\StringHelper;
use craft\web\View;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;

describe('Bundle tax category', function () {
    /**
     * Builds a bundle line item for a bundle of the given tax treatment and
     * runs it through the bundle's populateLineItem().
     */
    function populatedTaxLine(string $taxTreatment): LineItem
    {
        $bundleType = makeBundleType('Tax', 'tax' . StringHelper::randomString(6), $taxTreatment);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        $lineItem = new LineItem();
        $lineItem->qty = 1;
        $lineItem->setOptions([]);
        $lineItem->setSnapshot([]);
        $lineItem->taxCategoryId = $bundle->getTaxCategoryId();
        $bundle->populateLineItem($lineItem);

        return $lineItem;
    }

    it('puts a multiple-supply line on the apportioned zero-rate category', function () {
        $apportionedId = Bundle::apportionedTaxCategoryId();

        expect($apportionedId)->not->toBeNull()
            ->and(populatedTaxLine(TaxTreatment::Multiple->value)->taxCategoryId)->toBe($apportionedId);
    });

    it('leaves a composite-supply line on the bundle’s own category', function () {
        expect(populatedTaxLine(TaxTreatment::Composite->value)->taxCategoryId)
            ->not->toBe(Bundle::apportionedTaxCategoryId());
    });

    it('never reports the apportioned category as the bundle’s own', function () {
        $bundleType = makeBundleType('Own', 'own' . StringHelper::randomString(6), TaxTreatment::Multiple->value);
        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;

        expect($bundle->getTaxCategoryId())->not->toBe(Bundle::apportionedTaxCategoryId());
    });
});

describe('Bundle::getDescription()', function () {
    /**
     * Saves a bundle type with the given description format and returns an
     * unsaved bundle of that type titled "Starter".
     */
    function bundleWithDescriptionFormat(?string $format): Bundle
    {
        $bundleType = makeBundleType('Described', 'described' . StringHelper::randomString(6));
        $bundleType->descriptionFormat = $format;
        BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($bundleType);
        resetBundleTypesCache();

        $bundle = new Bundle();
        $bundle->typeId = $bundleType->id;
        $bundle->title = 'Starter';

        return $bundle;
    }

    it('renders the bundle type description format', function () {
        expect(bundleWithDescriptionFormat('{title} (bundle)')->getDescription())->toBe('Starter (bundle)');
    });

    it('falls back to the title when no format is set', function () {
        expect(bundleWithDescriptionFormat(null)->getDescription())->toBe('Starter');
    });

    it('falls back to the title when the format fails to render', function () {
        expect(bundleWithDescriptionFormat('{% if %}')->getDescription())->toBe('Starter');
    });
});

describe('Bundle products validation', function () {
    it('rejects the same product added twice', function () {
        $bundle = new Bundle();
        $bundle->setProducts([['productId' => 1, 'qty' => 1], ['productId' => 2, 'qty' => 1], ['productId' => 1, 'qty' => 2]]);

        $bundle->validate(['products']);

        expect($bundle->getErrors('products'))->not->toBeEmpty();
    });

    it('accepts distinct products', function () {
        $bundle = new Bundle();
        $bundle->setProducts([['productId' => 1, 'qty' => 1], ['productId' => 2, 'qty' => 3]]);

        $bundle->validate(['products']);

        expect($bundle->getErrors('products'))->toBeEmpty();
    });
});

/**
 * Saves a bundle type enabled in the given sites, each with the given Default
 * Status, and the given Show the Slug field setting.
 *
 * @param int[] $siteIds
 */
function bundleTypeWithSites(array $siteIds, bool $enabledByDefault, bool $showSlugField = true): BundleType
{
    $bundleType = makeBundleType('Sites', 'sites' . StringHelper::randomString(6));
    $bundleType->showSlugField = $showSlugField;
    $bundleType->setSiteSettings(array_combine($siteIds, array_map(
        static fn(int $siteId): BundleTypeSite => new BundleTypeSite([
            'siteId' => $siteId,
            'hasUrls' => false,
            'enabledByDefault' => $enabledByDefault,
        ]),
        $siteIds,
    )));
    BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($bundleType);
    resetBundleTypesCache();

    return BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($bundleType->id);
}

describe('Bundle type Default Status', function () {
    it('passes each site’s default status to propagation', function () {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle = new Bundle();
        $bundle->typeId = bundleTypeWithSites([$primaryId], false)->id;

        expect($bundle->getSupportedSites())->toBe([['siteId' => $primaryId, 'enabledByDefault' => false]]);
    });

    it('disables a new single-site bundle when its site defaults to disabled', function () {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle = new Bundle();
        $bundle->siteId = $primaryId;
        $bundle->typeId = bundleTypeWithSites([$primaryId], false)->id;

        $bundle->applyDefaultStatus();

        expect($bundle->enabled)->toBeFalse()
            ->and($bundle->getEnabledForSite())->toBeTrue();
    });

    it('disables only the current site of a new multi-site bundle', function () {
        $siteIds = Craft::$app->getSites()->getAllSiteIds();

        if (count($siteIds) < 2) {
            $this->markTestSkipped('The test database has only one site.');
        }

        $bundle = new Bundle();
        $bundle->siteId = $siteIds[0];
        $bundle->typeId = bundleTypeWithSites($siteIds, false)->id;

        $bundle->applyDefaultStatus();

        expect($bundle->enabled)->toBeTrue()
            ->and($bundle->getEnabledForSite())->toBeFalse();
    });

    it('enables a new bundle when its site defaults to enabled', function () {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle = new Bundle();
        $bundle->siteId = $primaryId;
        $bundle->typeId = bundleTypeWithSites([$primaryId], true)->id;

        $bundle->applyDefaultStatus();

        expect($bundle->enabled)->toBeTrue()
            ->and($bundle->getEnabledForSite())->toBeTrue();
    });
});

describe('Bundle type Show the Slug field', function () {
    /**
     * Renders a bundle's editor sidebar fields for a type with the given setting.
     */
    function bundleMetaFields(bool $showSlugField): string
    {
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle = new Bundle();
        $bundle->siteId = $primaryId;
        $bundle->typeId = bundleTypeWithSites([$primaryId], true, $showSlugField)->id;
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        return (fn() => $this->metaFieldsHtml(false))->call($bundle);
    }

    it('shows the slug field when the type allows it', function () {
        expect(bundleMetaFields(true))->toContain('id="slug"');
    });

    it('hides the slug field when the type turns it off', function () {
        expect(bundleMetaFields(false))->not->toContain('id="slug"');
    });
});

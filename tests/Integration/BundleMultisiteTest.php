<?php

/**
 * Pins bundles as per-site elements: a bundle lives in the sites its type is
 * enabled for and nowhere else, a new one is started in a site its type
 * supports whatever site the control panel was showing, and components are
 * checked against the bundle's own site.
 */

use craft\commerce\elements\Product;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\models\Site;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\migrations\m261123_010000_propagate_bundles;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;

/**
 * Returns the first two sites, skipping the test on a single-site install.
 *
 * @return array{Site, Site}
 */
function twoSites(): array
{
    $sites = array_values(Craft::$app->getSites()->getAllSites());

    if (count($sites) < 2) {
        test()->markTestSkipped('Needs two sites.');
    }

    return [$sites[0], $sites[1]];
}

/**
 * Creates a bundle type enabled for the given sites only.
 *
 * @param Site[] $sites
 */
function bundleTypeForSites(array $sites): BundleType
{
    $type = makeBundleType('Site Kit', 'siteKit' . StringHelper::randomString(8));
    $siteSettings = [];

    foreach ($sites as $site) {
        $siteSettings[$site->id] = new BundleTypeSite(['siteId' => $site->id]);
    }

    $type->setSiteSettings($siteSettings);
    BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type);
    resetBundleTypesCache();

    return BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($type->id);
}

/**
 * Saves a bundle of the given type in the given site.
 */
function bundleInSite(BundleType $type, Site $site): Bundle
{
    return withFileCacheWarningsSuppressed(static function () use ($type, $site): Bundle {
        $bundle = new Bundle();
        $bundle->typeId = $type->id;
        $bundle->siteId = $site->id;
        $bundle->title = 'Site Bundle ' . StringHelper::randomString(6);
        $bundle->enabled = true;

        if (!Craft::$app->getElements()->saveElement($bundle, false)) {
            throw new RuntimeException('Could not save bundle: ' . implode(', ', $bundle->getErrorSummary(true)));
        }

        return $bundle;
    });
}

/**
 * Clears the control panel's memo of the requested site and the user's
 * editable sites, which otherwise carry over from one test to the next.
 */
function resetRequestedSite(): void
{
    (new ReflectionProperty(Cp::class, '_requestedSite'))->setValue(null, null);
    (new ReflectionProperty(Craft::$app->getSites(), '_editableSiteIds'))->setValue(Craft::$app->getSites(), null);
}

describe('A bundle on a multi-site install', function () {
    it('is saved to every site its type is enabled for', function () {
        [$first, $second] = twoSites();
        $bundle = bundleInSite(bundleTypeForSites([$first, $second]), $first);

        expect(Bundle::find()->id($bundle->id)->siteId($first->id)->status(null)->exists())->toBeTrue();
        expect(Bundle::find()->id($bundle->id)->siteId($second->id)->status(null)->exists())->toBeTrue();
    });

    it('stays out of a site its type isn’t enabled for', function () {
        [$first, $second] = twoSites();
        $bundle = bundleInSite(bundleTypeForSites([$second]), $second);

        expect(Bundle::find()->id($bundle->id)->siteId($second->id)->status(null)->exists())->toBeTrue();
        expect(Bundle::find()->id($bundle->id)->siteId($first->id)->status(null)->exists())->toBeFalse();
    });

    it('lists its type as an index source only on the type’s sites', function () {
        [, $second] = twoSites();
        Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());
        $type = bundleTypeForSites([$second]);

        $sources = (new ReflectionMethod(Bundle::class, 'defineSources'))->invoke(null, 'index');
        $source = array_values(array_filter($sources, static fn(array $source): bool => $source['key'] === 'bundleType:' . $type->uid))[0];

        expect($source['sites'])->toBe([$second->id]);
    });

    it('checks component sources against products in its own site', function () {
        [, $second] = twoSites();
        $type = bundleTypeForSites([$second]);
        $type->componentSources = ['productType:' . StringHelper::UUID()];
        BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type);
        $product = Product::find()->siteId($second->id)->status(null)->one();

        if ($product === null) {
            $this->markTestSkipped('Needs a product in the second site.');
        }

        $bundle = new Bundle(['typeId' => $type->id, 'siteId' => $second->id]);
        $bundle->setProducts([['productId' => $product->id, 'qty' => 1]]);
        $bundle->setScenario(Bundle::SCENARIO_LIVE);
        $bundle->validate(['products']);

        expect($bundle->getErrors('products'))->not->toBeEmpty();
    });
});

describe('Starting a new bundle', function () {
    beforeEach(function () {
        Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());
        Craft::$app->getRequest()->setIsCpRequest(true);
        Craft::$app->getRequest()->setAcceptableContentTypes(['application/json' => ['q' => 1]]);
        resetRequestedSite();
    });

    afterEach(function () {
        Craft::$app->getRequest()->setBodyParams([]);
        Craft::$app->getRequest()->setQueryParams([]);
        resetRequestedSite();
    });

    it('uses a site the type is enabled for when the requested one isn’t', function () {
        [$first, $second] = twoSites();
        $type = bundleTypeForSites([$second]);
        Craft::$app->getRequest()->setQueryParams(['site' => $first->handle]);
        Craft::$app->getRequest()->setBodyParams(['typeId' => $type->id]);

        (new BundlesController('bundles', BundleBuilder::getInstance()))->actionCreate();

        expect(Bundle::find()->typeId($type->id)->siteId($second->id)->drafts()->status(null)->exists())->toBeTrue();
        expect(Bundle::find()->typeId($type->id)->siteId($first->id)->drafts()->status(null)->exists())->toBeFalse();
    });

    it('uses the requested site when the type is enabled for it', function () {
        [$first, $second] = twoSites();
        $type = bundleTypeForSites([$first, $second]);
        Craft::$app->getRequest()->setQueryParams(['site' => $second->handle]);
        Craft::$app->getRequest()->setBodyParams(['typeId' => $type->id]);

        (new BundlesController('bundles', BundleBuilder::getInstance()))->actionCreate();

        $draft = Bundle::find()->typeId($type->id)->site('*')->unique()->preferSites([$second->id])->drafts()->status(null)->one();

        expect($draft?->siteId)->toBe($second->id);
    });
});

describe('Upgrading a multi-site install', function () {
    it('queues a resave so existing bundles reach their other sites', function () {
        twoSites();
        $queued = (int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count();

        expect((new m261123_010000_propagate_bundles())->safeUp())->toBeTrue();
        expect((int)(new craft\db\Query())->from(craft\db\Table::QUEUE)->count())->toBe($queued + 1);
    });
});

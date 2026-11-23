<?php

declare(strict_types=1);

use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\web\View;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * @return array{Site, Site}
 */
function bundleEditorSites(): array
{
    $sites = array_values(Craft::$app->getSites()->getAllSites());
    if (count($sites) < 2) {
        test()->markTestSkipped('The editor tests need two sites.');
    }
    return [$sites[0], $sites[1]];
}

/**
 * @param Site[] $sites
 */
function bundleEditorType(array $sites): BundleType
{
    $type = makeBundleType('Site editor', 'siteEditor' . StringHelper::randomString(8));
    $settings = [];
    foreach ($sites as $site) {
        $settings[$site->id] = new BundleTypeSite(['siteId' => $site->id]);
    }
    $type->setSiteSettings($settings);
    BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type);
    return $type;
}

function resetBundleEditorSiteCache(): void
{
    (new ReflectionProperty(Cp::class, '_requestedSite'))->setValue(null, null);
    (new ReflectionProperty(Craft::$app->getSites(), '_editableSiteIds'))->setValue(Craft::$app->getSites(), null);
}

beforeEach(function (): void {
    Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());
    Craft::$app->getRequest()->setIsCpRequest(true);
    Craft::$app->getRequest()->setAcceptableContentTypes(['application/json' => 1]);
    Craft::$app->getRequest()->setBodyParams([]);
    Craft::$app->getRequest()->setQueryParams([]);
    Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
    resetBundleEditorSiteCache();
});

afterEach(function (): void {
    Craft::$app->getRequest()->setBodyParams([]);
    Craft::$app->getRequest()->setQueryParams([]);
    resetBundleEditorSiteCache();
});

it('exposes native localization and site limited bundle type sources', function (): void {
    [$first, $second] = bundleEditorSites();
    $type = bundleEditorType([$second]);
    $bundle = new Bundle(['typeId' => $type->id, 'siteId' => $second->id]);
    $sources = (new ReflectionMethod(Bundle::class, 'defineSources'))->invoke(null, 'index');
    $source = array_values(array_filter($sources, static fn(array $source): bool => ($source['key'] ?? null) === 'bundleType:' . $type->uid))[0];

    expect(Bundle::isLocalized())->toBeTrue();
    expect($source['sites'])->toBe([$second->id]);
    expect(ElementHelper::editableSiteIdsForElement($bundle))->toBe([$second->id]);
    expect($bundle->getPostEditUrl())->toContain('site=' . $second->handle);
});

it('creates a secondary site only draft without an unsupported site exception', function (string $selection): void {
    [$first, $second] = bundleEditorSites();
    $type = bundleEditorType([$second]);
    $params = ['typeId' => $type->id];
    if ($selection === 'unsupported') {
        $params['siteId'] = $first->id;
    } elseif ($selection === 'supported') {
        $params['siteId'] = $second->id;
    }
    Craft::$app->getRequest()->setQueryParams(['site' => $first->handle]);
    Craft::$app->getRequest()->setBodyParams($params);

    $response = (new BundlesController('bundles', BundleBuilder::getInstance()))->actionCreate();
    $draft = Bundle::find()->typeId($type->id)->siteId($second->id)->drafts(true)->status(null)->one();
    expect($response)->not->toBeNull();
    expect($draft)->not->toBeNull();
    expect($draft->getCpEditUrl())->toContain('site=' . $second->handle);
    expect(Bundle::find()->typeId($type->id)->siteId($first->id)->drafts(true)->status(null)->exists())->toBeFalse();
})->with(['implicit', 'unsupported', 'supported']);

it('honours the selected site when a type supports both sites', function (): void {
    [$first, $second] = bundleEditorSites();
    $type = bundleEditorType([$first, $second]);
    Craft::$app->getRequest()->setBodyParams(['typeId' => $type->id, 'siteId' => $first->id]);
    (new BundlesController('bundles', BundleBuilder::getInstance()))->actionCreate();

    expect(Bundle::find()->typeId($type->id)->siteId($first->id)->drafts(true)->status(null)->exists())->toBeTrue();
});

it('rejects an invalid site before creating a draft', function (mixed $siteId): void {
    [$first, $second] = bundleEditorSites();
    $type = bundleEditorType([$second]);
    Craft::$app->getRequest()->setBodyParams(['typeId' => $type->id, 'siteId' => $siteId]);
    $controller = new BundlesController('bundles', BundleBuilder::getInstance());
    expect(fn() => $controller->actionCreate())->toThrow(BadRequestHttpException::class, 'Invalid site ID.');
    expect(Bundle::find()->typeId($type->id)->siteId('*')->drafts(true)->status(null)->exists())->toBeFalse();
})->with(['invalid', 0, PHP_INT_MAX]);

it('refuses creation when the user has no access to a supported site', function (): void {
    [$first, $second] = bundleEditorSites();
    $type = bundleEditorType([$second]);
    $admin = Craft::$app->getUser()->getIdentity();
    $user = new class extends User {
        public array $allowedPermissions = [];

        public function can(string $permission): bool
        {
            return in_array($permission, $this->allowedPermissions, true);
        }
    };
    $user->id = $admin->id;
    $user->allowedPermissions = ['editSite:' . $first->uid, BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $type->uid];
    Craft::$app->getUser()->setIdentity($user);
    resetBundleEditorSiteCache();
    Craft::$app->getRequest()->setBodyParams(['typeId' => $type->id]);
    $controller = new BundlesController('bundles', BundleBuilder::getInstance());

    expect(fn() => $controller->actionCreate())->toThrow(ForbiddenHttpException::class, 'User not authorized to manage this bundle type in any sites.');
    expect(Bundle::find()->typeId($type->id)->siteId('*')->drafts(true)->status(null)->exists())->toBeFalse();
});

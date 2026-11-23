<?php

declare(strict_types=1);

use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\services\ProjectConfig;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;
use johnhenry\bundlebuilder\records\BundleTypeRecord;
use johnhenry\bundlebuilder\services\BundleTypes;

it('exports settings, layouts and sites using stable UIDs', function (): void {
    $type = makeBundleType();
    $site = Craft::$app->getSites()->getPrimarySite();
    $type->skuFormat = '{slug}';
    $type->descriptionFormat = '{title}';
    $type->showSlugField = false;
    $type->enableVersioning = true;
    $type->componentSources = ['productType:' . StringHelper::UUID()];
    $type->previewTargets = [['label' => 'Preview', 'urlFormat' => '{url}']];
    $type->setSiteSettings([$site->id => new BundleTypeSite([
        'siteId' => $site->id,
        'hasUrls' => true,
        'uriFormat' => 'bundles/{slug}',
        'template' => 'bundles/_entry',
        'enabledByDefault' => false,
    ])]);
    $service = BundleBuilder::getInstance()->getBundleTypes();
    $layoutId = $type->fieldLayoutId;
    $layoutUid = Db::uidById('{{%fieldlayouts}}', $layoutId);
    $type->setFieldLayout(new FieldLayout(['type' => Bundle::class]));

    expect($service->saveBundleType($type))->toBeTrue();
    $config = Craft::$app->getProjectConfig()->get(BundleTypes::CONFIG_BUNDLE_TYPES . '.' . $type->uid);
    expect($config)->not->toHaveKeys(['id', 'fieldLayoutId', 'uid']);
    expect(array_keys($config['fieldLayouts']))->toBe([$layoutUid]);
    expect(array_keys($config['siteSettings']))->toBe([$site->uid]);
    expect($type->fieldLayoutId)->toBe($layoutId);
    expect($config['siteSettings'][$site->uid]['enabledByDefault'])->toBeFalse();
    $loaded = $service->getBundleTypeById($type->id);
    expect($loaded->skuFormat)->toBe('{slug}');
    expect($loaded->descriptionFormat)->toBe('{title}');
    expect($loaded->showSlugField)->toBeFalse();
    expect($loaded->enableVersioning)->toBeTrue();
    expect($loaded->componentSources)->toBe($type->componentSources);
    expect($loaded->previewTargets)->toBe($type->previewTargets);
});

it('applies UID based definitions with local IDs and updates existing types', function (): void {
    $projectConfig = Craft::$app->getProjectConfig();
    $service = BundleBuilder::getInstance()->getBundleTypes();
    $type = new BundleType(['name' => 'Deployed Kit', 'handle' => 'deployedKit']);
    $uid = StringHelper::UUID();
    $path = BundleTypes::CONFIG_BUNDLE_TYPES . '.' . $uid;
    $config = $type->getConfig();
    $projectConfig->set($path, $config);
    $loaded = $service->getBundleTypeByHandle('deployedKit');
    $id = $loaded->id;
    expect($loaded->uid)->toBe($uid);
    expect($loaded->getSiteSettings())->toHaveKey(Craft::$app->getSites()->getPrimarySite()->id);

    $config['name'] = 'Updated Kit';
    $config['fieldLayouts'] = [];
    $projectConfig->set($path, $config);
    $updated = $service->getBundleTypeById($id);
    expect($updated->name)->toBe('Updated Kit');
    expect($updated->fieldLayoutId)->toBeNull();
    expect($updated->uid)->toBe($uid);
});

it('removes database state when config is removed and tolerates repeated deletion', function (): void {
    $type = makeBundleType();
    $service = BundleBuilder::getInstance()->getBundleTypes();
    $projectConfig = Craft::$app->getProjectConfig();
    $path = BundleTypes::CONFIG_BUNDLE_TYPES . '.' . $type->uid;
    $projectConfig->remove($path);

    expect($service->getBundleTypeById($type->id))->toBeNull();
    expect(BundleTypeRecord::findOne(['uid' => $type->uid]))->toBeNull();
    expect($service->getBundleTypeSites($type->id))->toBeEmpty();
    $service->handleDeletedBundleType(new ConfigEvent(['tokenMatches' => [$type->uid]]));
});

it('includes database definitions in project config rebuilds', function (): void {
    $type = makeBundleType();
    $event = new RebuildConfigEvent(['config' => []]);
    Craft::$app->getProjectConfig()->trigger(ProjectConfig::EVENT_REBUILD, $event);

    expect($event->config['bundleBuilder']['bundleTypes'][$type->uid])->toBe($type->getConfig());
});

it('does not write invalid types to config', function (): void {
    $type = new BundleType(['handle' => 'invalidKit']);
    expect(BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type))->toBeFalse();
    expect($type->id)->toBeNull();
    expect(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeByHandle('invalidKit'))->toBeNull();
});

it('applies incoming definitions while administrative changes are disabled', function (): void {
    $projectConfig = Craft::$app->getProjectConfig();
    $generalConfig = Craft::$app->getConfig()->getGeneral();
    $allowAdminChanges = $generalConfig->allowAdminChanges;
    $uid = StringHelper::UUID();
    $incoming = $projectConfig->get();
    $incoming['bundleBuilder']['bundleTypes'][$uid] = (new BundleType([
        'name' => 'Production Kit',
        'handle' => 'productionKit',
    ]))->getConfig();
    $generalConfig->allowAdminChanges = false;
    $projectConfig->readOnly = true;

    try {
        $projectConfig->applyConfigChanges($incoming);
        $type = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeByHandle('productionKit');
        expect($type->uid)->toBe($uid);
        expect($projectConfig->readOnly)->toBeTrue();
        expect($generalConfig->allowAdminChanges)->toBeFalse();
    } finally {
        $projectConfig->readOnly = false;
        $generalConfig->allowAdminChanges = $allowAdminChanges;
    }
});

it('prunes deleted site references from type configuration', function (): void {
    $type = makeBundleType();
    $site = Craft::$app->getSites()->getPrimarySite();
    $service = BundleBuilder::getInstance()->getBundleTypes();
    $service->pruneDeletedSite(new ConfigEvent(['tokenMatches' => [$site->uid]]));

    expect(Craft::$app->getProjectConfig()->get(BundleTypes::CONFIG_BUNDLE_TYPES . '.' . $type->uid . '.siteSettings'))->toBeEmpty();
});

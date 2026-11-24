<?php

/**
 * Pins bundle types to Project Config: what a saved type writes there, that a
 * definition arriving from another environment is applied (taking over a type
 * with the same handle rather than colliding with it), that removing one
 * removes its bundles, and that the screens are read-only without
 * allowAdminChanges.
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\events\ConfigEvent;
use craft\events\RebuildConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\models\ReadOnlyProjectConfigData;
use craft\services\ProjectConfig;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\controllers\BundleTypesController;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\migrations\m261123_000000_bundle_types_project_config;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;
use johnhenry\bundlebuilder\records\BundleTypeRecord;
use johnhenry\bundlebuilder\services\BundleTypes;
use yii\web\ForbiddenHttpException;

/**
 * Makes Project Config read the given schema version for the plugin from its
 * YAML, whatever the project's own files say.
 */
function pretendYamlSchemaVersion(string $schemaVersion): void
{
    $projectConfig = Craft::$app->getProjectConfig();
    $external = new ReflectionProperty($projectConfig, '_externalConfig');
    $external->setValue($projectConfig, new ReadOnlyProjectConfigData([
        'plugins' => ['bundle-builder' => ['schemaVersion' => $schemaVersion]],
    ]));
}

describe('A saved bundle type', function () {
    it('is written to Project Config under its UID, with sites and layout by UID', function () {
        $type = makeBundleType('Config Kit', 'configKit');
        $site = Craft::$app->getSites()->getPrimarySite();

        $type->skuFormat = '{slug}';
        $type->enableVersioning = true;
        $type->previewTargets = [['label' => 'Preview', 'urlFormat' => '{url}']];
        $type->setSiteSettings([$site->id => new BundleTypeSite([
            'siteId' => $site->id,
            'hasUrls' => true,
            'uriFormat' => 'bundles/{slug}',
            'template' => 'bundles/_entry',
            'enabledByDefault' => false,
        ])]);

        expect(BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type))->toBeTrue();

        $config = Craft::$app->getProjectConfig()->get(BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $type->uid);

        expect($config)->not->toHaveKeys(['id', 'uid', 'fieldLayoutId']);
        expect($config['handle'])->toBe('configKit');
        expect($config['skuFormat'])->toBe('{slug}');
        expect($config['enableVersioning'])->toBeTrue();
        expect(array_keys($config['siteSettings']))->toBe([$site->uid]);
        expect($config['siteSettings'][$site->uid]['uriFormat'])->toBe('bundles/{slug}');
        expect($config['siteSettings'][$site->uid]['enabledByDefault'])->toBeFalse();
        expect(array_keys($config['fieldLayouts']))->toBe([Db::uidById(Table::FIELDLAYOUTS, $type->fieldLayoutId)]);
    });

    it('reads back what was saved', function () {
        $type = makeBundleType('Config Kit', 'configKit');
        $type->componentSources = ['productType:' . StringHelper::UUID()];
        $type->previewTargets = [['label' => 'Preview', 'urlFormat' => '{url}']];
        BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type);
        resetBundleTypesCache();

        $loaded = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($type->id);

        expect($loaded->componentSources)->toBe($type->componentSources);
        expect($loaded->previewTargets)->toBe($type->previewTargets);
        expect($loaded->fieldLayoutId)->toBe($type->fieldLayoutId);
    });

    it('keeps an invalid type out of Project Config', function () {
        $type = new BundleType(['handle' => 'noNameKit']);

        expect(BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type))->toBeFalse();
        expect($type->uid)->toBeNull();
    });
});

describe('A bundle type arriving in Project Config', function () {
    it('is created with the UID it came with', function () {
        $uid = StringHelper::UUID();
        $config = (new BundleType(['name' => 'Deployed Kit', 'handle' => 'deployedKit']))->getConfig();

        Craft::$app->getProjectConfig()->set(BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $uid, $config);
        resetBundleTypesCache();

        $loaded = BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeByHandle('deployedKit');

        expect($loaded?->uid)->toBe($uid);
        expect($loaded->getSiteSettings())->toHaveKey(Craft::$app->getSites()->getPrimarySite()->id);
    });

    it('takes over a type with the same handle, keeping its bundles and permission', function () {
        $type = makeBundleType('Local Kit', 'sharedKit');
        $bundle = makeSavedBundle($type, [['product' => makeProduct(10.0), 'qty' => 1]]);
        $oldPermission = strtolower(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $type->uid);
        Craft::$app->getDb()->createCommand()->insert(Table::USERPERMISSIONS, ['name' => $oldPermission])->execute();

        $incomingUid = StringHelper::UUID();
        $config = $type->getConfig();
        $config['name'] = 'Deployed Kit';

        BundleBuilder::getInstance()->getBundleTypes()->handleChangedBundleType(new ConfigEvent([
            'tokenMatches' => [$incomingUid],
            'newValue' => $config,
        ]));

        $records = BundleTypeRecord::findAll(['handle' => 'sharedKit']);

        expect($records)->toHaveCount(1);
        expect($records[0]->id)->toBe($type->id);
        expect($records[0]->uid)->toBe($incomingUid);
        expect($records[0]->name)->toBe('Deployed Kit');
        expect(Bundle::find()->id($bundle->id)->status(null)->one()?->typeId)->toBe($type->id);

        $newPermission = strtolower(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $incomingUid);
        $names = (new Query())->select('name')->from(Table::USERPERMISSIONS)->where(['name' => [$oldPermission, $newPermission]])->column();

        expect($names)->toBe([$newPermission]);
    });

    it('queues a resave of the type’s bundles when a site is dropped from it', function () {
        $sites = Craft::$app->getSites()->getAllSites();

        if (count($sites) < 2) {
            $this->markTestSkipped('Needs two sites.');
        }

        $type = makeBundleType('Site Kit', 'siteKit');
        $config = $type->getConfig();
        unset($config['siteSettings'][end($sites)->uid]);
        $queued = (new Query())->from(Table::QUEUE)->count();

        Craft::$app->getProjectConfig()->set(BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $type->uid, $config);

        expect(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeSites($type->id))->toHaveCount(count($sites) - 1);
        expect((int)(new Query())->from(Table::QUEUE)->count())->toBe((int)$queued + 1);
    });
});

describe('A bundle type removed from Project Config', function () {
    it('is deleted along with its bundles', function () {
        $type = makeBundleType('Doomed Kit', 'doomedKit');
        $bundle = makeSavedBundle($type, [['product' => makeProduct(10.0), 'qty' => 1]]);

        expect(BundleBuilder::getInstance()->getBundleTypes()->deleteBundleTypeById($type->id))->toBeTrue();
        resetBundleTypesCache();

        expect(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeById($type->id))->toBeNull();
        expect(Craft::$app->getProjectConfig()->get(BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $type->uid))->toBeNull();
        expect(Bundle::find()->id($bundle->id)->site('*')->status(null)->exists())->toBeFalse();
    });

    it('ignores a UID it has no type for', function () {
        BundleBuilder::getInstance()->getBundleTypes()->handleDeletedBundleType(new ConfigEvent([
            'tokenMatches' => [StringHelper::UUID()],
        ]));
    })->throwsNoExceptions();
});

describe('Project Config upkeep', function () {
    it('includes every bundle type in a rebuild', function () {
        $type = makeBundleType('Rebuild Kit', 'rebuildKit');
        $event = new RebuildConfigEvent(['config' => []]);

        Craft::$app->getProjectConfig()->trigger(ProjectConfig::EVENT_REBUILD, $event);

        expect($event->config['bundle-builder']['bundleTypes'][$type->uid]['handle'])->toBe('rebuildKit');
    });

    it('writes types that predate Project Config to it on upgrade, without touching them', function () {
        $record = new BundleTypeRecord();
        $record->name = 'Legacy Kit';
        $record->handle = 'legacyKit';
        $record->save(false);
        resetBundleTypesCache();
        pretendYamlSchemaVersion('1.0.6');

        expect((new m261123_000000_bundle_types_project_config())->safeUp())->toBeTrue();

        $path = BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $record->uid;

        expect(Craft::$app->getProjectConfig()->get($path . '.handle'))->toBe('legacyKit');
        expect(BundleTypeRecord::findOne(['handle' => 'legacyKit'])->id)->toBe($record->id);
    });

    it('leaves Project Config alone when the YAML is already on the new schema', function () {
        $record = new BundleTypeRecord();
        $record->name = 'Legacy Kit';
        $record->handle = 'legacyKit';
        $record->save(false);
        resetBundleTypesCache();
        pretendYamlSchemaVersion('1.0.7');

        expect((new m261123_000000_bundle_types_project_config())->safeUp())->toBeTrue();
        expect(Craft::$app->getProjectConfig()->get(BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $record->uid))->toBeNull();
    });
});

describe('Bundle type screens without allowAdminChanges', function () {
    beforeEach(function () {
        Craft::$app->getUser()->setIdentity(User::find()->admin(true)->one());
        $this->general = Craft::$app->getConfig()->getGeneral();
        $this->allowAdminChanges = $this->general->allowAdminChanges;
        $this->isCpRequest = Craft::$app->getRequest()->getIsCpRequest();
        $this->general->allowAdminChanges = false;
        Craft::$app->getRequest()->setIsCpRequest(true);
    });

    afterEach(function () {
        $this->general->allowAdminChanges = $this->allowAdminChanges;
        Craft::$app->getRequest()->setIsCpRequest($this->isCpRequest);
    });

    it('refuses to save, delete or start a new type', function (string $action) {
        $controller = new BundleTypesController('bundle-types', BundleBuilder::getInstance());

        expect(fn() => $controller->runAction($action))->toThrow(ForbiddenHttpException::class);
    })->with(['save', 'delete', 'edit']);

    it('still lists Bundle Types in the navigation', function () {
        expect(BundleBuilder::getInstance()->getCpNavItem()['subnav'])->toHaveKey('bundle-types');
    });
});

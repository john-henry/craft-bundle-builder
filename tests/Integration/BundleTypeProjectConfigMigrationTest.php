<?php

declare(strict_types=1);

use craft\models\ReadOnlyProjectConfigData;
use johnhenry\bundlebuilder\migrations\m261123_150000_bundle_type_project_config;
use johnhenry\bundlebuilder\records\BundleTypeRecord;
use johnhenry\bundlebuilder\services\BundleTypes;

it('exports legacy definitions once without changing their identities', function (): void {
    $record = new BundleTypeRecord();
    $record->name = 'Legacy Kit';
    $record->handle = 'legacyKit';
    $record->save(false);
    resetBundleTypesCache();
    $projectConfig = Craft::$app->getProjectConfig();
    $external = new ReflectionProperty($projectConfig, '_externalConfig');
    $external->setValue($projectConfig, new ReadOnlyProjectConfigData([
        'plugins' => ['bundle-builder' => ['schemaVersion' => '1.0.6']],
    ]));

    $migration = new m261123_150000_bundle_type_project_config();
    expect($migration->safeUp())->toBeTrue();
    expect($projectConfig->get(BundleTypes::CONFIG_BUNDLE_TYPES . '.' . $record->uid . '.handle'))->toBe('legacyKit');
    expect(BundleTypeRecord::findOne(['uid' => $record->uid])->id)->toBe($record->id);
    expect($migration->safeUp())->toBeTrue();
});

it('leaves incoming config untouched during deployment migrations', function (): void {
    $record = new BundleTypeRecord();
    $record->name = 'Local Legacy Kit';
    $record->handle = 'localLegacyKit';
    $record->save(false);
    resetBundleTypesCache();
    $projectConfig = Craft::$app->getProjectConfig();
    $before = $projectConfig->get(BundleTypes::CONFIG_BUNDLE_TYPES);
    $external = new ReflectionProperty($projectConfig, '_externalConfig');
    $external->setValue($projectConfig, new ReadOnlyProjectConfigData([
        'plugins' => ['bundle-builder' => ['schemaVersion' => '1.0.7']],
        'bundleBuilder' => ['bundleTypes' => ['incoming' => ['name' => 'Incoming Definition']]],
    ]));
    $projectConfig->readOnly = true;

    try {
        expect((new m261123_150000_bundle_type_project_config())->safeUp())->toBeTrue();
        expect($projectConfig->get(BundleTypes::CONFIG_BUNDLE_TYPES))->toBe($before);
        expect($projectConfig->get('bundleBuilder.bundleTypes.incoming.name', true))->toBe('Incoming Definition');
    } finally {
        $projectConfig->readOnly = false;
    }
});

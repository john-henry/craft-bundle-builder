<?php

declare(strict_types=1);

namespace johnhenry\bundlebuilder\migrations;

use Craft;
use craft\db\Migration;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\services\BundleTypes;

class m261123_150000_bundle_type_project_config extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.bundle-builder.schemaVersion', true);
        if (version_compare($schemaVersion ?? '0', '1.0.7', '>=')) {
            return true;
        }

        foreach (BundleBuilder::$plugin->getBundleTypes()->getAllBundleTypes() as $bundleType) {
            $projectConfig->set(BundleTypes::CONFIG_BUNDLE_TYPES . '.' . $bundleType->uid, $bundleType->getConfig());
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "Bundle type project config cannot be reverted.\n";
        return false;
    }
}

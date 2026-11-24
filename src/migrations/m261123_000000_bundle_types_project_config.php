<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use Craft;
use craft\db\Migration;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\services\BundleTypes;
use Throwable;

/**
 * Writes the existing bundle types to Project Config.
 *
 * The definitions are written once, on the environment that first runs this
 * migration; other environments pick them up from the YAML. The database
 * already matches, so the config handlers stay quiet while they're written.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.3.0
 */
class m261123_000000_bundle_types_project_config extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @throws Throwable if Project Config can't be updated.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $schemaVersion = $projectConfig->get('plugins.bundle-builder.schemaVersion', true);

        if ($schemaVersion !== null && version_compare($schemaVersion, '1.0.7', '>=')) {
            return true;
        }

        $bundleTypes = BundleBuilder::getInstance()->getBundleTypes()->getAllBundleTypes();
        $muteEvents = $projectConfig->muteEvents;
        $projectConfig->muteEvents = true;

        try {
            foreach ($bundleTypes as $bundleType) {
                $projectConfig->set(BundleTypes::CONFIG_BUNDLE_TYPES_KEY . '.' . $bundleType->uid, $bundleType->getConfig());
            }
        } finally {
            $projectConfig->muteEvents = $muteEvents;
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the definitions stay in Project Config.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function safeDown(): bool
    {
        echo "m261123_000000_bundle_types_project_config cannot be reverted.\n";

        return false;
    }
}

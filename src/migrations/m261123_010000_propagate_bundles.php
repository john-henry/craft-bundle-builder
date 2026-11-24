<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use Craft;
use craft\db\Migration;
use johnhenry\bundlebuilder\BundleBuilder;

/**
 * Queues a resave of every bundle on a multi-site install, so each bundle
 * exists in all the sites its type is enabled for.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.3.0
 */
class m261123_010000_propagate_bundles extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function safeUp(): bool
    {
        if (!Craft::$app->getIsMultiSite()) {
            return true;
        }

        BundleBuilder::getInstance()->getBundleTypes()->resaveBundles();

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always true; there is nothing to undo.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function safeDown(): bool
    {
        return true;
    }
}

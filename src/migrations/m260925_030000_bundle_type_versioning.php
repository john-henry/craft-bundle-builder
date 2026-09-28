<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use craft\db\Migration;

/**
 * Adds the opt-in Enable versioning setting to bundle types, off by default.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260925_030000_bundle_type_versioning extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%bundlebuilder_bundletypes}}', 'enableVersioning')) {
            $this->addColumn(
                '{{%bundlebuilder_bundletypes}}',
                'enableVersioning',
                $this->boolean()->notNull()->defaultValue(false)->after('showSlugField'),
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration reverted successfully.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeDown(): bool
    {
        $this->dropColumn('{{%bundlebuilder_bundletypes}}', 'enableVersioning');

        return true;
    }
}

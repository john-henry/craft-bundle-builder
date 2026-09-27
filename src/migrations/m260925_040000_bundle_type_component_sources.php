<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use craft\db\Migration;

/**
 * Adds the Component Sources setting to bundle types. Existing types keep
 * picking from all product sources.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260925_040000_bundle_type_component_sources extends Migration
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
        if (!$this->db->columnExists('{{%bundlebuilder_bundletypes}}', 'componentSources')) {
            $this->addColumn(
                '{{%bundlebuilder_bundletypes}}',
                'componentSources',
                $this->text()->after('enableVersioning'),
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
        $this->dropColumn('{{%bundlebuilder_bundletypes}}', 'componentSources');

        return true;
    }
}

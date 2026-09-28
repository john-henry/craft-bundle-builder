<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use craft\db\Migration;

/**
 * Lets each bundle component limit which of its product's variants the
 * customer can choose. Existing components keep offering every variant.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260926_000000_component_variants extends Migration
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
        if (!$this->db->columnExists('{{%bundlebuilder_products}}', 'variantIds')) {
            $this->addColumn(
                '{{%bundlebuilder_products}}',
                'variantIds',
                $this->text()->after('qty'),
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
        $this->dropColumn('{{%bundlebuilder_products}}', 'variantIds');

        return true;
    }
}

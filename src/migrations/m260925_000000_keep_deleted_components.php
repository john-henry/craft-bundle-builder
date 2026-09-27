<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use craft\db\Migration;

/**
 * Drops the cascading foreign key from bundle components to products.
 *
 * With it, a product hard-deleted by garbage collection took its component
 * rows with it, and the bundle went back on sale one item short. Without it,
 * the row stays and the bundle shows the component as missing.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260925_000000_keep_deleted_components extends Migration
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
        $this->dropForeignKeyIfExists('{{%bundlebuilder_products}}', ['productId']);

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the foreign key isn't restored.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeDown(): bool
    {
        echo "m260925_000000_keep_deleted_components cannot be reverted.\n";

        return false;
    }
}

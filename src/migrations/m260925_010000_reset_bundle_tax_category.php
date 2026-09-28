<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\commerce\Plugin as Commerce;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;
use johnhenry\bundlebuilder\elements\Bundle;
use yii\base\InvalidConfigException;

/**
 * Moves bundles off the zero-rate apportioned tax category.
 *
 * Multiple-supply bundles used to save that category as their own, so a type
 * switched back to composite left its bundles untaxed. The category now goes
 * on the line item only. Bundles have no tax category field of their own, so
 * the store default is what they'd otherwise have.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class m260925_010000_reset_bundle_tax_category extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return bool Whether the migration applied successfully.
     * @throws InvalidConfigException if the default tax category can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeUp(): bool
    {
        $apportionedId = (new Query())
            ->select(['id'])
            ->from(CommerceTable::TAXCATEGORIES)
            ->where(['handle' => 'bundleBuilderApportioned'])
            ->scalar($this->db);

        if (!$apportionedId) {
            return true;
        }

        $defaultId = Commerce::getInstance()->getTaxCategories()->getDefaultTaxCategory()->id;

        if ((int)$defaultId === (int)$apportionedId) {
            return true;
        }

        $bundleIds = (new Query())
            ->select(['id'])
            ->from(Table::ELEMENTS)
            ->where(['type' => Bundle::class]);

        $this->update(
            CommerceTable::PURCHASABLES,
            ['taxCategoryId' => $defaultId],
            ['and', ['taxCategoryId' => $apportionedId], ['id' => $bundleIds]],
        );

        return true;
    }

    /**
     * @inheritdoc
     *
     * @return bool Always false; the old category isn't restored.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function safeDown(): bool
    {
        echo "m260925_010000_reset_bundle_tax_category cannot be reverted.\n";

        return false;
    }
}

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use Craft;
use craft\commerce\models\TaxCategory;
use craft\commerce\Plugin as Commerce;
use craft\db\Migration;
use johnhenry\bundlebuilder\elements\Bundle;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\Exception;

/**
 * Install migration.
 *
 * Creates the bundle, bundle type, bundle type site, and bundle product tables,
 * along with their indexes and foreign keys, and ensures the zero-rate tax
 * category used by apportioned ("multiple supply") bundles exists. Bundle
 * elements store their purchasable-specific columns in {{%bundlebuilder_bundles}};
 * the products that make up each bundle live in the {{%bundlebuilder_products}}
 * pivot.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class Install extends Migration
{
    // Constants
    // =========================================================================

    /**
     * @var string The handle of the zero-rate tax category used for apportioned bundles.
     */
    public const APPORTIONED_TAX_CATEGORY_HANDLE = 'bundleBuilderApportioned';

    // Public Methods
    // =========================================================================

    /**
     * Creates the plugin's tables and supporting tax category.
     *
     * @return bool Whether the migration applied successfully.
     * @throws Throwable if the tax category can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        // Indexes and foreign keys are only added to tables created on this run,
        // so re-running the install over existing tables is a no-op.
        $created = $this->_createTables();

        if (!empty($created)) {
            $this->_createIndexes($created);
            $this->_addForeignKeys($created);
            Craft::$app->getDb()->getSchema()->refresh();
        }

        $this->_ensureApportionedTaxCategory();

        return true;
    }

    /**
     * Removes the bundle elements, their field layouts, the apportioned tax
     * category, and the plugin's tables.
     *
     * Craft's uninstall doesn't delete a plugin's elements or field layouts.
     * Elements go first, while the foreign keys still cascade to Commerce's
     * purchasable rows. Line items keep their snapshot, so past orders are intact.
     *
     * @return bool Whether the migration reverted successfully.
     * @throws Exception|InvalidConfigException if a delete or drop statement fails.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    public function safeDown(): bool
    {
        $this->delete('{{%elements}}', ['type' => Bundle::class]);
        $this->delete('{{%fieldlayouts}}', ['type' => Bundle::class]);
        $this->_deleteApportionedTaxCategory();

        $this->dropTableIfExists('{{%bundlebuilder_products}}');
        $this->dropTableIfExists('{{%bundlebuilder_bundletypes_sites}}');
        $this->dropTableIfExists('{{%bundlebuilder_bundles}}');
        $this->dropTableIfExists('{{%bundlebuilder_bundletypes}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Ensures a zero-rate "Bundle (apportioned)" tax category exists, used by
     * multiple-supply bundles so Commerce's core tax adjuster leaves their line
     * untaxed while this plugin's adjuster applies apportioned tax.
     *
     * @return void
     * @throws Throwable if the tax category can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _ensureApportionedTaxCategory(): void
    {
        $commerce = Commerce::getInstance();

        if (!$commerce) {
            return;
        }

        $service = $commerce->getTaxCategories();

        if ($service->getTaxCategoryByHandle(self::APPORTIONED_TAX_CATEGORY_HANDLE)) {
            return;
        }

        $taxCategory = new TaxCategory([
            'name' => 'Bundle (apportioned)',
            'handle' => self::APPORTIONED_TAX_CATEGORY_HANDLE,
            'description' => 'Zero-rate category for multiple-supply bundles; tax is apportioned across components by Bundle Builder.',
        ]);

        // A failure here shouldn't abort the install; the category can be added in the CP.
        try {
            $service->saveTaxCategory($taxCategory);
        } catch (Throwable $e) {
            Craft::warning('Could not create the apportioned tax category: ' . $e->getMessage(), 'bundle-builder');
        }
    }

    /**
     * Deletes the apportioned tax category, if it still exists. Failures are
     * logged rather than aborting the uninstall.
     *
     * @return void
     * @throws InvalidConfigException
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.1.0
     */
    private function _deleteApportionedTaxCategory(): void
    {
        $commerce = Commerce::getInstance();

        if (!$commerce) {
            return;
        }

        $service = $commerce->getTaxCategories();
        $taxCategory = $service->getTaxCategoryByHandle(self::APPORTIONED_TAX_CATEGORY_HANDLE);

        if (!$taxCategory) {
            return;
        }

        try {
            $service->deleteTaxCategoryById($taxCategory->id);
        } catch (Throwable $e) {
            Craft::warning('Could not delete the apportioned tax category on uninstall: ' . $e->getMessage(), 'bundle-builder');
        }
    }

    /**
     * Creates any of the plugin's tables that don't exist yet.
     *
     * @return string[] The tables created on this run.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _createTables(): array
    {
        $created = [];

        $this->_createTableIfMissing($created, '{{%bundlebuilder_bundletypes}}', [
            'id' => $this->primaryKey(),
            'fieldLayoutId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'skuFormat' => $this->string(),
            'descriptionFormat' => $this->string(),
            'showSlugField' => $this->boolean()->notNull()->defaultValue(true),
            'enableVersioning' => $this->boolean()->notNull()->defaultValue(false),
            'componentSources' => $this->text(),
            'taxTreatment' => $this->string()->notNull()->defaultValue('composite'),
            'previewTargets' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->_createTableIfMissing($created, '{{%bundlebuilder_bundletypes_sites}}', [
            'id' => $this->primaryKey(),
            'bundleTypeId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'uriFormat' => $this->text(),
            'template' => $this->string(500),
            'hasUrls' => $this->boolean()->notNull()->defaultValue(false),
            'enabledByDefault' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Bundle-specific columns only; Commerce's purchasable tables hold the rest.
        $this->_createTableIfMissing($created, '{{%bundlebuilder_bundles}}', [
            'id' => $this->integer()->notNull(),
            'typeId' => $this->integer()->notNull(),
            'pricingStrategy' => $this->string()->notNull()->defaultValue('fixed'),
            'discountType' => $this->string(),
            'discountAmount' => $this->decimal(14, 4),
            'postDate' => $this->dateTime(),
            'expiryDate' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->_createTableIfMissing($created, '{{%bundlebuilder_products}}', [
            'id' => $this->primaryKey(),
            'bundleId' => $this->integer()->notNull(),
            'productId' => $this->integer()->notNull(),
            'qty' => $this->integer()->unsigned()->notNull()->defaultValue(1),
            'variantIds' => $this->text(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return $created;
    }

    /**
     * Creates a table unless it already exists, recording it in `$created`.
     *
     * @param string[] $created The tables created so far on this run.
     * @param string $table The table name.
     * @param array<int|string, mixed> $columns The column definitions.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _createTableIfMissing(array &$created, string $table, array $columns): void
    {
        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, $columns);
        $created[] = $table;
    }

    /**
     * Creates the indexes for the given newly created tables.
     *
     * @param string[] $tables The tables created on this run.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _createIndexes(array $tables): void
    {
        $indexes = [
            '{{%bundlebuilder_bundletypes}}' => [[['handle'], true], [['fieldLayoutId'], false]],
            '{{%bundlebuilder_bundletypes_sites}}' => [[['bundleTypeId', 'siteId'], true], [['siteId'], false]],
            '{{%bundlebuilder_bundles}}' => [[['typeId'], false], [['postDate'], false], [['expiryDate'], false]],
            '{{%bundlebuilder_products}}' => [[['bundleId'], false], [['productId'], false]],
        ];

        foreach ($tables as $table) {
            foreach ($indexes[$table] ?? [] as [$columns, $unique]) {
                $this->createIndex(null, $table, $columns, $unique);
            }
        }
    }

    /**
     * Adds the foreign keys for the given newly created tables.
     *
     * @param string[] $tables The tables created on this run.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _addForeignKeys(array $tables): void
    {
        $foreignKeys = [
            '{{%bundlebuilder_bundletypes}}' => [
                [['fieldLayoutId'], '{{%fieldlayouts}}', 'SET NULL', null],
            ],
            '{{%bundlebuilder_bundletypes_sites}}' => [
                [['bundleTypeId'], '{{%bundlebuilder_bundletypes}}', 'CASCADE', null],
                [['siteId'], '{{%sites}}', 'CASCADE', 'CASCADE'],
            ],
            '{{%bundlebuilder_bundles}}' => [
                [['id'], '{{%elements}}', 'CASCADE', null],
                [['typeId'], '{{%bundlebuilder_bundletypes}}', 'CASCADE', null],
            ],
            // No foreign key on productId: a deleted product has to leave its
            // row behind, so the bundle shows it as missing rather than
            // quietly selling without it.
            '{{%bundlebuilder_products}}' => [
                [['bundleId'], '{{%bundlebuilder_bundles}}', 'CASCADE', null],
            ],
        ];

        foreach ($tables as $table) {
            foreach ($foreignKeys[$table] ?? [] as [$columns, $refTable, $delete, $update]) {
                $this->addForeignKey(null, $table, $columns, $refTable, ['id'], $delete, $update);
            }
        }
    }
}

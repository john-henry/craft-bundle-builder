<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\migrations;

use Craft;
use craft\commerce\models\TaxCategory;
use craft\commerce\Plugin as Commerce;
use craft\db\Migration;
use Throwable;

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
 * @author JohnHenry <info@johnhenry.ie>
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
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeUp(): bool
    {
        if ($this->_createTables()) {
            $this->_createIndexes();
            $this->_addForeignKeys();
            Craft::$app->getDb()->getSchema()->refresh();
        }

        $this->_ensureApportionedTaxCategory();

        return true;
    }

    /**
     * Drops the plugin's tables.
     *
     * @return bool Whether the migration reverted successfully.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function safeDown(): bool
    {
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
     * untaxed while this plugin's adjuster applies apportioned VAT.
     *
     * @return void
     * @throws Throwable if the tax category can't be saved.
     * @author JohnHenry <info@johnhenry.ie>
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
            'description' => 'Zero-rate category for multiple-supply bundles; VAT is apportioned across components by Bundle Builder.',
        ]);

        // Don't let a tax-category failure abort the whole install — the tables
        // are already created and the category can be created later in the CP.
        try {
            $service->saveTaxCategory($taxCategory);
        } catch (Throwable $e) {
            Craft::warning('Could not create the apportioned tax category: ' . $e->getMessage(), 'bundle-builder');
        }
    }

    /**
     * Creates the plugin's tables.
     *
     * @return bool Whether the tables were created.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _createTables(): bool
    {
        $this->createTable('{{%bundlebuilder_bundletypes}}', [
            'id' => $this->primaryKey(),
            'fieldLayoutId' => $this->integer(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'skuFormat' => $this->string(),
            'descriptionFormat' => $this->string(),
            'showSlugField' => $this->boolean()->notNull()->defaultValue(true),
            'taxTreatment' => $this->string()->notNull()->defaultValue('composite'),
            'previewTargets' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createTable('{{%bundlebuilder_bundletypes_sites}}', [
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

        // Only bundle-specific columns live here. SKU, base price, tax/shipping
        // category, free-shipping, inventory tracking and dimensions are all
        // persisted natively by Commerce's Purchasable base class
        // (commerce_purchasables / commerce_purchasables_stores).
        $this->createTable('{{%bundlebuilder_bundles}}', [
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

        $this->createTable('{{%bundlebuilder_products}}', [
            'id' => $this->primaryKey(),
            'bundleId' => $this->integer()->notNull(),
            'productId' => $this->integer()->notNull(),
            'qty' => $this->integer()->unsigned()->notNull()->defaultValue(1),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        return true;
    }

    /**
     * Creates the plugin's indexes.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _createIndexes(): void
    {
        $this->createIndex(null, '{{%bundlebuilder_bundletypes}}', ['handle'], true);
        $this->createIndex(null, '{{%bundlebuilder_bundletypes}}', ['fieldLayoutId'], false);

        $this->createIndex(null, '{{%bundlebuilder_bundletypes_sites}}', ['bundleTypeId', 'siteId'], true);
        $this->createIndex(null, '{{%bundlebuilder_bundletypes_sites}}', ['siteId'], false);

        $this->createIndex(null, '{{%bundlebuilder_bundles}}', ['typeId'], false);
        $this->createIndex(null, '{{%bundlebuilder_bundles}}', ['postDate'], false);
        $this->createIndex(null, '{{%bundlebuilder_bundles}}', ['expiryDate'], false);

        $this->createIndex(null, '{{%bundlebuilder_products}}', ['bundleId'], false);
        $this->createIndex(null, '{{%bundlebuilder_products}}', ['productId'], false);
    }

    /**
     * Adds the plugin's foreign keys.
     *
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _addForeignKeys(): void
    {
        $this->addForeignKey(null, '{{%bundlebuilder_bundletypes}}', ['fieldLayoutId'], '{{%fieldlayouts}}', ['id'], 'SET NULL', null);

        $this->addForeignKey(null, '{{%bundlebuilder_bundletypes_sites}}', ['bundleTypeId'], '{{%bundlebuilder_bundletypes}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%bundlebuilder_bundletypes_sites}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE', 'CASCADE');

        $this->addForeignKey(null, '{{%bundlebuilder_bundles}}', ['id'], '{{%elements}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%bundlebuilder_bundles}}', ['typeId'], '{{%bundlebuilder_bundletypes}}', ['id'], 'CASCADE', null);

        $this->addForeignKey(null, '{{%bundlebuilder_products}}', ['bundleId'], '{{%bundlebuilder_bundles}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%bundlebuilder_products}}', ['productId'], '{{%commerce_products}}', ['id'], 'CASCADE', null);
    }
}

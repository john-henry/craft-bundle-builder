<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\db\Query;
use craft\db\Table;
use craft\elements\conditions\ElementConditionInterface;
use craft\events\ConfigEvent;
use craft\events\DeleteSiteEvent;
use craft\events\RebuildConfigEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\queue\jobs\ResaveElements;
use craft\services\ElementSources;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;
use johnhenry\bundlebuilder\records\BundleTypeRecord;
use johnhenry\bundlebuilder\records\BundleTypeSiteRecord;
use Throwable;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * Bundle types service.
 *
 * Reads, saves and deletes {@see BundleType} models with their field layout and
 * site settings. Definitions are stored in Project Config, and the config
 * handlers write the database rows. Results are memoized per request.
 *
 * @property-read BundleType[] $allBundleTypes
 * @property-read BundleType[] $editableBundleTypes
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTypes extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The Project Config path bundle types are stored under, keyed by UID.
     */
    public const CONFIG_BUNDLE_TYPES_KEY = 'bundle-builder.bundleTypes';

    // Private Properties
    // =========================================================================

    /**
     * @var BundleType[]|null Memoized list of all bundle types, indexed by ID.
     */
    private ?array $_bundleTypes = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns every bundle type, ordered by name.
     *
     * @return BundleType[] The bundle types, indexed by ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAllBundleTypes(): array
    {
        if ($this->_bundleTypes !== null) {
            return $this->_bundleTypes;
        }

        $this->_bundleTypes = [];

        /** @var BundleTypeRecord[] $records */
        $records = BundleTypeRecord::find()->orderBy(['name' => SORT_ASC])->all();

        foreach ($records as $record) {
            $bundleType = $this->_createBundleTypeFromRecord($record);
            $this->_bundleTypes[$bundleType->id] = $bundleType;
        }

        return $this->_bundleTypes;
    }

    /**
     * Returns the bundle types the current user can edit.
     *
     * @return BundleType[] The editable bundle types, indexed by ID.
     * @throws Throwable
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getEditableBundleTypes(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user) {
            return [];
        }

        return array_filter(
            $this->getAllBundleTypes(),
            static fn(BundleType $bundleType): bool => $user->can(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $bundleType->uid)
        );
    }

    /**
     * Returns the bundle type with the given ID, or null if none exists.
     *
     * @param int $id The bundle type ID.
     * @return BundleType|null The bundle type, or null.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleTypeById(int $id): ?BundleType
    {
        return $this->getAllBundleTypes()[$id] ?? null;
    }

    /**
     * Returns the bundle type with the given handle, or null if none exists.
     *
     * @param string $handle The bundle type handle.
     * @return BundleType|null The bundle type, or null.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleTypeByHandle(string $handle): ?BundleType
    {
        foreach ($this->getAllBundleTypes() as $bundleType) {
            if ($bundleType->handle === $handle) {
                return $bundleType;
            }
        }

        return null;
    }

    /**
     * Returns which of the given products fall within any of the given product
     * sources, applying each source the way Craft's element index does: a
     * custom source's condition, then the source's criteria.
     *
     * @param int[] $productIds The product IDs to check.
     * @param string|string[] $sources `*` for all sources, or a list of source keys.
     * @param int|null $siteId The site to look the products up in, or null for the current site.
     * @return int[] The IDs that fall within a source.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getProductIdsInSources(array $productIds, string|array $sources, ?int $siteId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        if ($sources === '*') {
            return Product::find()->siteId($siteId)->id($productIds)->status(null)->ids();
        }

        $found = [];

        foreach ((array)$sources as $sourceKey) {
            $source = ElementHelper::findSource(Product::class, $sourceKey, ElementSources::CONTEXT_MODAL);

            if ($source === null) {
                continue;
            }

            $query = Product::find()->siteId($siteId)->status(null);

            if (($source['type'] ?? null) === ElementSources::TYPE_CUSTOM && isset($source['condition'])) {
                /** @var array{class: class-string<ElementConditionInterface>} $config */
                $config = $source['condition'];
                Craft::$app->getConditions()->createCondition($config)->modifyQuery($query);
            }

            if (!empty($source['criteria'])) {
                Craft::configure($query, $source['criteria']);
            }

            $found = [...$found, ...$query->id($productIds)->ids()];
        }

        return array_values(array_unique(array_map('intval', $found)));
    }

    /**
     * Returns the per-site settings for the given bundle type.
     *
     * @param int $bundleTypeId The bundle type ID.
     * @return BundleTypeSite[] The site settings.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleTypeSites(int $bundleTypeId): array
    {
        $rows = (new Query())
            ->select(['id', 'bundleTypeId', 'siteId', 'uriFormat', 'template', 'hasUrls', 'enabledByDefault'])
            ->from(['{{%bundlebuilder_bundletypes_sites}}'])
            ->where(['bundleTypeId' => $bundleTypeId])
            ->all();

        $siteSettings = [];

        foreach ($rows as $row) {
            $siteSettings[] = new BundleTypeSite([
                'id' => (int)$row['id'],
                'bundleTypeId' => (int)$row['bundleTypeId'],
                'siteId' => (int)$row['siteId'],
                'uriFormat' => $row['uriFormat'],
                'template' => $row['template'],
                'hasUrls' => (bool)$row['hasUrls'],
                'enabledByDefault' => (bool)$row['enabledByDefault'],
            ]);
        }

        return $siteSettings;
    }

    /**
     * Validates and saves a bundle type, its field layout, and site settings.
     *
     * @param BundleType $bundleType The bundle type to save.
     * @param bool $runValidation Whether the bundle type should be validated.
     * @return bool Whether the bundle type was saved successfully.
     * @throws Throwable if the field layout or records can't be saved.
     * @throws NotSupportedException if Project Config is read-only.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function saveBundleType(BundleType $bundleType, bool $runValidation = true): bool
    {
        if ($runValidation && !$bundleType->validate()) {
            return false;
        }

        if ($bundleType->id) {
            $bundleType->uid = Db::uidById(BundleTypeRecord::tableName(), $bundleType->id);

            if ($bundleType->uid === null) {
                throw new Exception("Invalid bundle type ID: $bundleType->id");
            }
        }

        $bundleType->uid ??= StringHelper::UUID();

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_BUNDLE_TYPES_KEY . '.' . $bundleType->uid,
            $bundleType->getConfig(),
            "Save the “{$bundleType->handle}” bundle type",
        );

        /** @var BundleTypeRecord|null $record */
        $record = BundleTypeRecord::findOne(['uid' => $bundleType->uid]);

        if ($record === null) {
            throw new Exception("The “{$bundleType->handle}” bundle type wasn’t applied from Project Config.");
        }

        $bundleType->id = $record->id;
        $bundleType->fieldLayoutId = $record->fieldLayoutId;

        return true;
    }

    /**
     * Applies a bundle type added or changed in Project Config to the database.
     *
     * A type whose UID is unknown here takes over an existing type with the
     * same handle, so environments that each created the type by hand end up
     * sharing one definition and keep their bundles.
     *
     * @param ConfigEvent $event The config event.
     * @return void
     * @throws Throwable if the field layout or records can't be saved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function handleChangedBundleType(ConfigEvent $event): void
    {
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $uid = $event->tokenMatches[0];
        $data = $event->newValue;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = $this->_getBundleTypeRecord($uid, $data['handle']);
            $isNew = $record->getIsNewRecord();

            $record->uid = $uid;
            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->skuFormat = $data['skuFormat'] ?? null;
            $record->descriptionFormat = $data['descriptionFormat'] ?? null;
            $record->showSlugField = $data['showSlugField'] ?? true;
            $record->enableVersioning = $data['enableVersioning'] ?? false;
            $record->componentSources = Json::encode($data['componentSources'] ?? '*');
            $record->taxTreatment = $data['taxTreatment'] ?? TaxTreatment::Composite->value;
            $record->previewTargets = empty($data['previewTargets'])
                ? null
                : Json::encode(array_values(ProjectConfigHelper::unpackAssociativeArray($data['previewTargets'])));

            $fieldsService = Craft::$app->getFields();
            $fieldLayoutConfig = empty($data['fieldLayouts']) ? null : reset($data['fieldLayouts']);

            if ($fieldLayoutConfig) {
                $fieldLayout = FieldLayout::createFromConfig($fieldLayoutConfig);
                $fieldLayout->id = $record->fieldLayoutId;
                $fieldLayout->type = Bundle::class;
                $fieldLayout->uid = key($data['fieldLayouts']);
                $fieldsService->saveLayout($fieldLayout, false);
                $record->fieldLayoutId = $fieldLayout->id;
            } elseif ($record->fieldLayoutId) {
                $fieldsService->deleteLayoutById($record->fieldLayoutId);
                $record->fieldLayoutId = null;
            }

            $record->save(false);

            $resaveBundles = $this->_applySiteSettings($record->id, $data['siteSettings'] ?? [], $isNew);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->_bundleTypes = null;

        // Cached GraphQL results carry the type's handle and custom fields
        Craft::$app->getGql()->invalidateCaches();

        if ($resaveBundles) {
            $this->resaveBundles($record->id);
        }
    }

    /**
     * Queues a resave of bundles across all their sites, which adds each bundle
     * to the sites its type supports, removes it from the ones it doesn't, and
     * refreshes its URIs.
     *
     * @param int|null $bundleTypeId The bundle type to resave, or null for every type.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function resaveBundles(?int $bundleTypeId = null): void
    {
        $criteria = ['siteId' => '*', 'unique' => true, 'status' => null];

        if ($bundleTypeId !== null) {
            $criteria['typeId'] = $bundleTypeId;
        }

        Craft::$app->getQueue()->push(new ResaveElements([
            'elementType' => Bundle::class,
            'criteria' => $criteria,
        ]));
    }

    /**
     * Deletes the bundle type with the given ID, its field layout, bundles, and
     * site settings.
     *
     * @param int $id The bundle type ID.
     * @return bool Whether a bundle type was deleted.
     * @throws NotSupportedException if Project Config is read-only.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function deleteBundleTypeById(int $id): bool
    {
        /** @var BundleTypeRecord|null $record */
        $record = BundleTypeRecord::findOne($id);

        if ($record === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_BUNDLE_TYPES_KEY . '.' . $record->uid,
            "Delete the “{$record->handle}” bundle type",
        );

        return true;
    }

    /**
     * Deletes a bundle type removed from Project Config, with its bundles and
     * field layout.
     *
     * @param ConfigEvent $event The config event.
     * @return void
     * @throws Throwable if a bundle element can't be deleted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function handleDeletedBundleType(ConfigEvent $event): void
    {
        /** @var BundleTypeRecord|null $record */
        $record = BundleTypeRecord::findOne(['uid' => $event->tokenMatches[0]]);

        if ($record === null) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $bundles = Bundle::find()->typeId($record->id)->site('*')->unique()->status(null)->all();
            $elementsService = Craft::$app->getElements();

            foreach ($bundles as $bundle) {
                $elementsService->deleteElement($bundle);
            }

            if ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
            }

            $record->delete();

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->_bundleTypes = null;
        Craft::$app->getGql()->invalidateCaches();
    }

    /**
     * Drops a deleted site from every bundle type's Project Config definition.
     *
     * @param DeleteSiteEvent $event The delete-site event.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function pruneDeletedSite(DeleteSiteEvent $event): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $bundleTypes = $projectConfig->get(self::CONFIG_BUNDLE_TYPES_KEY);

        if (!is_array($bundleTypes)) {
            return;
        }

        foreach (array_keys($bundleTypes) as $uid) {
            $projectConfig->remove(self::CONFIG_BUNDLE_TYPES_KEY . '.' . $uid . '.siteSettings.' . $event->site->uid);
        }
    }

    /**
     * Adds every bundle type in the database to a Project Config rebuild.
     *
     * @param RebuildConfigEvent $event The rebuild event.
     * @return void
     * @throws InvalidConfigException if a field layout or site can't be resolved.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    public function handleRebuildConfig(RebuildConfigEvent $event): void
    {
        $this->_bundleTypes = null;
        $event->config['bundle-builder']['bundleTypes'] = [];

        foreach ($this->getAllBundleTypes() as $bundleType) {
            $event->config['bundle-builder']['bundleTypes'][$bundleType->uid] = $bundleType->getConfig();
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the record a Project Config definition applies to: the one with
     * its UID, else an existing one with its handle, else a new one.
     *
     * A record matched by handle is moved to the incoming UID along with the
     * permission that names it.
     *
     * @param string $uid The bundle type UID from Project Config.
     * @param string $handle The bundle type handle from Project Config.
     * @return BundleTypeRecord The record.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _getBundleTypeRecord(string $uid, string $handle): BundleTypeRecord
    {
        /** @var BundleTypeRecord|null $record */
        $record = BundleTypeRecord::findOne(['uid' => $uid]);

        if ($record !== null) {
            return $record;
        }

        /** @var BundleTypeRecord|null $record */
        $record = BundleTypeRecord::findOne(['handle' => $handle]);

        if ($record === null) {
            return new BundleTypeRecord();
        }

        // Craft stores permission names in lowercase. A permission that already
        // exists under the incoming UID is left as it is.
        $oldPermission = strtolower(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $record->uid);
        $newPermission = strtolower(BundlesController::PERMISSION_MANAGE_BUNDLES . ':' . $uid);

        if (!(new Query())->from(Table::USERPERMISSIONS)->where(['name' => $newPermission])->exists()) {
            Db::update(Table::USERPERMISSIONS, ['name' => $newPermission], ['name' => $oldPermission]);
        }

        return $record;
    }

    /**
     * Replaces a bundle type's per-site settings with the ones from Project
     * Config.
     *
     * @param int $bundleTypeId The bundle type ID.
     * @param array<string, array<string, mixed>> $siteData The site settings, keyed by site UID.
     * @param bool $isNew Whether the bundle type was just created.
     * @return bool Whether the type's bundles need resaving: a site was added
     * or removed, or a site's URI format changed.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.3.0
     */
    private function _applySiteSettings(int $bundleTypeId, array $siteData, bool $isNew): bool
    {
        /** @var array<int, BundleTypeSiteRecord> $oldRecords */
        $oldRecords = $isNew ? [] : BundleTypeSiteRecord::find()
            ->where(['bundleTypeId' => $bundleTypeId])
            ->indexBy('siteId')
            ->all();

        $siteIdMap = Db::idsByUids(Table::SITES, array_keys($siteData));
        $resaveBundles = false;

        foreach ($siteData as $siteUid => $siteSettings) {
            $siteId = $siteIdMap[$siteUid] ?? null;

            if ($siteId === null) {
                continue;
            }

            $record = $oldRecords[$siteId] ?? null;
            unset($oldRecords[$siteId]);

            if ($record === null) {
                $record = new BundleTypeSiteRecord();
                $record->bundleTypeId = $bundleTypeId;
                $record->siteId = $siteId;
                $resaveBundles = $resaveBundles || !$isNew;
            }

            $hasUrls = (bool)($siteSettings['hasUrls'] ?? false);
            $record->hasUrls = $hasUrls;
            $record->uriFormat = $hasUrls ? ($siteSettings['uriFormat'] ?? null) : null;
            $record->template = $hasUrls ? ($siteSettings['template'] ?? null) : null;
            $record->enabledByDefault = (bool)($siteSettings['enabledByDefault'] ?? true);

            if (!$record->getIsNewRecord() && ($record->isAttributeChanged('hasUrls', false) || $record->isAttributeChanged('uriFormat', false))) {
                $resaveBundles = true;
            }

            $record->save(false);
        }

        foreach ($oldRecords as $record) {
            $record->delete();
            $resaveBundles = true;
        }

        return $resaveBundles;
    }

    /**
     * Builds a bundle type model from its active record.
     *
     * @param BundleTypeRecord $record The bundle type record.
     * @return BundleType The populated bundle type model.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    private function _createBundleTypeFromRecord(BundleTypeRecord $record): BundleType
    {
        return new BundleType([
            'id' => $record->id,
            'fieldLayoutId' => $record->fieldLayoutId,
            'name' => $record->name,
            'handle' => $record->handle,
            'skuFormat' => $record->skuFormat,
            'descriptionFormat' => $record->descriptionFormat,
            'showSlugField' => (bool)$record->showSlugField,
            'enableVersioning' => (bool)$record->enableVersioning,
            'componentSources' => Json::decodeIfJson($record->componentSources ?? '"*"') ?: '*',
            'taxTreatment' => $record->taxTreatment,
            'previewTargets' => $record->previewTargets ? Json::decode($record->previewTargets) : [],
            'uid' => $record->uid,
        ]);
    }
}

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Product;
use craft\db\Query;
use craft\elements\conditions\ElementConditionInterface;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craft\services\ElementSources;
use johnhenry\bundlebuilder\controllers\BundlesController;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\models\BundleTypeSite;
use johnhenry\bundlebuilder\records\BundleTypeRecord;
use johnhenry\bundlebuilder\records\BundleTypeSiteRecord;
use Throwable;
use yii\base\Exception;

/**
 * Bundle types service.
 *
 * Reads, saves and deletes {@see BundleType} models with their field layout and
 * site settings. Results are memoized per request.
 *
 * @property-read BundleType[] $allBundleTypes
 * @property-read BundleType[] $editableBundleTypes
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTypes extends Component
{
    public const CONFIG_BUNDLE_TYPES = 'bundleBuilder.bundleTypes';

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
     * @return int[] The IDs that fall within a source.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getProductIdsInSources(array $productIds, string|array $sources): array
    {
        if (empty($productIds)) {
            return [];
        }

        if ($sources === '*') {
            return Product::find()->id($productIds)->status(null)->ids();
        }

        $found = [];

        foreach ((array)$sources as $sourceKey) {
            $source = ElementHelper::findSource(Product::class, $sourceKey, ElementSources::CONTEXT_MODAL);

            if ($source === null) {
                continue;
            }

            $query = Product::find()->status(null);

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
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function saveBundleType(BundleType $bundleType, bool $runValidation = true): bool
    {
        if ($runValidation && !$bundleType->validate()) {
            return false;
        }

        if ($bundleType->id) {
            $bundleType->uid = Db::uidById('{{%bundlebuilder_bundletypes}}', $bundleType->id);
            if ($bundleType->uid === null) {
                throw new Exception("Invalid bundle type ID: {$bundleType->id}");
            }
        } else {
            $bundleType->uid ??= StringHelper::UUID();
        }

        $layout = $bundleType->getBundleFieldLayout();
        if ($runValidation && !$layout->validate()) {
            $bundleType->addError('fieldLayout', Craft::t('bundle-builder', 'Couldn’t save field layout.'));
            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_BUNDLE_TYPES . '.' . $bundleType->uid,
            $bundleType->getConfig(),
        );

        $record = BundleTypeRecord::findOne(['uid' => $bundleType->uid]);
        if ($record === null) {
            throw new Exception("Bundle type {$bundleType->uid} was not applied from project config.");
        }
        $bundleType->id = $record->id;
        $bundleType->fieldLayoutId = $record->fieldLayoutId;
        $layout->id = $record->fieldLayoutId;

        return true;
    }

    public function handleChangedBundleType(ConfigEvent $event): void
    {
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $uid = $event->tokenMatches[0] ?? throw new Exception('Missing bundle type UID.');
        $data = $event->newValue;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $record = BundleTypeRecord::findOne(['uid' => $uid]) ?? new BundleTypeRecord();
            $record->uid = $uid;
            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->skuFormat = $data['skuFormat'] ?? null;
            $record->descriptionFormat = $data['descriptionFormat'] ?? null;
            $record->showSlugField = $data['showSlugField'];
            $record->enableVersioning = $data['enableVersioning'];
            $record->componentSources = Json::encode($data['componentSources']);
            $record->taxTreatment = $data['taxTreatment'];
            $record->previewTargets = empty($data['previewTargets'])
                ? null
                : Json::encode(ProjectConfigHelper::unpackAssociativeArray($data['previewTargets']));

            $fields = Craft::$app->getFields();
            if (!empty($data['fieldLayouts'])) {
                $layout = FieldLayout::createFromConfig(reset($data['fieldLayouts']));
                $layout->id = $record->fieldLayoutId;
                $layout->type = Bundle::class;
                $layout->uid = key($data['fieldLayouts']);
                $fields->saveLayout($layout, false);
                $record->fieldLayoutId = $layout->id;
            } elseif ($record->fieldLayoutId) {
                $fields->deleteLayoutById($record->fieldLayoutId);
                $record->fieldLayoutId = null;
            }

            $record->save(false);

            Db::delete('{{%bundlebuilder_bundletypes_sites}}', ['bundleTypeId' => $record->id]);
            foreach ($data['siteSettings'] as $siteUid => $settings) {
                $siteId = Db::idByUid('{{%sites}}', $siteUid);
                if ($siteId === null) {
                    continue;
                }
                $siteRecord = new BundleTypeSiteRecord();
                $siteRecord->bundleTypeId = $record->id;
                $siteRecord->siteId = $siteId;
                $siteRecord->hasUrls = $settings['hasUrls'];
                $siteRecord->uriFormat = $settings['hasUrls'] ? ($settings['uriFormat'] ?? null) : null;
                $siteRecord->template = $settings['hasUrls'] ? ($settings['template'] ?? null) : null;
                $siteRecord->enabledByDefault = $settings['enabledByDefault'];
                $siteRecord->save(false);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->_bundleTypes = null;
        Craft::$app->getGql()->invalidateCaches();
    }

    /**
     * Deletes the bundle type with the given ID, its field layout, bundles, and
     * site settings.
     *
     * @param int $id The bundle type ID.
     * @return bool Whether a bundle type was deleted.
     * @throws Throwable if a bundle element can't be deleted.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function deleteBundleTypeById(int $id): bool
    {
        $record = BundleTypeRecord::findOne($id);
        if ($record === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_BUNDLE_TYPES . '.' . $record->uid);
        return true;
    }

    public function handleDeletedBundleType(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0] ?? throw new Exception('Missing bundle type UID.');
        $record = BundleTypeRecord::findOne(['uid' => $uid]);
        if ($record === null) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $bundles = Bundle::find()->typeId($record->id)->status(null)->limit(null)->all();
            foreach ($bundles as $bundle) {
                if (!Craft::$app->getElements()->deleteElement($bundle)) {
                    throw new Exception("Could not delete bundle {$bundle->id}.");
                }
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

    public function pruneDeletedSite(ConfigEvent $event): void
    {
        $siteUid = $event->tokenMatches[0] ?? throw new Exception('Missing site UID.');
        $projectConfig = Craft::$app->getProjectConfig();
        if ($projectConfig->getIsApplyingExternalChanges()) {
            return;
        }

        foreach ($projectConfig->get(self::CONFIG_BUNDLE_TYPES) ?? [] as $uid => $config) {
            if (isset($config['siteSettings'][$siteUid])) {
                $projectConfig->remove(self::CONFIG_BUNDLE_TYPES . '.' . $uid . '.siteSettings.' . $siteUid);
            }
        }
    }

    // Private Methods
    // =========================================================================

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

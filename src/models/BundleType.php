<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\models;

use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\records\BundleTypeRecord;

/**
 * Bundle type model.
 *
 * A bundle type defines the field layout shared by its bundles, the formats used
 * to generate each bundle's SKU and description, and the per-site URL settings
 * that control whether bundles of the type are routable on the front end.
 *
 * @mixin FieldLayoutBehavior
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleType extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The bundle type's ID.
     */
    public ?int $id = null;

    /**
     * @var int|null The bundle type's field layout ID.
     */
    public ?int $fieldLayoutId = null;

    /**
     * @var string|null The bundle type's name.
     */
    public ?string $name = null;

    /**
     * @var string|null The bundle type's handle.
     */
    public ?string $handle = null;

    /**
     * @var string|null The format used to generate a bundle's SKU.
     */
    public ?string $skuFormat = null;

    /**
     * @var string|null The format used to generate a bundle's description.
     */
    public ?string $descriptionFormat = null;

    /**
     * @var bool Whether the slug field is shown on bundles of this type.
     */
    public bool $showSlugField = true;

    /**
     * @var string How bundles of this type are treated for VAT ("composite" or "multiple").
     */
    public string $taxTreatment = TaxTreatment::Composite->value;

    /**
     * @var array The bundle type's preview targets (each `['label' => ..., 'urlFormat' => ...]`).
     */
    public array $previewTargets = [];

    /**
     * @var string|null The bundle type's UID.
     */
    public ?string $uid = null;

    // Private Properties
    // =========================================================================

    /**
     * @var BundleTypeSite[]|null The bundle type's site settings, indexed by site ID.
     */
    private ?array $_siteSettings = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the bundle type's name.
     *
     * @return string The bundle type's name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function __toString(): string
    {
        return (string)$this->name;
    }

    /**
     * @inheritdoc
     *
     * @return array The behaviors.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function behaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Bundle::class,
                'idAttribute' => 'fieldLayoutId',
            ],
        ];
    }

    /**
     * Returns the bundle type's control panel edit URL.
     *
     * @return string The CP edit URL.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('bundle-builder/bundle-types/' . $this->id);
    }

    /**
     * Returns the bundle type's field layout.
     *
     * @return FieldLayout The field layout.
     * @throws \yii\base\InvalidConfigException if the behavior is misconfigured.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleFieldLayout(): FieldLayout
    {
        /** @var FieldLayoutBehavior $behavior */
        $behavior = $this->getBehavior('fieldLayout');
        return $behavior->getFieldLayout();
    }

    /**
     * Returns the bundle type's per-site settings, indexed by site ID.
     *
     * @return BundleTypeSite[] The site settings.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSiteSettings(): array
    {
        if ($this->_siteSettings !== null) {
            return $this->_siteSettings;
        }

        if (!$this->id) {
            return [];
        }

        $this->setSiteSettings(
            ArrayHelper::index(BundleBuilder::getInstance()->getBundleTypes()->getBundleTypeSites($this->id), 'siteId')
        );

        return $this->_siteSettings ?? [];
    }

    /**
     * Sets the bundle type's per-site settings.
     *
     * @param BundleTypeSite[] $siteSettings The site settings, indexed by site ID.
     * @return void
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setSiteSettings(array $siteSettings): void
    {
        $this->_siteSettings = $siteSettings;

        foreach ($this->_siteSettings as $settings) {
            $settings->setBundleType($this);
        }
    }

    /**
     * @inheritdoc
     *
     * @return array The validation rules.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['id', 'fieldLayoutId'], 'number', 'integerOnly' => true];
        $rules[] = [['name', 'handle'], 'required'];
        $rules[] = [['name', 'handle'], 'string', 'max' => 255];
        $rules[] = [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title']];
        $rules[] = [['name', 'handle'], UniqueValidator::class, 'targetClass' => BundleTypeRecord::class];
        $rules[] = [['taxTreatment'], 'in', 'range' => [TaxTreatment::Composite->value, TaxTreatment::Multiple->value]];

        return $rules;
    }
}

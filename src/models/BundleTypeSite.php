<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\models;

use Craft;
use craft\base\Model;
use craft\models\Site;

/**
 * Bundle type site settings model.
 *
 * Per-site URL settings for a {@see BundleType}: whether bundles have URLs in
 * the site, their URI format, and the template that renders them.
 *
 * @property-read Site|null $site
 * @property BundleType|null $bundleType
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTypeSite extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The record ID.
     */
    public ?int $id = null;

    /**
     * @var int|null The bundle type ID.
     */
    public ?int $bundleTypeId = null;

    /**
     * @var int|null The site ID.
     */
    public ?int $siteId = null;

    /**
     * @var bool Whether bundles of this type have URLs in this site.
     */
    public bool $hasUrls = false;

    /**
     * @var bool Whether new bundles are enabled by default in this site.
     */
    public bool $enabledByDefault = true;

    /**
     * @var string|null The URI format for bundles in this site.
     */
    public ?string $uriFormat = null;

    /**
     * @var string|null The template path for bundles in this site.
     */
    public ?string $template = null;

    // Private Properties
    // =========================================================================

    /**
     * @var BundleType|null The bundle type these settings belong to.
     */
    private ?BundleType $_bundleType = null;

    /**
     * @var Site|null The site these settings belong to.
     */
    private ?Site $_site = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the bundle type these settings belong to.
     *
     * @return BundleType|null The bundle type, or null if not set.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleType(): ?BundleType
    {
        return $this->_bundleType;
    }

    /**
     * Sets the bundle type these settings belong to.
     *
     * @param BundleType $bundleType The bundle type.
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function setBundleType(BundleType $bundleType): void
    {
        $this->_bundleType = $bundleType;
    }

    /**
     * Returns the site these settings belong to.
     *
     * @return Site|null The site, or null if not set.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSite(): ?Site
    {
        if ($this->_site === null && $this->siteId) {
            $this->_site = Craft::$app->getSites()->getSiteById($this->siteId);
        }
        return $this->_site;
    }

    /**
     * @inheritdoc
     *
     * @return array<int, array<int|string, mixed>> The validation rules.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['siteId'], 'required'];
        $rules[] = [['id', 'bundleTypeId', 'siteId'], 'number', 'integerOnly' => true];
        $rules[] = [['uriFormat'], 'required', 'when' => fn(BundleTypeSite $model): bool => $model->hasUrls];
        $rules[] = [['template'], 'required', 'when' => fn(BundleTypeSite $model): bool => $model->hasUrls];

        return $rules;
    }
}

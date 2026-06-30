<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\records;

use craft\db\ActiveRecord;
use craft\records\Site;
use yii\db\ActiveQueryInterface;

/**
 * Bundle type site active record.
 *
 * Persists the per-site URL settings for a bundle type: whether bundles of this
 * type have front-end URLs in the given site, the URI format, and the template
 * used to render them.
 *
 * @property int $id The record's ID.
 * @property int $bundleTypeId The bundle type's ID.
 * @property int $siteId The site's ID.
 * @property string|null $uriFormat The URI format for bundles in this site.
 * @property string|null $template The template path for bundles in this site.
 * @property bool $hasUrls Whether bundles of this type have URLs in this site.
 * @property bool $enabledByDefault Whether new bundles are enabled by default in this site.
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
class BundleTypeSiteRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the name of the database table this record uses.
     *
     * @return string The table name.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function tableName(): string
    {
        return '{{%bundlebuilder_bundletypes_sites}}';
    }

    /**
     * Returns the bundle type this record belongs to.
     *
     * @return ActiveQueryInterface The relational query.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleType(): ActiveQueryInterface
    {
        return $this->hasOne(BundleTypeRecord::class, ['id' => 'bundleTypeId']);
    }

    /**
     * Returns the site this record belongs to.
     *
     * @return ActiveQueryInterface The relational query.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}

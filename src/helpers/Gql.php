<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\helpers;

use craft\helpers\Gql as GqlHelper;
use craft\models\GqlSchema;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\models\BundleType;

/**
 * GraphQL helper.
 *
 * Reads the bundle types a GraphQL schema grants read access to. Access is
 * granted per bundle type with a `bundleTypes.{uid}:read` scope.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Gql extends GqlHelper
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The schema scope prefix for bundle types.
     */
    public const SCOPE_BUNDLE_TYPES = 'bundleTypes';

    // Public Methods
    // =========================================================================

    /**
     * Returns whether the schema can query bundles of any type.
     *
     * @param GqlSchema|null $schema The schema, or null for the active one.
     * @return bool Whether bundles can be queried.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function canQueryBundles(?GqlSchema $schema = null): bool
    {
        return !empty(self::getSchemaContainedBundleTypes($schema));
    }

    /**
     * Returns the bundle types the schema can read.
     *
     * @param GqlSchema|null $schema The schema, or null for the active one.
     * @return BundleType[] The readable bundle types, indexed by ID.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getSchemaContainedBundleTypes(?GqlSchema $schema = null): array
    {
        $uids = self::extractAllowedEntitiesFromSchema('read', $schema)[self::SCOPE_BUNDLE_TYPES] ?? [];

        if (!is_array($uids) || empty($uids)) {
            return [];
        }

        return array_filter(
            BundleBuilder::getInstance()->getBundleTypes()->getAllBundleTypes(),
            static fn(BundleType $bundleType): bool => in_array($bundleType->uid, $uids, true),
        );
    }

    /**
     * Returns the IDs of the bundle types the schema can read.
     *
     * @param GqlSchema|null $schema The schema, or null for the active one.
     * @return int[] The readable bundle type IDs.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getSchemaContainedBundleTypeIds(?GqlSchema $schema = null): array
    {
        return array_map('intval', array_keys(self::getSchemaContainedBundleTypes($schema)));
    }
}

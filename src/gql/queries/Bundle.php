<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use johnhenry\bundlebuilder\gql\arguments\elements\Bundle as BundleArguments;
use johnhenry\bundlebuilder\gql\interfaces\elements\Bundle as BundleInterface;
use johnhenry\bundlebuilder\gql\resolvers\elements\Bundle as BundleResolver;
use johnhenry\bundlebuilder\helpers\Gql;

/**
 * Bundle GraphQL queries.
 *
 * Registers `bundles`, `bundle` and `bundleCount`, when the active schema can
 * read at least one bundle type.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Bundle extends Query
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param bool $checkToken Whether to only return the queries the active schema allows.
     * @return array<string, array<string, mixed>> The query definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !Gql::canQueryBundles()) {
            return [];
        }

        return [
            'bundles' => [
                'type' => Type::listOf(BundleInterface::getType()),
                'args' => BundleArguments::getArguments(),
                'resolve' => BundleResolver::class . '::resolve',
                'description' => 'This query is used to query for bundles.',
            ],
            'bundleCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => BundleArguments::getArguments(),
                'resolve' => BundleResolver::class . '::resolveCount',
                'description' => 'This query is used to return the number of bundles.',
            ],
            'bundle' => [
                'type' => BundleInterface::getType(),
                'args' => BundleArguments::getArguments(),
                'resolve' => BundleResolver::class . '::resolveOne',
                'description' => 'This query is used to query for a bundle.',
            ],
        ];
    }
}

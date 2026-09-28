<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\fields;

use Craft;
use craft\fields\BaseRelationField;
use craft\models\GqlSchema;
use craft\services\Gql as GqlService;
use GraphQL\Type\Definition\Type;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\gql\arguments\elements\Bundle as BundleArguments;
use johnhenry\bundlebuilder\gql\interfaces\elements\Bundle as BundleInterface;
use johnhenry\bundlebuilder\gql\resolvers\elements\Bundle as BundleResolver;
use johnhenry\bundlebuilder\helpers\Gql;

/**
 * Bundles relation field.
 *
 * A relation field for selecting bundle elements, so editors can relate bundles
 * from other elements' field layouts (e.g. a "Bundles" field on a product or
 * entry).
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
class Bundles extends BaseRelationField
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The field type's display name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('bundle-builder', 'Bundles');
    }

    /**
     * @inheritdoc
     *
     * @return string The field type's icon.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function icon(): string
    {
        return 'box';
    }

    /**
     * @inheritdoc
     *
     * @return string The related element type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function elementType(): string
    {
        return Bundle::class;
    }

    /**
     * @inheritdoc
     *
     * @return string The default selection label.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function defaultSelectionLabel(): string
    {
        return Craft::t('bundle-builder', 'Add a bundle');
    }

    /**
     * @inheritdoc
     *
     * @param GqlSchema $schema The schema being built.
     * @return bool Whether the schema can read at least one bundle type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return Gql::canQueryBundles($schema);
    }

    /**
     * @inheritdoc
     *
     * @return Type|array<string, mixed> The field's GraphQL definition.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::nonNull(Type::listOf(BundleInterface::getType())),
            'args' => [
                ...BundleArguments::getArguments(),
                ...$this->gqlFieldArguments(),
            ],
            'resolve' => BundleResolver::class . '::resolve',
            'complexity' => Gql::relatedArgumentComplexity(GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD),
        ];
    }

    /**
     * @inheritdoc
     *
     * Eager-loaded bundles skip the resolver's scope check, so they're limited
     * to the readable bundle types here.
     *
     * @return array<string, int[]>|null The eager-loading conditions, or null
     * when the schema can't read any bundle type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function getEagerLoadingGqlConditions(): ?array
    {
        $typeIds = Gql::getSchemaContainedBundleTypeIds();

        if (empty($typeIds)) {
            return null;
        }

        return ['typeId' => $typeIds];
    }
}

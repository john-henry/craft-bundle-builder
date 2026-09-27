<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;
use johnhenry\bundlebuilder\gql\interfaces\elements\Bundle as BundleInterface;
use johnhenry\bundlebuilder\gql\types\elements\Bundle as BundleGqlType;
use johnhenry\bundlebuilder\helpers\Gql;
use johnhenry\bundlebuilder\models\BundleType as BundleTypeModel;

/**
 * Bundle GraphQL type generator.
 *
 * Generates a `{handle}_Bundle` type for each bundle type the active schema
 * can read, with the custom fields from the bundle type's field layout.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class BundleType extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $context Unused.
     * @return array<string, ObjectType> The generated types, indexed by name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function generateTypes(mixed $context = null): array
    {
        $gqlTypes = [];

        foreach (Gql::getSchemaContainedBundleTypes() as $bundleType) {
            $type = static::generateType($bundleType);
            $gqlTypes[$type->name] = $type;
        }

        return $gqlTypes;
    }

    /**
     * @inheritdoc
     *
     * @param mixed $context The bundle type.
     * @return ObjectType The bundle type's GraphQL type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function generateType(mixed $context): ObjectType
    {
        /** @var BundleTypeModel $context */
        $typeName = BundleElement::gqlTypeName($context);

        return GqlEntityRegistry::getOrCreate($typeName, static fn(): BundleGqlType => new BundleGqlType([
            'name' => $typeName,
            'fields' => static function() use ($context, $typeName): array {
                $contentFields = self::getContentFields($context->getBundleFieldLayout());
                $fields = array_merge(BundleInterface::getFieldDefinitions(), $contentFields);

                return Craft::$app->getGql()->prepareFieldDefinitions($fields, $typeName);
            },
        ]));
    }
}

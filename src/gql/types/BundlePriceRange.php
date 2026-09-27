<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\types;

use Craft;
use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;

/**
 * Bundle price range GraphQL type.
 *
 * The lowest and highest a bundle's sale price can be across the variants its
 * components offer, for "from" prices.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class BundlePriceRange extends ObjectType
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the type's name.
     *
     * @return string The type name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getName(): string
    {
        return 'BundlePriceRange';
    }

    /**
     * Returns the type, creating it the first time it's asked for.
     *
     * @return Type The type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), static fn(): self => new self([
            'name' => self::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'The lowest and highest sale price of a bundle across the variants its components offer.',
        ]));
    }

    /**
     * Returns the type's field definitions.
     *
     * @return array<string, array<string, mixed>> The field definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions([
            'min' => [
                'name' => 'min',
                'type' => Type::nonNull(Type::float()),
                'description' => 'The lowest sale price.',
            ],
            'max' => [
                'name' => 'max',
                'type' => Type::nonNull(Type::float()),
                'description' => 'The highest sale price.',
            ],
        ], self::getName());
    }
}

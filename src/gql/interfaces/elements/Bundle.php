<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use craft\gql\types\DateTime;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use johnhenry\bundlebuilder\gql\types\BundleComponent;
use johnhenry\bundlebuilder\gql\types\BundlePriceRange;
use johnhenry\bundlebuilder\gql\types\generators\BundleType;

/**
 * Bundle GraphQL interface.
 *
 * Implemented by every bundle type's GraphQL type. Carries Craft's element
 * fields, the purchasable fields Commerce exposes on variants, and the
 * bundle's pricing, dates and components.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Bundle extends Element
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return string The type generator class.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getTypeGenerator(): string
    {
        return BundleType::class;
    }

    /**
     * @inheritdoc
     *
     * @return Type The interface type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all bundles.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        BundleType::generateTypes();

        return $type;
    }

    /**
     * @inheritdoc
     *
     * @return string The interface name.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getName(): string
    {
        return 'BundleInterface';
    }

    /**
     * @inheritdoc
     *
     * @return array<string, array<string, mixed>> The field definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The bundle’s full URL.',
            ],
            'bundleTypeId' => [
                'name' => 'bundleTypeId',
                'type' => Type::int(),
                'description' => 'The ID of the bundle type that contains the bundle.',
            ],
            'bundleTypeHandle' => [
                'name' => 'bundleTypeHandle',
                'type' => Type::string(),
                'description' => 'The handle of the bundle type that contains the bundle.',
            ],
            'sku' => [
                'name' => 'sku',
                'type' => Type::string(),
                'description' => 'The SKU of the bundle.',
            ],
            'storeId' => [
                'name' => 'storeId',
                'type' => Type::int(),
                'description' => 'The ID of the bundle’s store.',
            ],
            'price' => [
                'name' => 'price',
                'type' => Type::float(),
                'description' => 'The price of the bundle, for its default variants.',
            ],
            'priceAsCurrency' => [
                'name' => 'priceAsCurrency',
                'type' => Type::string(),
                'description' => 'The formatted price of the bundle.',
            ],
            'promotionalPrice' => [
                'name' => 'promotionalPrice',
                'type' => Type::float(),
                'description' => 'The promotional price of the bundle.',
            ],
            'promotionalPriceAsCurrency' => [
                'name' => 'promotionalPriceAsCurrency',
                'type' => Type::string(),
                'description' => 'The formatted promotional price of the bundle.',
            ],
            'salePrice' => [
                'name' => 'salePrice',
                'type' => Type::float(),
                'description' => 'The sale price of the bundle, for its default variants.',
            ],
            'salePriceAsCurrency' => [
                'name' => 'salePriceAsCurrency',
                'type' => Type::string(),
                'description' => 'The formatted sale price of the bundle.',
            ],
            'onPromotion' => [
                'name' => 'onPromotion',
                'type' => Type::boolean(),
                'description' => 'Whether the bundle has a promotional price.',
            ],
            'priceRange' => [
                'name' => 'priceRange',
                'type' => BundlePriceRange::getType(),
                'description' => 'The lowest and highest sale price of the bundle across the variants its components offer.',
                'complexity' => GqlHelper::nPlus1Complexity(),
            ],
            'pricingStrategy' => [
                'name' => 'pricingStrategy',
                'type' => Type::string(),
                'description' => 'How the bundle is priced: `fixed` or `automatic`.',
            ],
            'discountType' => [
                'name' => 'discountType',
                'type' => Type::string(),
                'description' => 'The discount an automatic bundle takes off its components: `percentage` or `flat`.',
            ],
            'discountAmount' => [
                'name' => 'discountAmount',
                'type' => Type::float(),
                'description' => 'The amount of the automatic discount.',
            ],
            'isAvailable' => [
                'name' => 'isAvailable',
                'type' => Type::boolean(),
                'description' => 'Whether the bundle can be bought: within its dates, available for purchase, and every component has a variant in stock.',
                'complexity' => GqlHelper::nPlus1Complexity(),
            ],
            'availableForPurchase' => [
                'name' => 'availableForPurchase',
                'type' => Type::boolean(),
                'description' => 'Whether the bundle is set as available for purchase.',
            ],
            'promotable' => [
                'name' => 'promotable',
                'type' => Type::boolean(),
                'description' => 'Whether the bundle is promotable.',
            ],
            'freeShipping' => [
                'name' => 'freeShipping',
                'type' => Type::boolean(),
                'description' => 'Whether the bundle has free shipping.',
            ],
            'postDate' => [
                'name' => 'postDate',
                'type' => DateTime::getType(),
                'description' => 'The date the bundle becomes available.',
            ],
            'expiryDate' => [
                'name' => 'expiryDate',
                'type' => DateTime::getType(),
                'description' => 'The date the bundle stops being available.',
            ],
            'components' => [
                'name' => 'components',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(BundleComponent::getType()))),
                'description' => 'The products that make up the bundle.',
                'complexity' => GqlHelper::nPlus1Complexity(),
            ],
        ]), self::getName());
    }
}

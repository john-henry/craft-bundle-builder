<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\arguments\elements;

use Craft;
use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;

/**
 * Bundle GraphQL arguments.
 *
 * Craft's element arguments, the bundle types' custom field arguments, and
 * the bundle and purchasable query params.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Bundle extends ElementArguments
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return array<string, array<string, mixed>> The argument definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), [
            'type' => [
                'name' => 'type',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the query results based on the bundle types the bundles belong to, per the bundle types’ handles.',
            ],
            'typeId' => [
                'name' => 'typeId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the query results based on the bundle types the bundles belong to, per the bundle type IDs.',
            ],
            'sku' => [
                'name' => 'sku',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the query results based on the bundles’ SKUs.',
            ],
            'price' => [
                'name' => 'price',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the query results based on the bundles’ prices.',
            ],
            'promotionalPrice' => [
                'name' => 'promotionalPrice',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the query results based on the bundles’ promotional prices.',
            ],
            'onPromotion' => [
                'name' => 'onPromotion',
                'type' => Type::boolean(),
                'description' => 'Narrows the query results based on whether the bundles have a promotional price.',
            ],
            'availableForPurchase' => [
                'name' => 'availableForPurchase',
                'type' => Type::boolean(),
                'description' => 'Narrows the query results based on whether the bundles are set as available for purchase.',
            ],
            'postDate' => [
                'name' => 'postDate',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the query results based on the bundles’ post dates.',
            ],
            'expiryDate' => [
                'name' => 'expiryDate',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the query results based on the bundles’ expiry dates.',
            ],
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return array<string, mixed> The custom field argument definitions.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function getContentArguments(): array
    {
        $bundleTypeFieldArguments = Craft::$app->getGql()->getContentArguments(
            BundleBuilder::getInstance()->getBundleTypes()->getAllBundleTypes(),
            BundleElement::class,
        );

        return array_merge(parent::getContentArguments(), $bundleTypeFieldArguments);
    }
}

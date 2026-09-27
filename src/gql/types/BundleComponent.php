<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\types;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\gql\interfaces\elements\Product as ProductInterface;
use craft\commerce\gql\interfaces\elements\Variant as VariantInterface;
use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use johnhenry\bundlebuilder\helpers\Gql;
use johnhenry\bundlebuilder\models\BundleProduct;
use yii\base\InvalidConfigException;

/**
 * Bundle component GraphQL type.
 *
 * One product in a bundle: the quantity required, the variants the customer
 * can choose from and the one chosen when they don't pick. The product and its
 * variants are only returned when the schema can also read the product's type,
 * and a product that isn't live only when the schema can query inactive
 * elements.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class BundleComponent extends ObjectType
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
        return 'BundleComponent';
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
            'description' => 'A product in a bundle, with the variants the customer can choose from.',
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
            'productId' => [
                'name' => 'productId',
                'type' => Type::int(),
                'description' => 'The ID of the component’s product.',
            ],
            'qty' => [
                'name' => 'qty',
                'type' => Type::nonNull(Type::int()),
                'description' => 'How many of the product the bundle includes.',
            ],
            'product' => [
                'name' => 'product',
                'type' => ProductInterface::getType(),
                'description' => 'The component’s product.',
            ],
            'variants' => [
                'name' => 'variants',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(VariantInterface::getType()))),
                'description' => 'The variants the customer can choose from: the ones the bundle offers that are available for purchase.',
            ],
            'defaultVariant' => [
                'name' => 'defaultVariant',
                'type' => VariantInterface::getType(),
                'description' => 'The variant chosen when the customer doesn’t pick one.',
            ],
        ], self::getName());
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $source The bundle component.
     * @param array<string, mixed> $arguments The field arguments.
     * @param mixed $context The shared resolver context.
     * @param ResolveInfo $resolveInfo The resolve information.
     * @return mixed The field value.
     * @throws InvalidConfigException if the component's product has an invalid type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var BundleProduct $source */
        return match ($resolveInfo->fieldName) {
            'product' => $this->_readableProduct($source),
            'variants' => $this->_readableProduct($source) ? $source->getVariants() : [],
            'defaultVariant' => $this->_readableProduct($source) ? $source->getDefaultVariant() : null,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the component's product if the active schema may read it.
     *
     * @param BundleProduct $component The bundle component.
     * @return Product|null The product, or null if it's missing or the schema can't read it.
     * @throws InvalidConfigException if the product has an invalid type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    private function _readableProduct(BundleProduct $component): ?Product
    {
        $product = $component->getProduct();

        if (!$product) {
            return null;
        }

        // Without the product type in the schema there's no concrete GraphQL
        // type to resolve the product or its variants to.
        if (!Gql::isSchemaAwareOf(Product::gqlScopesByContext($product->getType()))) {
            return null;
        }

        if ($product->getStatus() !== Product::STATUS_LIVE && !Gql::canQueryInactiveElements()) {
            return null;
        }

        return $product;
    }
}

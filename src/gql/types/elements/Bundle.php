<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\types\elements;

use craft\gql\types\elements\Element as ElementType;
use GraphQL\Type\Definition\ResolveInfo;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;
use johnhenry\bundlebuilder\gql\interfaces\elements\Bundle as BundleInterface;
use johnhenry\bundlebuilder\gql\resolvers\elements\Bundle as BundleResolver;
use yii\base\InvalidConfigException;

/**
 * Bundle GraphQL type.
 *
 * The object type generated for each bundle type, implementing
 * {@see BundleInterface}.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Bundle extends ElementType
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param array<string, mixed> $config The type config.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            BundleInterface::getType(),
        ];

        parent::__construct($config);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $source The bundle.
     * @param array<string, mixed> $arguments The field arguments.
     * @param mixed $context The shared resolver context.
     * @param ResolveInfo $resolveInfo The resolve information.
     * @return mixed The field value.
     * @throws InvalidConfigException if the bundle has no valid type.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var BundleElement $source */
        return match ($resolveInfo->fieldName) {
            'bundleTypeHandle' => $source->getType()->handle,
            'bundleTypeId' => $source->typeId,
            'components' => $source->getProducts(),
            'priceRange' => $source->getPriceRange(),
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }

    /**
     * @inheritdoc
     *
     * @return string|null The resolver class.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    protected static function elementResolverClass(): ?string
    {
        return BundleResolver::class;
    }
}

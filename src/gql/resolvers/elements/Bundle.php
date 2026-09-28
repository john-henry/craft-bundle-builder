<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\gql\resolvers\elements;

use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use johnhenry\bundlebuilder\elements\Bundle as BundleElement;
use johnhenry\bundlebuilder\helpers\Gql;
use yii\base\UnknownMethodException;

/**
 * Bundle GraphQL resolver.
 *
 * Resolves the `bundles`, `bundle` and `bundleCount` queries and Bundles
 * field values, limited to the bundle types the active schema can read.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class Bundle extends ElementResolver
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @param mixed $source The parent element, or null at the root of a query.
     * @param array<string, mixed> $arguments The query arguments.
     * @param string|null $fieldName The field being resolved on the parent element.
     * @return mixed The prepared bundle query, or the eager-loaded or empty collection.
     * @throws UnknownMethodException if an argument has no matching query param.
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source === null ? BundleElement::find() : $source->$fieldName;

        // Eager-loaded values have already been limited to the readable types
        // by the Bundles field's eager-loading conditions.
        if (!$query instanceof ElementQuery) {
            return $query;
        }

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        $typeIds = Gql::getSchemaContainedBundleTypeIds();

        if (empty($typeIds)) {
            return ElementCollection::empty();
        }

        $query->andWhere(['bundlebuilder_bundles.typeId' => $typeIds]);

        return $query;
    }
}

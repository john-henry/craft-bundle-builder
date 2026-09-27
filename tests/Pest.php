<?php

/**
 * Pest harness for the Bundle Builder plugin.
 *
 * Feature tests run without a running Craft application; they cover enums and
 * pure model validation that never touches the database. Anything that reaches
 * Craft::$app, Commerce::getInstance(), a Bundle element, or the database must
 * live under Integration/ and uses the RefreshesDatabase trait so the test DB is
 * rolled back after each test.
 *
 * Run from the parent Craft project (not from inside the plugin folder):
 *
 *   ddev exec vendor/bin/pest plugins/craft-bundle-builder/tests \
 *     --test-directory=plugins/craft-bundle-builder/tests
 */

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use craft\services\Gql as GqlService;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\elements\Bundle;
use johnhenry\bundlebuilder\enums\PricingStrategy;
use johnhenry\bundlebuilder\enums\TaxTreatment;
use johnhenry\bundlebuilder\helpers\Gql as GqlHelper;
use johnhenry\bundlebuilder\models\BundleType;
use johnhenry\bundlebuilder\records\BundleTypeRecord;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

// Apply TestCase + RefreshesDatabase to every Integration test.
// RefreshesDatabase wraps each test in a DB transaction that rolls back on
// teardown, keeping test bundle types and pivot rows out of the database.
uses(
    TestCase::class,
    RefreshesDatabase::class,
)->in('Integration');

// Reset the bundle-types service cache before every Integration test, so the
// service re-reads from the DB after RefreshesDatabase rolls back the previous
// test's transaction.
beforeEach(function () {
    // Swap the Craft cache to a fresh in-memory store so each test starts with an
    // empty cache and avoids FileCache filesystem noise.
    Craft::$app->set('cache', new ArrayCache());
    resetBundleTypesCache();
})->in('Integration');

// ---------------------------------------------------------------------------
// Shared integration test helpers
// Defined here so they are available regardless of which test file runs.
// ---------------------------------------------------------------------------

/**
 * Clears the BundleTypes service's in-request memo so it re-reads from the DB
 * after a RefreshesDatabase rollback or a direct record delete.
 */
function resetBundleTypesCache(): void
{
    $service = BundleBuilder::getInstance()->getBundleTypes();
    $prop = new \ReflectionProperty($service, '_bundleTypes');
    $prop->setAccessible(true);
    $prop->setValue($service, null);
}

/**
 * Creates (or reuses) a bundle type by handle. Upsert-style; any existing
 * record with the same handle (e.g. left behind by CP usage outside of tests)
 * is dropped first so tests run idempotently against any DB state. Inside the
 * current test's transaction the DELETE is itself rolled back at teardown.
 */
function makeBundleType(
    string $name = 'Starter Kit',
    string $handle = 'starterKit',
    string $taxTreatment = TaxTreatment::Composite->value,
): BundleType {
    BundleTypeRecord::deleteAll(['handle' => $handle]);
    resetBundleTypesCache();

    $type = new BundleType();
    $type->name = $name;
    $type->handle = $handle;
    $type->taxTreatment = $taxTreatment;

    if (!BundleBuilder::getInstance()->getBundleTypes()->saveBundleType($type)) {
        throw new RuntimeException(
            'Could not save bundle type: ' . implode(', ', $type->getErrorSummary(true))
        );
    }

    resetBundleTypesCache();

    return $type;
}

/**
 * Creates a saved Commerce product with a single default variant at the given
 * base price, in the given product type or the first one in the test database. The RefreshesDatabase transaction rolls the product back on
 * teardown.
 *
 * @param float $basePrice The default variant's base price.
 * @param int|null $stock The default variant's tracked stock, or null for untracked.
 * @param float|null $promotionalPrice The default variant's promotional (sale) price, or null for none.
 * @param bool $allowOutOfStockPurchases Whether the default variant allows out-of-stock purchases.
 * @param int|null $typeId The product type ID, or null for the first product type.
 */
function makeProduct(
    float $basePrice = 10.0,
    ?int $stock = null,
    ?float $promotionalPrice = null,
    bool $allowOutOfStockPurchases = false,
    ?int $typeId = null,
): Product
{
    $typeId ??= (int)(new Query())
        ->select('id')
        ->from('{{%commerce_producttypes}}')
        ->orderBy(['id' => SORT_ASC])
        ->scalar();

    if (!$typeId) {
        throw new RuntimeException('No Commerce product type exists in the test database.');
    }

    $elementsService = Craft::$app->getElements();

    return withFileCacheWarningsSuppressed(static function () use ($elementsService, $typeId, $basePrice, $stock, $promotionalPrice, $allowOutOfStockPurchases): Product {
        $product = new Product();
        $product->typeId = $typeId;
        $product->title = 'Test Component ' . StringHelper::randomString(6);

        if (!$elementsService->saveElement($product, false)) {
            throw new RuntimeException(
                'Could not save test product: ' . implode(', ', $product->getErrorSummary(true))
            );
        }

        // Save the default variant as a nested element explicitly, mirroring
        // Commerce's own ProductFixture; relying on Product::setVariants() alone
        // doesn't mark the variants attribute dirty, so the cascade never fires.
        $variant = new Variant();
        $variant->setPrimaryOwnerId($product->id);
        $variant->setOwnerId($product->id);
        $variant->isDefault = true;
        $variant->sku = 'BB-TEST-' . StringHelper::UUID();
        $variant->setBasePrice($basePrice);
        $variant->inventoryTracked = $stock !== null;
        $variant->allowOutOfStockPurchases = $allowOutOfStockPurchases;

        if ($promotionalPrice !== null) {
            $variant->setBasePromotionalPrice($promotionalPrice);
        }

        if (!$elementsService->saveElement($variant, false)) {
            throw new RuntimeException(
                'Could not save test variant: ' . implode(', ', $variant->getErrorSummary(true))
            );
        }

        // Stock is derived from store-scoped inventory levels in Commerce 5, so
        // set it through the inventory service (using the saved variant's inventory
        // item) rather than as a plain attribute.
        if ($stock !== null) {
            ensureStoreHasInventoryLocation($variant->getStore()->id);
            Commerce::getInstance()->getInventory()
                ->updatePurchasableInventoryLevel($variant, $stock);
        }

        return $product;
    });
}

/**
 * Adds a non-default variant to a product, optionally with tracked stock.
 * Rolled back with the test transaction.
 *
 * @param Product $product The product.
 * @param float $basePrice The variant's base price.
 * @param int|null $stock The variant's tracked stock, or null for untracked.
 */
function addVariant(Product $product, float $basePrice, ?int $stock = null): Variant
{
    return withFileCacheWarningsSuppressed(static function () use ($product, $basePrice, $stock): Variant {
        $variant = new Variant();
        $variant->setPrimaryOwnerId($product->id);
        $variant->setOwnerId($product->id);
        $variant->isDefault = false;
        $variant->sku = 'BB-TEST-' . StringHelper::UUID();
        $variant->setBasePrice($basePrice);
        $variant->inventoryTracked = $stock !== null;

        if (!Craft::$app->getElements()->saveElement($variant, false)) {
            throw new RuntimeException('Could not save test variant: ' . implode(', ', $variant->getErrorSummary(true)));
        }

        if ($stock !== null) {
            ensureStoreHasInventoryLocation($variant->getStore()->id);
            Commerce::getInstance()->getInventory()->updatePurchasableInventoryLevel($variant, $stock);
        }

        return $variant;
    });
}

/**
 * Ensures the given store is linked to an inventory location, so stock set on a
 * purchasable in that store is visible via getStock(). The boilerplate test
 * database links its single Default location only to the secondary store, so
 * products created in the primary store would otherwise always read zero stock.
 * Linked through Commerce's service rather than raw SQL, as Commerce memoizes
 * each store's location IDs per request. Rolled back with the test transaction.
 *
 * @param int $storeId The store to link.
 */
function ensureStoreHasInventoryLocation(int $storeId): void
{
    $alreadyLinked = (new Query())
        ->from('{{%commerce_inventorylocations_stores}}')
        ->where(['storeId' => $storeId])
        ->exists();

    if ($alreadyLinked) {
        return;
    }

    $locationId = (int)(new Query())
        ->select('id')
        ->from('{{%commerce_inventorylocations}}')
        ->orderBy(['id' => SORT_ASC])
        ->scalar();

    if (!$locationId) {
        throw new RuntimeException('No Commerce inventory location exists in the test database.');
    }

    $commerce = Commerce::getInstance();
    $commerce->getInventoryLocations()->saveStoreInventoryLocations(
        $commerce->getStores()->getStoreById($storeId),
        [$locationId],
    );
}

/**
 * Runs a callback with Yii's deliberate @filemtime() suppression in FileCache
 * honoured. Commerce's catalog-pricing reads emit an @-suppressed filemtime()
 * warning on stale cache files; Pest's error handler otherwise promotes it to a
 * test failure. Only filemtime warnings are swallowed; anything else propagates.
 *
 * @template T
 * @param callable():T $callback The callback to run.
 * @return T The callback's return value.
 */
function withFileCacheWarningsSuppressed(callable $callback): mixed
{
    set_error_handler(
        static fn(int $errno, string $errstr): bool => str_contains($errstr, 'filemtime'),
        E_WARNING,
    );

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * Builds an unsaved bundle whose components are the given products, each at the
 * supplied quantity. Used to exercise the real (un-mocked) pricing subtotal.
 *
 * @param array<int, array{product: Product, qty: int}> $components
 */
function bundleWithComponents(array $components): \johnhenry\bundlebuilder\elements\Bundle
{
    $bundle = new \johnhenry\bundlebuilder\elements\Bundle();

    $bundle->setProducts(array_map(static fn(array $component): array => [
        'productId' => $component['product']->id,
        'qty' => $component['qty'],
    ], $components));

    return $bundle;
}

/**
 * Saves a bundle of the given type with the given component products, at the
 * given pricing strategy. Rolled back with the test transaction.
 *
 * @param array<int, array{product: Product, qty: int}> $components
 */
function makeSavedBundle(
    BundleType $type,
    array $components,
    string $pricingStrategy = PricingStrategy::Automatic->value,
): Bundle {
    return withFileCacheWarningsSuppressed(static function () use ($type, $components, $pricingStrategy): Bundle {
        $bundle = new Bundle();
        $bundle->typeId = $type->id;
        $bundle->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $bundle->title = 'Test Bundle ' . StringHelper::randomString(6);
        $bundle->enabled = true;
        $bundle->pricingStrategy = $pricingStrategy;
        $bundle->setProducts(array_map(static fn(array $component): array => [
            'productId' => $component['product']->id,
            'qty' => $component['qty'],
        ], $components));

        if (!Craft::$app->getElements()->saveElement($bundle, false)) {
            throw new RuntimeException('Could not save bundle: ' . implode(', ', $bundle->getErrorSummary(true)));
        }

        return $bundle;
    });
}

/**
 * Builds an unsaved order carrying the given line items, at the given
 * currency, in the primary store.
 *
 * @param LineItem[] $lineItems
 */
function orderWithLineItems(array $lineItems, string $currency = 'USD'): Order
{
    $order = new Order();
    $order->storeId = Commerce::getInstance()->getStores()->getPrimaryStore()->id;
    $order->currency = $currency;
    $order->setLineItems($lineItems);

    return $order;
}

/**
 * Builds a schema that can read the primary site, the given bundle types
 * and, optionally, every product type.
 *
 * @param BundleType[] $bundleTypes
 */
function bundleGqlSchema(array $bundleTypes, bool $withProductTypes = true): GqlSchema
{
    $scope = ['sites.' . Craft::$app->getSites()->getPrimarySite()->uid . ':read'];

    foreach ($bundleTypes as $bundleType) {
        $scope[] = GqlHelper::SCOPE_BUNDLE_TYPES . '.' . $bundleType->uid . ':read';
    }

    if ($withProductTypes) {
        foreach (Commerce::getInstance()->getProductTypes()->getAllProductTypes() as $productType) {
            $scope[] = 'productTypes.' . $productType->uid . ':read';
        }
    }

    return new GqlSchema(['name' => 'Bundle Builder test', 'scope' => $scope]);
}

/**
 * Runs a GraphQL query against the schema from a clean GraphQL state. Craft
 * memoizes the schema definition and every generated type for the whole
 * process, so without a reset one schema's types would answer for the next.
 * flushCaches() alone leaves two memos behind that hold types from before
 * the flush: the service's field layout arguments, so the service is replaced,
 * and Commerce's variant content arguments, so that memo is cleared.
 *
 * @return array<string, mixed>
 */
function runBundleGql(GqlSchema $schema, string $query): array
{
    Craft::$app->set('gql', new GqlService());
    $gql = Craft::$app->getGql();
    $gql->flushCaches();

    $variants = Commerce::getInstance()->getVariants();
    $memo = new \ReflectionProperty($variants, '_contentFieldCache');
    $memo->setValue($variants, []);

    // Debug mode, so validation errors come back instead of an empty result
    return withFileCacheWarningsSuppressed(static fn(): array => $gql->executeQuery($schema, $query, debugMode: true));
}

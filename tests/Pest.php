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

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\StringHelper;
use johnhenry\bundlebuilder\BundleBuilder;
use johnhenry\bundlebuilder\enums\TaxTreatment;
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
 * base price, reusing whichever product type already exists in the test
 * database. The RefreshesDatabase transaction rolls the product back on
 * teardown.
 *
 * @param float $basePrice The default variant's base price.
 * @param int|null $stock The default variant's tracked stock, or null for untracked.
 * @param float|null $promotionalPrice The default variant's promotional (sale) price, or null for none.
 */
function makeProduct(float $basePrice = 10.0, ?int $stock = null, ?float $promotionalPrice = null): Product
{
    $typeId = (int)(new Query())
        ->select('id')
        ->from('{{%commerce_producttypes}}')
        ->orderBy(['id' => SORT_ASC])
        ->scalar();

    if (!$typeId) {
        throw new RuntimeException('No Commerce product type exists in the test database.');
    }

    $elementsService = Craft::$app->getElements();

    return withFileCacheWarningsSuppressed(static function () use ($elementsService, $typeId, $basePrice, $stock, $promotionalPrice): Product {
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
 * Ensures the given store is linked to an inventory location, so stock set on a
 * purchasable in that store is visible via getStock(). The boilerplate test
 * database links its single Default location only to the secondary store, so
 * products created in the primary store would otherwise always read zero stock.
 * The inserted link is rolled back with the test transaction.
 *
 * @param int $storeId The store to link.
 */
function ensureStoreHasInventoryLocation(int $storeId): void
{
    $db = Craft::$app->getDb();

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

    $now = date('Y-m-d H:i:s');
    $db->createCommand()->insert('{{%commerce_inventorylocations_stores}}', [
        'storeId' => $storeId,
        'inventoryLocationId' => $locationId,
        'sortOrder' => 1,
        'dateCreated' => $now,
        'dateUpdated' => $now,
        'uid' => StringHelper::UUID(),
    ])->execute();
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

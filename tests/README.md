# Bundle Builder Tests

Tests use [Pest](https://pestphp.com/) on top of [`markhuot/craft-pest-core`](https://github.com/markhuot/craft-pest). Pest lives in the parent Craft project's `vendor/`; the plugin has no `vendor/` of its own, so all commands run from the project root.

## Layout

- `Feature/`: pure PHP tests that don't need a running Craft application: enum backing values and model validation rules that never touch the database.
- `Integration/`: tests that require a real Craft + Commerce environment. Each test is wrapped in a DB transaction via `RefreshesDatabase` and rolled back on teardown, so no test bundle types or pivot rows persist.

```
tests/
├── Pest.php                          # Bootstrap: applies TestCase to Integration/, shared helpers
├── Feature/
│   ├── EnumsTest.php                 # DiscountType / PricingStrategy / TaxTreatment backing values
│   ├── BundleProductTest.php         # BundleProduct model validation (required, qty min, integers)
│   └── BundleTypeTest.php            # BundleType defaults + taxTreatment range rule (no DB)
└── Integration/
    ├── BundlePricingTest.php         # calculatePrice() discount maths (mocked + real components), sale-aware subtotal
    ├── BundleTypeServiceTest.php     # BundleTypes save/lookup/delete + unique-handle/name validation
    ├── BundleElementTest.php         # getTaxCategoryId() apportioned-vs-own category swap (composite vs multiple supply)
    ├── BundleRecalculationTest.php   # getBundleIdsForProduct() + automatic price re-sync when a component price changes
    ├── BundleCartTest.php            # applyToLineItem() variant/options/snapshot/stock + getSelections()
    └── BundleTaxAdjusterTest.php     # apportionment adds up to the line tax, selling-price weighting, line discounts, mixed rates, reverse-charge exemption, getIsTaxable() gate, largest-remainder distribution
```

## Running

From the parent Craft project (not from inside the plugin folder):

```bash
ddev exec vendor/bin/pest plugins/craft-bundle-builder/tests \
  --test-directory=plugins/craft-bundle-builder/tests
```

To run a single suite:

```bash
ddev exec vendor/bin/pest plugins/craft-bundle-builder/tests/Feature
ddev exec vendor/bin/pest plugins/craft-bundle-builder/tests/Integration
```

To filter to a specific test file:

```bash
ddev exec vendor/bin/pest plugins/craft-bundle-builder/tests \
  --test-directory=plugins/craft-bundle-builder/tests --filter=BundlePricing
```

## Adding tests

- Anything that touches `Craft::$app`, `Commerce::getInstance()`, a `Bundle` element, or the database belongs in `Integration/`.
- Pure enum/model/value-object tests belong in `Feature/`; they run faster and need no DB.
- Shared helpers live in [`Pest.php`](Pest.php):
  - `makeBundleType()` / `resetBundleTypesCache()`: bundle-type persistence.
  - `makeProduct($basePrice, $stock, $promotionalPrice)`: saves a real Commerce product with a single default variant at the given base price, (optional, inventory-backed) stock, and (optional) promotional/sale price.
  - `bundleWithComponents([...])`: an unsaved bundle whose components are real products, for exercising the un-mocked pricing/cart paths.

### Fixture notes

- `makeProduct()` saves the product, then saves its default variant as a nested
  element explicitly (`setPrimaryOwnerId`/`setOwnerId`), mirroring Commerce's own
  `ProductFixture`. `Product::setVariants()` alone does not mark the variants
  attribute dirty, so the nested-element cascade never fires and the variant is
  silently dropped.
- Stock is derived from store-scoped inventory levels in Commerce 5, so
  `makeProduct()` sets it via the inventory service. The boilerplate database links
  its only inventory location to the secondary store, so the fixture links it to the
  product's store first (rolled back with the test transaction); without that,
  primary-store products always read zero stock.
- Saving a Commerce product triggers catalog-pricing reads that emit an
  `@`-suppressed `filemtime()` warning on stale `FileCache` files. Pest promotes
  suppressed warnings to failures, so the product save runs inside
  `withFileCacheWarningsSuppressed()`, which honours Yii's deliberate suppression
  for `filemtime` only and lets every other warning through.

### Why some pricing tests use an anonymous subclass

`BundlePricing::calculatePrice()` is the highest-value logic in the plugin, but its
input, `getComponentsSubtotal()`, depends on real Commerce products and variants.
To assert the percentage/flat/floor/round branches with exact figures,
[`BundlePricingTest.php`](Integration/BundlePricingTest.php) uses an anonymous
subclass that overrides `getComponentsSubtotal()` with a fixed value, isolating the
discount maths from the database. The empty-bundle case exercises the real
subtotal path (no products → `0.0`) without stubbing.

`BundlePricingTest.php` also covers the real path end to end (`BundlePricing: real
components`): saved products with real default-variant base prices, summed by
quantity and run through the discount maths. `BundlePricing: sale-aware pricing`
covers `getComponentPrice()` preferring a component's promotional price over its
base price, falling back when there isn't one, and a mix of both within one
bundle. `BundleRecalculationTest.php` covers the recalc service the queued
`RecalculateBundlePrices` job is built on: `getBundleIdsForProduct()`, and
`recalculateBundlesForProduct()` actually re-storing an automatic bundle's price
after a component price change while leaving a fixed-price bundle alone. **Not
covered:** the batched job's own queue lifecycle (the service methods it calls
are covered instead, since asserting a queue job ran needs a synchronous queue
driver this suite doesn't configure), and a sale or catalog pricing rule changing
*without* a component product save, a known gap, since Commerce recomputes catalog
pricing asynchronously and there's no cheap way to know which products a given
rule/sale change affects without duplicating that work.
`BundleCartTest.php` exercises
`applyToLineItem()` against real products: variant resolution, readable options, the
component snapshot, and the stock-shortfall validation (including the line-quantity
multiplier). The only path left to manual QA is the live inventory decrement at
order-complete (`decrementComponentStock()` actually reducing stock), since that
rides Commerce's order-completion lifecycle.

`BundleTaxAdjusterTest.php` covers apportionment reconciliation (each component's
tax always summing back to the tax on the whole line), selling-price weighting (a
component on sale takes a smaller share of the split), a discounted line (tax
follows the price the customer actually pays, not the pre-discount subtotal),
mixed tax rates within one bundle (including a zero rate), the reverse-charge
exemption (a validated tax ID meaning no tax is added, driven by seeding the same
validation cache Commerce's core adjuster uses), the `getIsTaxable()` skip, and
the largest-remainder distribution maths directly.
`BundleElementTest.php` covers `getTaxCategoryId()` returning the zero-rate
apportioned category for multiple-supply bundles and the bundle's own category
for composite ones. **Not covered:** the `removeIncluded` / `removeVatIncluded`
branch that produces a negative "tax removed" adjustment; Commerce only allows a
removable included rate on the *default* tax zone, which needs a saved `TaxZone` +
condition fixture this suite doesn't have yet. Left to manual QA until that
fixture exists.

## Static Analysis

```bash
ddev composer phpstan --working-dir=plugins/craft-bundle-builder
```

## Coding Standards

```bash
ddev composer check-cs --working-dir=plugins/craft-bundle-builder
ddev composer fix-cs --working-dir=plugins/craft-bundle-builder
```

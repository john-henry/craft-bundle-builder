# Release Notes for Bundle Builder

## 1.2.0 - 2026-09-25

### Added
- Bundle types have a Component Sources setting, to limit which products can be components.
- Bundle types have an Enable versioning setting, so bundle revisions can be kept and reverted to. It's off by default.
- Bundle lines on the CP order edit screen list the chosen components, variants and SKUs.
- A "Change variants" button on bundle lines lets you swap the chosen variants before an order is completed.
- Each bundle component can be limited to some of its product's variants.
- With Container Deposits installed, the cans, bottles and flagons chosen for a bundle are charged their container deposit.
- `Bundle::getPriceRange()` and `Bundle::getVariantPriceAdjustment()`, for "From" prices and each variant's price difference.
- `BundleProduct::getVariants()` and `BundleProduct::getDefaultVariant()`, which respect the variants a component offers.
- `BundleCart::getComponentVariants()`, for the variant chosen for each component of a bundle line.
- Support for [Navigation](https://verbb.io/craft-plugins/navigation) 4. If you upgrade Navigation after this update, run `php craft bundle-builder/navigation/upgrade-nodes`.
- Bundle product rows can now be reordered with the keyboard, not just by dragging.
- Bundles can now be queried with GraphQL, with a read permission for each bundle type.
- Bundles fields now return their bundles in GraphQL.
- The bundle query's `type` param now takes a list of bundle type handles as well as one.

### Changed
- The per-bundle-type permission is now `bundle-builder:manage-bundles:{bundleTypeUid}`. Existing grants are moved over, but update any `can()` checks in your own templates or code.
- Bundle Builder now requires Craft Commerce 5.3 or later.
- A bundle line's `bundleProducts` option now holds product and variant IDs. Read the titles from `lineItem.snapshot.bundleProducts` instead.
- An automatic bundle's price now follows the variants the customer picks. Show each variant's price difference or a "From" price in your templates.
- A product can only be added to a bundle once. Use its Qty instead.
- "Add a product" in the bundle editor opens the product picker straight away, and several products can be added at once.
- A live bundle needs at least one component.
- Component stock is committed at the first inventory location that can cover it, the same as for variants.
- A component's default variant is now the first variant it offers when it doesn't offer the product's default.
- A bundle's availability only counts variants its components offer that are for sale.
- Scheduled and expired bundles no longer have a page, except in preview.
- Saving a component variant now recalculates automatic bundle prices.
- An automatic bundle no longer keeps a promotional price from when it was fixed price.
- The installed package no longer includes the documentation site or the test suite.

### Deprecated
- `BundlePricing::getComponentPrice()`. Use `getComponentUnitPrice()` instead.

### Security
- Bundle drafts and revisions can no longer be added to a cart.
- A bundle's type can no longer be changed by a posted `typeId`.
- Changing a bundle line's variants on an order now needs the Manage orders permission as well as Edit orders.
- The "All bundles" source only lists bundle types the user can manage.
- Creating a bundle, and adding a component row, now check the permission for the bundle's type.
- Product drafts and revisions can no longer be bundle components.

### Fixed
- Component stock is now checked when the cart is saved, across every line using the same variant.
- A changed variant is now stock-checked against the new choice.
- Customers' variant choices are no longer replaced with the defaults when the cart is saved.
- A disabled or unavailable variant can no longer be chosen in the cart.
- Components whose variants allow out-of-stock purchases no longer make a bundle unavailable.
- Multiple-supply bundles are now taxed with every tax rate type, not only purchasable rates.
- A multiple-supply bundle whose chosen components all cost nothing is now taxed.
- Switching a bundle type to composite supply no longer leaves its bundles untaxed or taxed twice.
- A custom line item no longer stops an order being saved or recalculated.
- Applying a draft now keeps the bundle's SKU and brings its component changes onto the live bundle.
- Duplicating a bundle now copies its components.
- A component whose product was deleted now stays in the bundle editor, marked as deleted, and the bundle stays unavailable.
- Disabling a component product no longer lowers an automatic bundle's price.
- Automatic bundle prices are rounded to the store currency's minor unit.
- A bundle type's Automatic Description Format is now used for line item descriptions.
- A bundle type's per-site Default Status is now used for new and propagated bundles.
- Turning off a bundle type's Show the Slug field setting now hides the slug field.
- The last component in a bundle can now be removed.
- The bundle editor no longer flashes the other pricing strategy's inputs while loading.
- The component picker and pricing toggle now work in a slideout.
- A component row's remove button now works from the keyboard.
- The Promotional Price label is now tied to its input for screen readers.
- Saving a bundle type with no preview targets no longer throws an error.
- Deleting the last bundle type now shows the "No bundle types exist yet." message.
- A component that can't be found while pricing is now logged as a warning.
- Installing over existing Bundle Builder tables no longer fails.
- A Bundles field no longer breaks GraphQL queries on the elements it's on.

## 1.1.0 - 2026-07-11

### Added
- Bundles can be picked in [Hyper](https://verbb.io/craft-plugins/hyper) link fields when Hyper is installed.
- Bundles are a node type in [Navigation](https://verbb.io/craft-plugins/navigation) menus when Navigation is installed, enabled by default.

### Fixed
- Uninstalling now removes bundle elements, their field layouts and the apportioned tax category. Past orders keep their line items.

## 1.0.0 - 2026-07-05

### Added
- Bundles: a Commerce purchasable that sells several products as a single line item.
- Bundle types, each with its own field layout, SKU and description formats, per-site URLs, preview targets and tax treatment.
- Fixed pricing, with an optional promotional price.
- Automatic pricing: the components' sale prices summed, less a percentage or flat discount. Recalculated in the background when a component's price changes.
- Cart-time variant selection for each component, saved on the line item as options and a snapshot.
- Availability based on post and expiry dates and on every component having stock.
- Component variant stock comes down when an order completes.
- Composite or multiple supply tax treatment per bundle type. Multiple supply taxes each component at its own rate.
- Drafts, revisions, autosave and live preview through Craft's element editor.
- A `craft.bundleBuilder` Twig variable.

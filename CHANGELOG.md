# Release Notes for Bundle Builder

## 1.0.0 - 2026-07-05

First public release.

### Added
- Bundles as a first-class Commerce purchasable: group several products together and sell them as a single line item at a single price.
- Bundle types, each with their own field layout, SKU and description formats, per-site URL settings, preview targets, and tax treatment.
- Fixed pricing: set a price yourself, with an optional promotional price.
- Automatic pricing: the bundle price works itself out from the sum of its components, less a percentage or flat discount. It keeps the components' own sale and catalog-pricing-rule prices in mind, so a bundle of discounted parts never costs more than buying them separately, and it recalculates in the background when a component's price changes.
- Cart-time variant selection: the customer picks a variant for each component when they add the bundle to the cart. The bundle stays one line item, with the choices saved as readable line-item options and a frozen snapshot.
- Availability tied to a post and expiry window, and to each component having an in-stock variant.
- Component inventory: when the order is paid, each chosen variant's stock comes down. The bundle itself doesn't hold stock.
- Two ways of handling tax, set per bundle type. Composite supply taxes the whole bundle at its own rate. Multiple supply splits the price across the components and taxes each at its own rate, working with whatever tax scheme (VAT, GST, sales tax) you've set up in Commerce.
- Bundles are edited through Craft's own element editor, so you get live preview, drafts, revisions, and autosave.
- A `craft.bundleBuilder` Twig variable for the front end.

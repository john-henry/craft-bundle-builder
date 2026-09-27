[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-bundle-builder?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-bundle-builder)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/bundle-builder?craft5)

![Bundle Builder](https://johnhenry.ie/images/plugins/promos/bundle-builder/1.png)

# Bundle Builder for Craft Commerce

Product bundles for Craft Commerce 5. Sell a hamper, a starter kit or a set of books as one cart line at one price, with the customer picking the variant they want for each part. A bundle is a purchasable in its own right, with its own SKU and pricing. Stock comes off each part when the order completes, and tax works with whatever scheme you've set up in Commerce.

## Features

- **Bundle elements** edited through Craft's native element editor (live preview, drafts, autosave, and revisions when versioning is on for the bundle type).
- **Bundle types** with their own field layout, SKU/description formats, per-site URL settings, preview targets, and tax treatment.
- **Pricing**
  - *Fixed*: a set price (plus optional promotional price).
  - *Automatic*: the summed price of the components (each component's own sale or catalog-pricing-rule price, when one applies), less a percentage or flat discount. Recalculated automatically when a component product's price changes.
- **Cart-time variant selection**: the customer chooses a variant for each component; the bundle stays one line item, with the choices kept in its options and resolved into a snapshot with product and variant titles.
- **CP order editing**: each bundle line on an order lists the chosen components, and they can be changed until the order is completed.
- **Availability** gated by the post/expiry window and by each component having a variant that can cover its quantity, with component stock checked across the whole cart.
- **Inventory**: on order completion, each chosen component variant's stock is decremented (the bundle itself doesn't track inventory).
- **Tax treatment** (composite vs multiple supply, set per bundle type)
  - *Composite supply*: the whole bundle is taxed at one rate, using the store's default tax category.
  - *Multiple supply*: the price is apportioned across components and each is taxed at its own product's tax category rate, for any tax scheme configured in Commerce.
- **Twig API** via `craft.bundleBuilder`.

## Documentation

Full documentation is at [johnhenry.ie/plugins/bundle-builder/docs](https://johnhenry.ie/plugins/bundle-builder/docs/getting-started/overview).

## Requirements

- Craft CMS 5.0 or later
- Craft Commerce 5.3 or later
- PHP 8.2 or later

## Accessibility

How accessible the plugin is, what's been checked, and how to report a problem are all in the [accessibility statement](https://github.com/john-henry/craft-bundle-builder/blob/craft-5/ACCESSIBILITY.md).

## Support

Need a hand? Open an issue on the [GitHub Issues page](https://github.com/john-henry/craft-bundle-builder/issues).

## License

Proprietary. Copyright (c) 2026 John Henry Donovan.

---

<a href="https://johnhenry.ie/plugins/" target="_blank">
    <img height="46" src="https://johnhenry.ie/images/plugins/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>

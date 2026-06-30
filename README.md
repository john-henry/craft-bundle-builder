[![Stable Version](https://img.shields.io/packagist/v/johnhenry/craft-bundle-builder?label=stable&style=for-the-badge)](https://packagist.org/packages/johnhenry/craft-bundle-builder)
[![Static Badge](https://img.shields.io/badge/BUY-plugin?style=for-the-badge&logo=craftcms&logoColor=white&logoSize=auto&label=Craft%20Plugin%20Store&labelColor=%23E5422B)](https://plugins.craftcms.com/bundle-builder?craft5)

<p align="center"><img width="120" height="120" alt="craft-bundle-builder-plugin-icon" src="https://plugins.johnhenry.ie/icons/craft-bundle-builder.svg"></p>

<h1 align="center">Bundle Builder for Craft Commerce</h1>



Product bundles for Craft Commerce 5. A **bundle** is a first-class purchasable element that groups several products together and sells as a **single line item** — with flexible pricing, cart-time variant selection, component inventory tracking, and Irish-VAT-aware tax handling.

## Features

- **Bundle elements** edited through Craft's native element editor (live preview, drafts, revisions, autosave).
- **Bundle types** with their own field layout, SKU/description formats, per-site URL settings, preview targets, and tax treatment.
- **Pricing**
  - *Fixed* — a set price (plus optional promotional price).
  - *Automatic* — the summed base price of the components, less a percentage or flat discount. Recalculated automatically when a component product's price changes.
- **Cart-time variant selection** — the customer chooses a variant for each component; the bundle stays one line item, with the choices stored as readable line-item options and a frozen snapshot.
- **Availability** gated by the post/expiry window and by each component having an in-stock variant.
- **Inventory** — on order completion, each chosen component variant's stock is decremented (the bundle itself doesn't track inventory).
- **VAT treatment** (Irish Revenue mixed-supply rules)
  - *Composite supply* — the whole bundle is taxed at the bundle's own tax category (principal element rate).
  - *Multiple supply* — the price is apportioned across components and each is taxed at its own product's tax category rate.
- **Twig API** via `craft.bundleBuilder`.

## Documentation
Full documentation can be found at [https://johnhenry.ie/plugins/bundle-builder/](https://johnhenry.ie/plugins/bundle-builder/)

## Support
For support, please visit the [GitHub Issues page](https://github.com/john-henry/craft-bundle-builder/issues)

## License
Proprietary - Copyright (c) 2026 John Henry Donovan

## Requirements

- Craft CMS 5.0+
- Craft Commerce 5.0+
- PHP 8.2+

---

<a href="https://plugins.johnhenry.ie" target="_blank">
    <img height="46" src="https://plugins.johnhenry.ie/images/logo.svg" alt="John Henry - Craft CMS Plugins">
</a>

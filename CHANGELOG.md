# Release Notes for Bundle Builder

## 1.1.0 - 2026-07-11

### Added
- Bundles can now be picked in a [Hyper](https://verbb.io/craft-plugins/hyper) link field. If you have Hyper installed, "Bundle" shows up as a link type alongside entries and products, so you can point a link straight at a bundle. Nothing to turn on: it appears once Hyper is enabled, and stays out of the way if it isn't. It uses the same picker as the other element link types, so there's nothing new to learn.
- Bundles can now be added as nodes in [Navigation](https://verbb.io/craft-plugins/navigation) menus. If you have Navigation installed, "Bundles" turns up in a menu's node types by default, so you can drop a bundle straight into a menu the same way you would an entry or a product.

### Fixed
- Uninstalling the plugin now clears up properly after itself. Before, it dropped its own tables but left the bundle elements, their field layouts, and the tax category behind. Now the lot is removed on uninstall. Your orders are untouched: any past order that included a bundle keeps its line items exactly as they were.

## 1.0.0 - 2026-07-05

First public release.

<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

/**
 * Bundle Builder English translations.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
return [
    // General
    // =========================================================================
    'Bundle Builder' => 'Bundle Builder',
    'Bundle' => 'Bundle',
    'bundle' => 'bundle',
    'Bundles' => 'Bundles',
    'Bundle Types' => 'Bundle Types',
    'All bundles' => 'All bundles',
    'Add a bundle' => 'Add a bundle',
    'Type' => 'Type',
    'Components' => 'Components',
    'Product' => 'Product',
    'Qty' => 'Qty',
    'Quantity' => 'Quantity',

    // Bundle types — CP
    // =========================================================================
    'New bundle type' => 'New bundle type',
    'Create a Bundle Type' => 'Create a Bundle Type',
    'Edit Bundle Type' => 'Edit Bundle Type',
    'No bundle types exist yet.' => 'No bundle types exist yet.',
    'Name' => 'Name',
    'Handle' => 'Handle',
    'Settings' => 'Settings',
    'Field Layout' => 'Field Layout',
    'What this bundle type will be called in the control panel.' => 'What this bundle type will be called in the control panel.',
    'How you’ll refer to this bundle type in templates.' => 'How you’ll refer to this bundle type in templates.',
    'Automatic SKU Format' => 'Automatic SKU Format',
    'Optional. Used to generate a bundle’s SKU if left blank. Accepts the same object template tags as products (e.g. `{slug}`).' => 'Optional. Used to generate a bundle’s SKU if left blank. Accepts the same object template tags as products (e.g. `{slug}`).',
    'Automatic Description Format' => 'Automatic Description Format',
    'Optional. Used as the line-item description if set. Defaults to the bundle’s title.' => 'Optional. Used as the line-item description if set. Defaults to the bundle’s title.',
    'Show the Slug field' => 'Show the Slug field',
    'Whether the slug field is shown when editing bundles of this type.' => 'Whether the slug field is shown when editing bundles of this type.',
    'Are you sure you want to delete “{name}” and all of its bundles?' => 'Are you sure you want to delete “{name}” and all of its bundles?',
    'Bundle type saved.' => 'Bundle type saved.',
    'Bundle type not found.' => 'Bundle type not found.',
    'Couldn’t save bundle type.' => 'Couldn’t save bundle type.',
    'Could not delete bundle type.' => 'Could not delete bundle type.',

    // Site settings & preview targets
    // =========================================================================
    'Choose which sites this bundle type should be available in, and configure the site-specific settings.' => 'Choose which sites this bundle type should be available in, and configure the site-specific settings.',
    'Bundle URI Format' => 'Bundle URI Format',
    'What bundle URIs should look like for the site.' => 'What bundle URIs should look like for the site.',
    'Leave blank if bundles don’t have URLs' => 'Leave blank if bundles don’t have URLs',
    'Which template should be loaded when a bundle’s URL is requested.' => 'Which template should be loaded when a bundle’s URL is requested.',
    'Add a preview target' => 'Add a preview target',
    'Locations that should be available for previewing bundles of this type. Use `{url}` for the bundle’s own URL.' => 'Locations that should be available for previewing bundles of this type. Use `{url}` for the bundle’s own URL.',
    'What the preview URL should look like. Defaults to the bundle’s own URL.' => 'What the preview URL should look like. Defaults to the bundle’s own URL.',
    'Primary bundle page' => 'Primary bundle page',

    // VAT / tax treatment
    // =========================================================================
    'VAT / Tax treatment' => 'VAT / Tax treatment',
    'How bundles of this type are taxed. **Composite supply** (default): the whole bundle is taxed at the bundle’s own tax category — use when one element is principal and the others are ancillary. **Multiple supply**: the price is apportioned across components and each is taxed at its own product’s tax category — use for independent items sold together (e.g. a bag + a collection service at different VAT rates).' => 'How bundles of this type are taxed. **Composite supply** (default): the whole bundle is taxed at the bundle’s own tax category — use when one element is principal and the others are ancillary. **Multiple supply**: the price is apportioned across components and each is taxed at its own product’s tax category — use for independent items sold together (e.g. a bag + a collection service at different VAT rates).',
    'Composite supply (single rate)' => 'Composite supply (single rate)',
    'Multiple supply (apportion VAT per component)' => 'Multiple supply (apportion VAT per component)',

    // Bundles — CP
    // =========================================================================
    'New bundle' => 'New bundle',
    'Add a product' => 'Add a product',
    'The products included in this bundle. Customers choose a variant for each at checkout.' => 'The products included in this bundle. Customers choose a variant for each at checkout.',
    'Bundle saved.' => 'Bundle saved.',
    'Bundle created.' => 'Bundle created.',
    'Bundle not found.' => 'Bundle not found.',
    'Couldn’t create bundle.' => 'Couldn’t create bundle.',
    'No editable bundle types exist.' => 'No editable bundle types exist.',
    'This bundle type has no front-end template.' => 'This bundle type has no front-end template.',
    'You don’t have permission to edit this bundle.' => 'You don’t have permission to edit this bundle.',
    'You don’t have permission to save this bundle.' => 'You don’t have permission to save this bundle.',
    'You don’t have permission to delete this bundle.' => 'You don’t have permission to delete this bundle.',

    // Pricing
    // =========================================================================
    'Pricing' => 'Pricing',
    'Pricing strategy' => 'Pricing strategy',
    'Fixed price' => 'Fixed price',
    'Automatic (sum of components − discount)' => 'Automatic (sum of components − discount)',
    'Bundle price' => 'Bundle price',
    'Promotional Price' => 'Promotional Price',
    'Discount type' => 'Discount type',
    'Discount amount' => 'Discount amount',
    'Percentage off' => 'Percentage off',
    'Flat amount off' => 'Flat amount off',
    'For percentage, enter e.g. 10 for 10%.' => 'For percentage, enter e.g. 10 for 10%.',

    // Permissions
    // =========================================================================
    'Manage “{type}” bundles' => 'Manage “{type}” bundles',

    // Cart / orders
    // =========================================================================
    '“{product}” isn’t available in the requested quantity.' => '“{product}” isn’t available in the requested quantity.',
    'Bundle order: {sku}' => 'Bundle order: {sku}',
];

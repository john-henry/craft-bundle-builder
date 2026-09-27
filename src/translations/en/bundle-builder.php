<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

/**
 * Bundle Builder English translations.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
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
    'Bundle components' => 'Bundle components',
    'Change variants' => 'Change variants',
    'Change bundle variants' => 'Change bundle variants',
    'Save' => 'Save',
    'Cancel' => 'Cancel',
    'Save or discard your changes to the order before changing bundle variants.' => 'Save or discard your changes to the order before changing bundle variants.',
    'Couldn’t change the variants.' => 'Couldn’t change the variants.',
    'Variants changed.' => 'Variants changed.',
    'Order not found.' => 'Order not found.',
    'User not authorized to edit this order.' => 'User not authorized to edit this order.',
    'Variants can only be changed before the order is completed.' => 'Variants can only be changed before the order is completed.',
    'That bundle line isn’t on this order.' => 'That bundle line isn’t on this order.',
    'Choose a variant for “{product}”.' => 'Choose a variant for “{product}”.',
    'Another line already has these choices. Change its quantity instead.' => 'Another line already has these choices. Change its quantity instead.',
    'Couldn’t save the order.' => 'Couldn’t save the order.',
    'This bundle is no longer available, so its variants can’t be changed.' => 'This bundle is no longer available, so its variants can’t be changed.',
    'Qty' => 'Qty',
    'Quantity' => 'Quantity',

    // Bundle types (CP)
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

    // Tax treatment
    // =========================================================================
    'Tax treatment' => 'Tax treatment',
    'How bundles of this type are taxed. **Composite supply** (default): the whole bundle is taxed at one rate, using the store’s default tax category; use when one element is principal and the others are ancillary. **Multiple supply**: the price is apportioned across components and each is taxed at its own product’s tax category; use for independent items sold together (e.g. a bag + a collection service at different tax rates).' => 'How bundles of this type are taxed. **Composite supply** (default): the whole bundle is taxed at one rate, using the store’s default tax category; use when one element is principal and the others are ancillary. **Multiple supply**: the price is apportioned across components and each is taxed at its own product’s tax category; use for independent items sold together (e.g. a bag + a collection service at different tax rates).',
    'Composite supply (single rate)' => 'Composite supply (single rate)',
    'Multiple supply (apportion tax per component)' => 'Multiple supply (apportion tax per component)',

    // Bundles (CP)
    // =========================================================================
    'New bundle' => 'New bundle',
    'Add a product' => 'Add a product',
    'Couldn’t add a product row.' => 'Couldn’t add a product row.',
    'Component Sources' => 'Component Sources',
    '“{product}” isn’t in one of this bundle type’s component sources.' => '“{product}” isn’t in one of this bundle type’s component sources.',
    'Which sources can bundle components be picked from?' => 'Which sources can bundle components be picked from?',
    'Enable versioning for bundles of this type' => 'Enable versioning for bundles of this type',
    'Whether a revision is saved each time a bundle of this type is saved, so earlier versions can be viewed and reverted to.' => 'Whether a revision is saved each time a bundle of this type is saved, so earlier versions can be viewed and reverted to.',
    'Add at least one product to the bundle.' => 'Add at least one product to the bundle.',
    'This product has been deleted. Restore it, or remove it from the bundle.' => 'This product has been deleted. Restore it, or remove it from the bundle.',
    'Each product can only be added once. Use Qty to include more than one.' => 'Each product can only be added once. Use Qty to include more than one.',
    'The products included in this bundle. Customers choose a variant for each at checkout.' => 'The products included in this bundle. Customers choose a variant for each at checkout.',
    'Bundle created.' => 'Bundle created.',
    'Couldn’t create bundle.' => 'Couldn’t create bundle.',
    'User not authorized to manage bundles of any type.' => 'User not authorized to manage bundles of any type.',
    'No editable bundle type exists.' => 'No editable bundle type exists.',

    // Pricing
    // =========================================================================
    'Pricing' => 'Pricing',
    'Strategy' => 'Strategy',
    'Fixed price' => 'Fixed price',
    'Automatic (sum of components − discount)' => 'Automatic (sum of components − discount)',
    'Bundle price' => 'Bundle price',
    'Promotional Price' => 'Promotional Price',
    'Discount type' => 'Discount type',
    'Discount amount' => 'Discount amount',
    'Percentage off' => 'Percentage off',
    'Flat amount off' => 'Flat amount off',
    'For percentage, enter e.g. 10 for 10%.' => 'For percentage, enter e.g. 10 for 10%.',
    'A percentage discount can’t exceed 100%.' => 'A percentage discount can’t exceed 100%.',

    // Permissions
    // =========================================================================
    'Manage “{type}” bundles' => 'Manage “{type}” bundles',

    // GraphQL
    // =========================================================================
    'Query for bundles in the “{name}” bundle type' => 'Query for bundles in the “{name}” bundle type',

    // Cart / orders
    // =========================================================================
    '“{product}” isn’t available in the requested quantity.' => '“{product}” isn’t available in the requested quantity.',
    'Bundle order: {sku}' => 'Bundle order: {sku}',

    // Jobs
    // =========================================================================
    'Recalculating bundle prices' => 'Recalculating bundle prices',
    'Variants' => 'Variants',
    'The variants customers can choose from.' => 'The variants customers can choose from.',
    'All, including new ones' => 'All, including new ones',
    'not for sale' => 'not for sale',
    'Choose at least one variant of “{product}” that’s available for purchase.' => 'Choose at least one variant of “{product}” that’s available for purchase.',
    'Only products can be bundle components, not drafts or revisions of them.' => 'Only products can be bundle components, not drafts or revisions of them.',
    'User not authorized to manage bundles of this type.' => 'User not authorized to manage bundles of this type.',
    'Couldn’t update the product row.' => 'Couldn’t update the product row.',
    'Product moved to position {position} of {total}.' => 'Product moved to position {position} of {total}.',
];

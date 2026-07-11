<?php

/**
 * Coverage for the Navigation node-type registration.
 *
 * Navigation auto-discovers every element type whose hasUris() returns true, so
 * bundles already turn up in its registered elements. The plugin's job is to
 * flip that auto-discovered entry on by default (Navigation leaves it toggled
 * off), and to do so without appending a second Bundle entry, which would
 * duplicate it in a menu's node types. Both are pinned here: exactly one Bundle
 * entry, and it defaults to enabled.
 *
 * Navigation is a soft dependency: the plugin registers this only when
 * Navigation is enabled, so these are skipped when it isn't.
 */

use johnhenry\bundlebuilder\elements\Bundle;
use verbb\navigation\Navigation;

describe('Navigation Bundle node type', function () {
    beforeEach(function () {
        if (!Craft::$app->getPlugins()->isPluginEnabled('navigation')) {
            $this->markTestSkipped('Navigation is not installed.');
        }
    });

    it('registers the Bundle element exactly once, enabled by default', function () {
        $elements = Navigation::getInstance()->getElements()->getRegisteredElements(false);

        $bundleEntries = array_values(array_filter(
            $elements,
            static fn(array $element): bool => ($element['type'] ?? null) === Bundle::class,
        ));

        expect($bundleEntries)->toHaveCount(1);
        expect($bundleEntries[0]['default'] ?? null)->toBeTrue();
    });
});

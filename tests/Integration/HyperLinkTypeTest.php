<?php

/**
 * Coverage for the Hyper "Bundle" link type.
 *
 * Two things are worth pinning. First, that the link type is registered with
 * Hyper at all, so bundles can be picked in a Hyper field. Second, that its
 * settings HTML actually renders: Hyper's base ElementLink derives the settings
 * template path from the class name (hyper/links/bundle/settings), which only
 * exists for Hyper's own built-in types, so the link type overrides
 * getSettingsHtml() to render the shared element template instead.
 *
 * Hyper is a soft dependency: the plugin registers the link type only when Hyper
 * is enabled, so these are skipped when it isn't.
 */

use craft\web\View;
use johnhenry\bundlebuilder\links\Bundle as BundleLink;
use verbb\hyper\Hyper;

describe('Hyper Bundle link type', function () {
    beforeEach(function () {
        if (!Craft::$app->getPlugins()->isPluginEnabled('hyper')) {
            $this->markTestSkipped('Hyper is not installed.');
        }
    });

    it('is registered with Hyper', function () {
        $linkTypes = Hyper::getInstance()->getLinks()->getAllLinkTypes();

        expect($linkTypes)->toContain(BundleLink::class);
    });

    it('renders its settings HTML without falling back to a missing per-type template', function () {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        try {
            $html = (new BundleLink())->getSettingsHtml();
        } finally {
            $view->setTemplateMode($oldMode);
        }

        expect($html)->toBeString()->not->toBeEmpty();
    });
});

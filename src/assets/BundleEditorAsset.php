<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control panel assets for the bundle editor's components and pricing fields.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.2.0
 */
class BundleEditorAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @return void
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.2.0
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/bundle-editor.js',
        ];

        $this->css = [
            'css/bundle-editor.css',
        ];

        parent::init();
    }
}

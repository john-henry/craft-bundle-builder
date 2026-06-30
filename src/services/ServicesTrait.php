<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\services;

use yii\base\InvalidConfigException;

/**
 * ServicesTrait
 *
 * Wires the plugin's service components and exposes typed accessors that narrow
 * Yii's `Component::get()` return type for static analysis.
 *
 * @property BundleCart $bundleCart
 * @property BundlePricing $bundlePricing
 * @property BundleTypes $bundleTypes
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the plugin's service component configuration.
     *
     * @return array The plugin's component configuration.
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'bundleTypes' => BundleTypes::class,
                'bundlePricing' => BundlePricing::class,
                'bundleCart' => BundleCart::class,
            ],
        ];
    }

    /**
     * Returns the bundle cart service.
     *
     * @return BundleCart The bundle cart service.
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleCart(): BundleCart
    {
        $component = $this->get('bundleCart');
        assert($component instanceof BundleCart);
        return $component;
    }

    /**
     * Returns the bundle pricing service.
     *
     * @return BundlePricing The bundle pricing service.
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundlePricing(): BundlePricing
    {
        $component = $this->get('bundlePricing');
        assert($component instanceof BundlePricing);
        return $component;
    }

    /**
     * Returns the bundle types service.
     *
     * @return BundleTypes The bundle types service.
     * @throws InvalidConfigException
     * @author JohnHenry <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getBundleTypes(): BundleTypes
    {
        $component = $this->get('bundleTypes');
        assert($component instanceof BundleTypes);
        return $component;
    }
}

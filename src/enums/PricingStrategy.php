<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\enums;

/**
 * Pricing strategy.
 *
 * Determines how a bundle's price is calculated: a fixed price entered by the
 * merchandiser, or a price computed automatically from the bundle's components.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
enum PricingStrategy: string
{
    case Fixed = 'fixed';
    case Automatic = 'automatic';
}

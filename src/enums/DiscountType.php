<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\enums;

/**
 * Discount type.
 *
 * Determines how an automatic bundle discount is applied to the summed price of
 * the bundle's components: as a percentage of the sum, or as a flat amount off.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case Flat = 'flat';
}

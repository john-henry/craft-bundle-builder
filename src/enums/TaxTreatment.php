<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\enums;

/**
 * Tax treatment.
 *
 * How a bundle is treated for VAT, following Irish Revenue's mixed-supply rules:
 * a composite supply is taxed wholly at the rate of its principal element, while
 * a multiple supply has its price apportioned across components, each taxed at
 * its own rate.
 *
 * @author JohnHenry <info@johnhenry.ie>
 * @since 1.0.0
 */
enum TaxTreatment: string
{
    case Composite = 'composite';
    case Multiple = 'multiple';
}

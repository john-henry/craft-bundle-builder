<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\bundlebuilder\enums;

/**
 * Tax treatment.
 *
 * How a bundle is taxed, following the composite-vs-multiple-supply distinction
 * used across most VAT, GST and sales-tax regimes: a composite supply is taxed
 * wholly at the rate of its principal element, while a multiple supply has its
 * price apportioned across components, each taxed at its own component's rate.
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
enum TaxTreatment: string
{
    case Composite = 'composite';
    case Multiple = 'multiple';
}

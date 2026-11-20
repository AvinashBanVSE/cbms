<?php

declare(strict_types=1);

namespace App\Configuration;

final class GlobalBusinessSettings
{
    public const BUSINESS_TIME_ZONE = 'Asia/Kolkata';

    public const CURRENCY = 'INR';

    public const STABLE_CORE = [
        'country' => 'IN',
        'currency' => self::CURRENCY,
        'time_zone' => self::BUSINESS_TIME_ZONE,
        'number_grouping' => 'Indian',
        'calendar' => 'Gregorian',
        'measurement_system' => 'Metric',
    ];

    public const PLATFORM_DEFAULTS = [
        'language' => 'en',
        'financial_year_start' => '04-01',
        'financial_year_end' => '03-31',
    ];

    // Factors express each unit in the metric base unit for its dimension.
    public const UNIT_FACTORS = [
        'mass' => ['kg' => 1, 'g' => 0.001, 't' => 1000],
        'length' => ['m' => 1, 'cm' => 0.01, 'mm' => 0.001],
        'volume' => ['L' => 1, 'mL' => 0.001],
        'area' => ['m²' => 1, 'ft²' => 0.09290304, 'acre' => 4046.8564224],
    ];

    public const ADDRESS_FIELDS = ['locality', 'district', 'state_or_union_territory', 'pin_code'];
}

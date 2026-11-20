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

    public const REFERENCE_UNITS = [
        'mass' => ['kg', 'g', 't'],
        'length' => ['m', 'cm', 'mm'],
        'volume' => ['L', 'mL'],
        'area' => ['m²', 'ft²', 'acre'],
    ];

    public const ADDRESS_FIELDS = ['locality', 'district', 'state_or_union_territory', 'pin_code'];
}

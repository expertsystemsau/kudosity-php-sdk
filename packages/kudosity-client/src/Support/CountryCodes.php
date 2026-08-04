<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Support;

/**
 * Country code mappings for phone number formatting.
 *
 * Maps ISO 3166-1 alpha-2 country codes to international dialing codes.
 */
final class CountryCodes
{
    /**
     * ISO 3166-1 alpha-2 to international dialing code mapping.
     *
     * @var array<string, string>
     */
    public const CODES = [
        'AU' => '61',   // Australia
        'NZ' => '64',   // New Zealand
        'US' => '1',    // United States
        'CA' => '1',    // Canada
        'GB' => '44',   // United Kingdom
        'UK' => '44',   // United Kingdom (alias)
        'IE' => '353',  // Ireland
        'SG' => '65',   // Singapore
        'HK' => '852',  // Hong Kong
        'MY' => '60',   // Malaysia
        'PH' => '63',   // Philippines
        'ID' => '62',   // Indonesia
        'TH' => '66',   // Thailand
        'VN' => '84',   // Vietnam
        'IN' => '91',   // India
        'PK' => '92',   // Pakistan
        'BD' => '880',  // Bangladesh
        'LK' => '94',   // Sri Lanka
        'NP' => '977',  // Nepal
        'JP' => '81',   // Japan
        'KR' => '82',   // South Korea
        'CN' => '86',   // China
        'TW' => '886',  // Taiwan
        'DE' => '49',   // Germany
        'FR' => '33',   // France
        'IT' => '39',   // Italy
        'ES' => '34',   // Spain
        'PT' => '351',  // Portugal
        'NL' => '31',   // Netherlands
        'BE' => '32',   // Belgium
        'AT' => '43',   // Austria
        'CH' => '41',   // Switzerland
        'SE' => '46',   // Sweden
        'NO' => '47',   // Norway
        'DK' => '45',   // Denmark
        'FI' => '358',  // Finland
        'PL' => '48',   // Poland
        'CZ' => '420',  // Czech Republic
        'GR' => '30',   // Greece
        'RU' => '7',    // Russia
        'UA' => '380',  // Ukraine
        'ZA' => '27',   // South Africa
        'EG' => '20',   // Egypt
        'NG' => '234',  // Nigeria
        'KE' => '254',  // Kenya
        'AE' => '971',  // United Arab Emirates
        'SA' => '966',  // Saudi Arabia
        'QA' => '974',  // Qatar
        'KW' => '965',  // Kuwait
        'BH' => '973',  // Bahrain
        'OM' => '968',  // Oman
        'IL' => '972',  // Israel
        'TR' => '90',   // Turkey
        'MX' => '52',   // Mexico
        'BR' => '55',   // Brazil
        'AR' => '54',   // Argentina
        'CL' => '56',   // Chile
        'CO' => '57',   // Colombia
        'PE' => '51',   // Peru
        'VE' => '58',   // Venezuela
        'FJ' => '679',  // Fiji
        'PG' => '675',  // Papua New Guinea
        'NC' => '687',  // New Caledonia
        'WS' => '685',  // Samoa
        'TO' => '676',  // Tonga
        'VU' => '678',  // Vanuatu
    ];

    /**
     * Country name to ISO code mapping.
     *
     * @var array<string, string>
     */
    public const NAMES = [
        'AUSTRALIA' => 'AU',
        'NEW ZEALAND' => 'NZ',
        'UNITED STATES' => 'US',
        'USA' => 'US',
        'CANADA' => 'CA',
        'UNITED KINGDOM' => 'GB',
        'UK' => 'GB',
        'GREAT BRITAIN' => 'GB',
        'IRELAND' => 'IE',
        'SINGAPORE' => 'SG',
        'HONG KONG' => 'HK',
        'MALAYSIA' => 'MY',
        'PHILIPPINES' => 'PH',
        'INDONESIA' => 'ID',
        'THAILAND' => 'TH',
        'VIETNAM' => 'VN',
        'INDIA' => 'IN',
        'PAKISTAN' => 'PK',
        'BANGLADESH' => 'BD',
        'SRI LANKA' => 'LK',
        'NEPAL' => 'NP',
        'JAPAN' => 'JP',
        'SOUTH KOREA' => 'KR',
        'KOREA' => 'KR',
        'CHINA' => 'CN',
        'TAIWAN' => 'TW',
        'GERMANY' => 'DE',
        'FRANCE' => 'FR',
        'ITALY' => 'IT',
        'SPAIN' => 'ES',
        'PORTUGAL' => 'PT',
        'NETHERLANDS' => 'NL',
        'BELGIUM' => 'BE',
        'AUSTRIA' => 'AT',
        'SWITZERLAND' => 'CH',
        'SWEDEN' => 'SE',
        'NORWAY' => 'NO',
        'DENMARK' => 'DK',
        'FINLAND' => 'FI',
        'POLAND' => 'PL',
        'CZECH REPUBLIC' => 'CZ',
        'GREECE' => 'GR',
        'RUSSIA' => 'RU',
        'UKRAINE' => 'UA',
        'SOUTH AFRICA' => 'ZA',
        'EGYPT' => 'EG',
        'NIGERIA' => 'NG',
        'KENYA' => 'KE',
        'UNITED ARAB EMIRATES' => 'AE',
        'UAE' => 'AE',
        'SAUDI ARABIA' => 'SA',
        'QATAR' => 'QA',
        'KUWAIT' => 'KW',
        'BAHRAIN' => 'BH',
        'OMAN' => 'OM',
        'ISRAEL' => 'IL',
        'TURKEY' => 'TR',
        'MEXICO' => 'MX',
        'BRAZIL' => 'BR',
        'ARGENTINA' => 'AR',
        'CHILE' => 'CL',
        'COLOMBIA' => 'CO',
        'PERU' => 'PE',
        'VENEZUELA' => 'VE',
        'FIJI' => 'FJ',
        'PAPUA NEW GUINEA' => 'PG',
        'NEW CALEDONIA' => 'NC',
        'SAMOA' => 'WS',
        'TONGA' => 'TO',
        'VANUATU' => 'VU',
    ];

    /**
     * Get the international dialing code for a country.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 code or country name
     * @return string|null The dialing code or null if not found
     */
    public static function getDialingCode(string $countryCode): ?string
    {
        $normalized = strtoupper(trim($countryCode));

        // Check if it's a country name
        if (isset(self::NAMES[$normalized])) {
            $normalized = self::NAMES[$normalized];
        }

        return self::CODES[$normalized] ?? null;
    }

    /**
     * Check if a country code is supported.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 code or country name
     */
    public static function isSupported(string $countryCode): bool
    {
        return self::getDialingCode($countryCode) !== null;
    }

    /**
     * Get the ISO code from a country name or code.
     *
     * @param  string  $countryCode  ISO 3166-1 alpha-2 code or country name
     */
    public static function normalizeToIso(string $countryCode): ?string
    {
        $normalized = strtoupper(trim($countryCode));

        // Check if it's already an ISO code
        if (isset(self::CODES[$normalized])) {
            return $normalized;
        }

        // Check if it's a country name
        return self::NAMES[$normalized] ?? null;
    }

    /**
     * Check if a phone number starts with a known country dialing code.
     *
     * This checks against all known dialing codes in our mapping,
     * sorted by length (longest first) to handle codes like '1' vs '61'.
     *
     * @param  string  $number  The phone number (digits only, no + prefix)
     * @return string|null The matched dialing code, or null if none match
     */
    public static function matchDialingCode(string $number): ?string
    {
        // Get unique dialing codes sorted by length (longest first)
        // This ensures '61' matches before '6', '44' before '4', etc.
        $dialingCodes = array_unique(array_values(self::CODES));
        usort($dialingCodes, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($dialingCodes as $code) {
            if (str_starts_with($number, $code)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Check if a number starts with any known country dialing code.
     *
     * @param  string  $number  The phone number (digits only, no + prefix)
     */
    public static function startsWithKnownDialingCode(string $number): bool
    {
        return self::matchDialingCode($number) !== null;
    }
}

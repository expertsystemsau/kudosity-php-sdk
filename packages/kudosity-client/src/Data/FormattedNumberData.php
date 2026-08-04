<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Data transfer object for format-number response.
 *
 * Note: Phone number fields (countryCode, nationalNumber, international) are stored as strings
 * to avoid integer overflow issues on 32-bit systems where E.164 numbers may exceed PHP_INT_MAX.
 */
final readonly class FormattedNumberData
{
    /**
     * Number type constants.
     */
    public const TYPE_LANDLINE = 0;

    public const TYPE_MOBILE = 1;

    public const TYPE_INVALID = 10;

    /**
     * @param  string  $countryCode  The country calling code (e.g., "61" for Australia)
     * @param  string  $nationalNumber  The national number without country code
     * @param  string  $international  The full international number in E.164 format (without +)
     * @param  int  $type  Number type: TYPE_LANDLINE (0), TYPE_MOBILE (1), or TYPE_INVALID (10)
     * @param  bool  $isValid  Whether the number is valid
     */
    public function __construct(
        public string $countryCode,
        public string $nationalNumber,
        public string $international,
        public int $type,
        public bool $isValid,
    ) {}

    /**
     * Create from API response array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $number = $data['number'] ?? $data;

        return new self(
            countryCode: (string) $number['countrycode'],
            nationalNumber: (string) $number['nationalnumber'],
            international: (string) $number['international'],
            type: (int) $number['type'],
            isValid: (bool) $number['isValid'],
        );
    }

    /**
     * Check if this is a mobile number.
     */
    public function isMobile(): bool
    {
        return $this->type === self::TYPE_MOBILE;
    }

    /**
     * Check if this is a landline number.
     */
    public function isLandline(): bool
    {
        return $this->type === self::TYPE_LANDLINE;
    }

    /**
     * Get the international number as a string.
     *
     * @deprecated Use the $international property directly as it is now a string.
     */
    public function getInternationalString(): string
    {
        return $this->international;
    }
}

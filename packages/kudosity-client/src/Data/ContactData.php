<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data;

/**
 * Contact DTO.
 *
 * Returned by get-contact, add-to-list endpoints.
 *
 * Default Value Handling:
 * - mobile: Required - throws exception if missing (API should always provide this)
 * - firstName, lastName: Empty string if not provided (API may omit these)
 * - status: Defaults to 'active' (client assumption for backwards compatibility)
 * - dateAdded: Null if not provided (API omits for some endpoints)
 * - customFields: Empty array if none defined (API omits empty fields)
 */
final readonly class ContactData
{
    /**
     * @param  string  $mobile  The contact's mobile number (required)
     * @param  string  $firstName  First name (may be empty if not provided by API)
     * @param  string  $lastName  Last name (may be empty if not provided by API)
     * @param  string  $status  Contact status: 'active', 'optout', 'deleted' (defaults to 'active')
     * @param  string|null  $dateAdded  When the contact was added (ISO 8601 format)
     * @param  array<string, string>  $customFields  Custom field values (field_1 through field_10)
     */
    public function __construct(
        public string $mobile,
        public string $firstName,
        public string $lastName,
        public string $status,
        public ?string $dateAdded = null,
        public array $customFields = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \InvalidArgumentException If mobile number is missing or empty
     */
    public static function fromResponse(array $data): self
    {
        $contact = $data['contact'] ?? $data;

        // Validate mobile number - it's required for a contact
        $mobile = $contact['mobile'] ?? $contact['msisdn'] ?? null;
        if ($mobile === null || $mobile === '') {
            throw new \InvalidArgumentException(
                'Contact must have a valid mobile number (mobile or msisdn field)'
            );
        }

        // Extract custom fields
        $customFields = [];
        for ($i = 1; $i <= 10; $i++) {
            $key = "field_{$i}";
            if (isset($contact[$key]) && $contact[$key] !== '') {
                $customFields[$key] = (string) $contact[$key];
            }
        }

        return new self(
            mobile: (string) $mobile,
            firstName: (string) ($contact['firstname'] ?? ''),
            lastName: (string) ($contact['lastname'] ?? ''),
            status: (string) ($contact['status'] ?? 'active'),
            dateAdded: $contact['date_added'] ?? null,
            customFields: $customFields,
        );
    }

    /**
     * Check if contact is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if contact is opted out.
     */
    public function isOptedOut(): bool
    {
        return $this->status === 'optout';
    }
}

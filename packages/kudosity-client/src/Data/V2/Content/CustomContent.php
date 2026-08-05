<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2\Content;

use ExpertSystems\Kudosity\Contracts\WhatsAppContent;

/**
 * A raw Meta Cloud API payload — `content_type: "custom"`.
 *
 * The escape hatch for everything {@see TemplateContent} cannot express: image
 * and document headers, buttons, carousels, an explicit language policy. Inside
 * `custom` the shape changes completely — `language` becomes an object rather
 * than a flat `locale` string, and parameters become typed objects grouped into
 * `components` by `HEADER`/`BODY`/`BUTTONS`.
 *
 * Kudosity passes the payload straight through, so Meta's Cloud API
 * documentation is the reference for what goes inside it and this class
 * deliberately does not validate or reshape it. Prefer
 * {@see TemplateContent} where it works: its validation errors come back as
 * readable `error.issues[]` rather than surfacing later as a non-delivery.
 */
final readonly class CustomContent implements WhatsAppContent
{
    /**
     * @param  array<string, mixed>  $payload  Meta's own object, sent verbatim
     */
    public function __construct(
        public array $payload,
    ) {}

    public function contentType(): string
    {
        return 'custom';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['custom' => $this->payload];
    }
}

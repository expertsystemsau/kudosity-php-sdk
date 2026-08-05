<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Contracts;

use ExpertSystems\Kudosity\Requests\V2\SendWhatsAppRequest;

/**
 * One of the three shapes the WhatsApp `content` field can take.
 *
 * `POST /v2/whatsapp/messages` carries two fields that must agree: a
 * `content_type` discriminator and a `content` object whose single key repeats
 * it — `{"content_type": "template", "content": {"template": {...}}}`. It is
 * `content.template.name`, never `content.name`, and the upstream docs name
 * that flattening as the most common WhatsApp integration mistake.
 *
 * Both fields are therefore derived from one object rather than passed
 * separately, so there is no arrangement of arguments in which they can
 * disagree.
 *
 * @see SendWhatsAppRequest
 */
interface WhatsAppContent
{
    /**
     * The `content_type` discriminator this variant sends.
     */
    public function contentType(): string;

    /**
     * The `content` object, keyed by the variant name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}

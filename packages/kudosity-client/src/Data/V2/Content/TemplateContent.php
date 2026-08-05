<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Data\V2\Content;

use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Exceptions\ValidationException;

/**
 * A pre-approved WhatsApp template — `content_type: "template"`.
 *
 * The only shape that delivers outside the 24-hour service window, and so the
 * one to reach for whenever you are initiating the conversation. Templates are
 * registered and approved in the WhatsApp Business account, not through this
 * API — there is no endpoint to create one.
 *
 * This shape covers text-only templates with positional placeholders. A
 * template needing a media header, buttons or a carousel needs Meta's own
 * payload instead: see {@see CustomContent}.
 */
final readonly class TemplateContent implements WhatsAppContent
{
    /**
     * The documented template-name rule: lowercase alphanumeric and
     * underscores. `order_confirmation`, not `Order Confirmation` and not
     * `order-confirmation`.
     *
     * The `D` modifier is load-bearing. Without it PCRE's `$` also matches
     * immediately before a final newline, so `"order_confirmation\n"` — a name
     * read from a file, a CSV cell or a copied line — passes the rule and the
     * newline goes on the wire.
     */
    public const NAME_PATTERN = '/^[a-z0-9_]+$/D';

    /**
     * @param  array<int, mixed>  $parameters  Positional values filling `{1}`, `{2}`, … in
     *                                         the approved body. Typed `mixed` deliberately:
     *                                         the element type is enforced below at runtime,
     *                                         and annotating `array<int, string>` would make
     *                                         that check unreachable as far as the analyser
     *                                         is concerned — while the callers who pass an
     *                                         int are precisely the ones whose data came out
     *                                         of JSON or an unanalysed codebase.
     *
     * @throws ValidationException If the name breaks the documented naming rule,
     *                             or any parameter is not a string
     */
    public function __construct(
        public string $name,
        public array $parameters = [],
        public ?string $locale = null,
    ) {
        if ($name === '') {
            throw new ValidationException(
                message: 'A template name is required.',
                errorCode: 'FIELD_EMPTY',
            );
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new ValidationException(
                message: sprintf(
                    'A template name must be lowercase alphanumeric and underscores only, "%s" given.',
                    $name,
                ),
                errorCode: 'FIELD_INVALID',
            );
        }

        foreach ($parameters as $index => $parameter) {
            // Rejected rather than stringified on purpose. Template parameters
            // are positional strings upstream, so an int, array or object here
            // is a caller error — and quietly casting it would produce a send
            // that succeeds while saying something nobody wrote, which is the
            // exact class of silent-wrong behaviour these value objects exist
            // to make impossible. Do not soften this to a (string) cast.
            if (! is_string($parameter)) {
                throw new ValidationException(
                    message: sprintf(
                        'Template parameters must be strings; parameter %s is a %s. Media, documents and typed values need content_type "custom".',
                        (string) $index,
                        get_debug_type($parameter),
                    ),
                    errorCode: 'FIELD_INVALID',
                );
            }
        }
    }

    public function contentType(): string
    {
        return 'template';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $template = ['name' => $this->name];

        if ($this->parameters !== []) {
            // array_values keeps the list positional. A gapped array — what
            // array_filter() leaves behind — would serialise as a JSON object
            // and the placeholders would not fill.
            $template['parameters'] = array_values($this->parameters);
        }

        if ($this->locale !== null) {
            $template['locale'] = $this->locale;
        }

        return ['template' => $template];
    }
}

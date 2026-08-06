<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlParser;
use ExpertSystems\Kudosity\Data\V2\Content\CustomContent;
use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Exceptions\InvalidSignatureException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The three WhatsAppContent variants, SmsFallback, and the
 * CallbackUrlBuilder/Parser signing contract.
 *
 * One of this task's brief examples does not match the shipped source and is
 * corrected here rather than followed verbatim (confirmed by a RED run
 * against the brief's literal text — see the task report):
 *
 * - TemplateContent::toArray() returns `['template' => [...]]`, not the
 *   inner array bare. The brief's assertion omitted the `template` wrapper
 *   that every other variant (and the interface's own docblock) carries.
 *
 * The three `PhoneNumber`/`CountryCodes` tests this class used to hold moved
 * to `PhoneNumberTest.php` and `CountryCodesTest.php` in Task 7b batch 1's fix
 * round — one class, one owning test file, per `#[CoversClass]` coverage
 * attribution (see that task's report for why the attribution matters).
 */
#[CoversClass(TemplateContent::class)]
#[CoversClass(TextContent::class)]
#[CoversClass(CustomContent::class)]
#[CoversClass(SmsFallback::class)]
#[CoversClass(CallbackUrlBuilder::class)]
#[CoversClass(CallbackUrlParser::class)]
final class ValueObjectTest extends TestCase
{
    // -----------------------------------------------------------------
    // WhatsAppContent variants
    // -----------------------------------------------------------------

    public function test_template_content_type_and_content_cannot_desynchronise(): void
    {
        // The value objects exist so content_type and content are produced
        // together — a string content_type plus a hand-built array is how
        // they drift. content.template.name, never content.name: the
        // `template` wrapper is the flattening mistake the class's own
        // docblock names.
        $template = new TemplateContent('order_update', ['ACME']);

        $this->assertSame('template', $template->contentType());
        $this->assertSame(['template' => ['name' => 'order_update', 'parameters' => ['ACME']]], $template->toArray());
    }

    public function test_text_content_type_and_content_agree(): void
    {
        $text = new TextContent('Report to the ready room!');

        $this->assertSame('text', $text->contentType());
        $this->assertSame(['text' => ['message' => 'Report to the ready room!']], $text->toArray());
    }

    public function test_custom_content_type_and_content_agree(): void
    {
        // CustomContent's whole point is passing Meta's payload straight
        // through unreshaped — asserted here against a shape TemplateContent
        // cannot express (an image header) so the pass-through isn't a
        // coincidence of a payload simple enough to survive any handling.
        $payload = ['header' => ['type' => 'image', 'image' => ['link' => 'https://e.com/a.jpg']]];
        $custom = new CustomContent($payload);

        $this->assertSame('custom', $custom->contentType());
        $this->assertSame(['custom' => $payload], $custom->toArray());
    }

    public function test_template_parameters_are_omitted_from_the_payload_when_there_are_none(): void
    {
        // A gapless omission, not a null or an empty array on the wire —
        // asserted by absence of the key entirely.
        $this->assertSame(['template' => ['name' => 'order_update']], (new TemplateContent('order_update'))->toArray());
    }

    public function test_template_locale_is_included_when_given(): void
    {
        $template = new TemplateContent('order_update', ['ACME'], 'en_US');

        $this->assertSame(
            ['template' => ['name' => 'order_update', 'parameters' => ['ACME'], 'locale' => 'en_US']],
            $template->toArray(),
        );
    }

    public function test_template_rejects_an_empty_name(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/name is required/');

        new TemplateContent('');
    }

    public function test_template_rejects_a_name_that_breaks_the_naming_rule(): void
    {
        // Uppercase and a space, neither of which the documented rule
        // allows — a name only this rule, and not the empty-name check,
        // catches.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase alphanumeric/');

        new TemplateContent('Order Confirmation');
    }

    public function test_template_name_pattern_rejects_a_trailing_newline(): void
    {
        // The /D modifier on NAME_PATTERN is load-bearing: without it, PCRE's
        // $ also matches immediately before a final newline, so a name read
        // from a file or a copied line would pass with the newline still on
        // the wire.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase alphanumeric/');

        new TemplateContent("order_confirmation\n");
    }

    public function test_template_rejects_a_non_string_parameter(): void
    {
        // Rejected rather than stringified: an int here would send a
        // template call that succeeds while saying something nobody wrote.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/must be strings/');

        new TemplateContent('order_update', [123]);
    }

    // -----------------------------------------------------------------
    // SmsFallback
    // -----------------------------------------------------------------

    public function test_sms_fallback_rejects_an_empty_message(): void
    {
        $this->expectException(ValidationException::class);

        new SmsFallback('');
    }

    public function test_sms_fallback_payload_omits_sender_when_absent(): void
    {
        $this->assertSame(['message' => 'fallback'], (new SmsFallback('fallback'))->toArray());
    }

    public function test_sms_fallback_payload_includes_sender_when_present(): void
    {
        $this->assertSame(
            ['message' => 'fallback', 'sender' => '61481074185'],
            (new SmsFallback('fallback', '61481074185'))->toArray(),
        );
    }

    public function test_from_array_rejects_a_payload_with_no_message(): void
    {
        // The request-side factory holds the constructor's invariant: a
        // fallback with no usable message is a caller error to raise, not
        // silently drop.
        $this->expectException(ValidationException::class);

        SmsFallback::fromArray(['sender' => '61481074185']);
    }

    public function test_from_array_builds_a_complete_fallback(): void
    {
        $fallback = SmsFallback::fromArray(['message' => 'fallback', 'sender' => '61481074185']);

        $this->assertSame('fallback', $fallback->message);
        $this->assertSame('61481074185', $fallback->sender);
    }

    public function test_from_response_returns_null_rather_than_throwing_for_a_missing_message(): void
    {
        // fromResponse() is the read path's whole reason to exist:
        // fromArray() would throw on this exact payload, and a response is
        // not ours to police.
        $this->assertNull(SmsFallback::fromResponse(['sender' => '61481074185']));
    }

    public function test_from_response_returns_null_for_an_empty_message(): void
    {
        $this->assertNull(SmsFallback::fromResponse(['message' => '']));
    }

    public function test_from_response_builds_a_fallback_when_the_message_is_usable(): void
    {
        // The other side of null-on-the-way-out: a usable message must not
        // be swallowed by the same tolerance that lets a bad one through as
        // null.
        $fallback = SmsFallback::fromResponse(['message' => 'fallback']);

        $this->assertNotNull($fallback);
        $this->assertSame('fallback', $fallback->message);
        $this->assertNull($fallback->sender);
    }

    // -----------------------------------------------------------------
    // CallbackUrlBuilder / CallbackUrlParser
    // -----------------------------------------------------------------

    public function test_a_signed_url_round_trips_through_the_parser(): void
    {
        $builder = new CallbackUrlBuilder('https://app.test/webhooks/kudosity', 'signing-key');

        $url = $builder->build(CallbackType::EVENTS, 'App\\Handlers\\Events', ['order_id' => 991]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $parsed = (new CallbackUrlParser('signing-key'))->parse($query);

        $this->assertSame('App\\Handlers\\Events', $parsed['handler']);
        $this->assertSame(991, $parsed['context']['order_id']);
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        $builder = new CallbackUrlBuilder('https://app.test/webhooks/kudosity', 'signing-key');
        parse_str((string) parse_url($builder->build(CallbackType::EVENTS, 'H'), PHP_URL_QUERY), $query);
        $query['s'] = str_repeat('0', strlen((string) $query['s']));

        $this->expectException(InvalidSignatureException::class);

        (new CallbackUrlParser('signing-key'))->parse($query);
    }

    public function test_a_url_verified_with_the_wrong_signing_key_is_refused(): void
    {
        // The same failure mode from the other side: a signature that is
        // internally consistent but produced with a different key must fail
        // exactly like a tampered one.
        $builder = new CallbackUrlBuilder('https://app.test/webhooks/kudosity', 'signing-key');
        parse_str((string) parse_url($builder->build(CallbackType::EVENTS, 'H'), PHP_URL_QUERY), $query);

        $this->expectException(InvalidSignatureException::class);

        (new CallbackUrlParser('a-different-key'))->parse($query);
    }

    public function test_the_parser_still_allows_an_unsigned_events_only_url(): void
    {
        // Documented, deliberate, and relied on by the three V1 GET routes
        // where there is no handler to protect. The V2 receiver compensates
        // by requiring `s` and `h` itself — do NOT "fix" this to match the
        // receiver.
        $this->assertSame(null, (new CallbackUrlParser('signing-key'))->parse([])['handler']);
    }

    public function test_a_handler_present_without_a_signature_is_refused(): void
    {
        // Events-only mode applies only when BOTH h and c are absent — this
        // is the other side of that boundary, where a signature is missing
        // but there is something worth protecting.
        $this->expectException(InvalidSignatureException::class);

        (new CallbackUrlParser('signing-key'))->parse(['h' => 'aGk=']);
    }

    public function test_the_builder_returns_the_bare_url_when_there_is_nothing_to_sign(): void
    {
        $builder = new CallbackUrlBuilder('https://app.test/webhooks/kudosity', 'signing-key');

        $this->assertSame(
            'https://app.test/webhooks/kudosity/events',
            $builder->build(CallbackType::EVENTS),
        );
    }
}

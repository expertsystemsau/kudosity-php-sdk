<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Callbacks\CallbackType;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlBuilder;
use ExpertSystems\Kudosity\Callbacks\CallbackUrlParser;
use ExpertSystems\Kudosity\Data\V2\Content\CustomContent;
use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Exceptions\InvalidSignatureException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The three WhatsAppContent variants and the CallbackUrlBuilder/Parser
 * signing contract.
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
 * round, and the eight `SmsFallback` tests it used to hold moved to
 * `V2FoundationsTest.php` in Task 7b batch 3 — one class, one owning test
 * file, per `#[CoversClass]` coverage attribution (see batch 1's task report
 * for why the attribution matters). Six of this file's nine `TemplateContent`
 * tests moved to `V2WhatsAppTest.php` in Task 7b batch 6, superseded there by
 * tests that assert the same fact at least as strongly (the template-name
 * rule in particular, at seven isolated violations rather than three
 * conflated ones). The three that remain — the combined `contentType()` +
 * `toArray()` check per variant — have no counterpart in that file.
 */
#[CoversClass(TemplateContent::class)]
#[CoversClass(TextContent::class)]
#[CoversClass(CustomContent::class)]
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

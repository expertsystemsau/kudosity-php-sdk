<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use DateTimeImmutable;
use ExpertSystems\Kudosity\Concerns\GuardsMessageRef;
use ExpertSystems\Kudosity\Contracts\PaginatesV2Cursor;
use ExpertSystems\Kudosity\Contracts\WhatsAppContent;
use ExpertSystems\Kudosity\Data\V2\Content\CustomContent;
use ExpertSystems\Kudosity\Data\V2\Content\TemplateContent;
use ExpertSystems\Kudosity\Data\V2\Content\TextContent;
use ExpertSystems\Kudosity\Data\V2\SmsFallback;
use ExpertSystems\Kudosity\Data\V2\WhatsAppMessageData;
use ExpertSystems\Kudosity\Enums\MessageStatus;
use ExpertSystems\Kudosity\Exceptions\NotFoundException;
use ExpertSystems\Kudosity\Exceptions\ValidationException;
use ExpertSystems\Kudosity\KudosityV2Connector;
use ExpertSystems\Kudosity\Requests\V2\GetWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\ListWhatsAppRequest;
use ExpertSystems\Kudosity\Requests\V2\SendWhatsAppRequest;
use ExpertSystems\Kudosity\Resources\WhatsAppResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Ported from the root Pest suite's tests/Unit/V2WhatsAppTest.php.
 *
 * Resource-level behaviour for the nested `data`/`content` envelope, the
 * three `WhatsAppContent` variants, and `WhatsAppMessageData`'s hydration —
 * including the seven-violation template-name rule this file keeps at full
 * granularity (see below).
 *
 * `ValueObjectTest.php` used to hold nine `TemplateContent`/`TextContent`/
 * `CustomContent` tests. Six come out of that file in this commit as exact
 * or superseded duplicates of tests ported here:
 *
 * - `test_template_parameters_are_omitted_from_the_payload_when_there_are_none`
 *   and `test_template_locale_is_included_when_given` — identical scenarios,
 *   ported verbatim below.
 * - `test_template_rejects_an_empty_name`,
 *   `test_template_rejects_a_name_that_breaks_the_naming_rule` and
 *   `test_template_name_pattern_rejects_a_trailing_newline` — superseded by
 *   this file's finer-grained violation cases below (uppercase alone,
 *   hyphen alone, space alone, trailing newline alone, leading newline
 *   alone, trailing space alone, and empty via its own dedicated
 *   FIELD_EMPTY-code assertion), none of which weakens what the three old
 *   tests asserted.
 * - `test_template_rejects_a_non_string_parameter` — the same guard, ported
 *   verbatim below with the root suite's own fixture value.
 *
 * Three of the old file's nine tests stay, because nothing here duplicates
 * them: `test_template_content_type_and_content_cannot_desynchronise`,
 * `test_text_content_type_and_content_agree` and
 * `test_custom_content_type_and_content_agree` each assert `contentType()`
 * and `toArray()` together, directly against the value object with no
 * resource in between — a combination this file does not repeat.
 *
 * `V2FoundationsTest.php` already owns every `SmsFallback` test directly
 * against that class; the sms_fallback-related tests here are kept because
 * they assert a different thing — that `SendWhatsAppRequest` and
 * `WhatsAppMessageData` actually wire a given/returned fallback through,
 * not `SmsFallback`'s own behaviour. `V2DateRangeFilterTest.php` likewise
 * owns the `date_range` allow-list and `custom_date` pairing rule; the list
 * filter tests here assert the query actually reaches the wire through
 * `WhatsAppResource::list()`, which that file never inspects.
 */
#[CoversClass(WhatsAppResource::class)]
#[CoversClass(SendWhatsAppRequest::class)]
#[CoversClass(GetWhatsAppRequest::class)]
#[CoversClass(ListWhatsAppRequest::class)]
#[CoversClass(WhatsAppMessageData::class)]
#[CoversClass(TemplateContent::class)]
#[CoversClass(TextContent::class)]
#[CoversClass(CustomContent::class)]
#[CoversTrait(GuardsMessageRef::class)]
final class V2WhatsAppTest extends TestCase
{
    /**
     * The message object verbatim from .agents/skills/kudosity-whatsapp/SKILL.md.
     *
     * Note what is absent: no `status`, no `content`, no `campaign_id`. The
     * send response really does omit them, which is why those DTO fields are
     * nullable.
     *
     * @return array<string, mixed>
     */
    private static function whatsAppMessage(array $overrides = []): array
    {
        return array_merge([
            'id' => '6fdae71c-dad7-4c36-9734-a69693ecf3b4',
            'message_ref' => 'order-12345',
            'sender' => '14155238886',
            'recipient' => '61491570010',
            'content_type' => 'template',
            'created_at' => '2026-07-29T00:00:00Z',
        ], $overrides);
    }

    /**
     * The send response — WRAPPED in a `data` envelope, unlike SMS and MMS.
     *
     * @return array<string, mixed>
     */
    private static function whatsAppSendBody(array $overrides = []): array
    {
        return ['data' => self::whatsAppMessage($overrides)];
    }

    /**
     * The list response — items at `data.messages`, cursor state at `meta.pagination`.
     *
     * @return array<string, mixed>
     */
    private static function whatsAppListBody(): array
    {
        return [
            'data' => [
                'messages' => [self::whatsAppMessage(), self::whatsAppMessage(['id' => 'second'])],
            ],
            'meta' => [
                'pagination' => [
                    'has_next' => false,
                    'next_cursor' => '',
                ],
            ],
        ];
    }

    /** @param array<class-string, MockResponse> $responses */
    private static function whatsAppResource(array $responses): WhatsAppResource
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient($responses));

        return new WhatsAppResource($connector);
    }

    // ---------------------------------------------------------------------------
    // The nested envelope — the whole reason the content value objects exist
    // ---------------------------------------------------------------------------

    public function test_derives_content_type_from_the_content_object_and_nests_content_under_its_variant_key(): void
    {
        // content_type and content come from one source, so they cannot disagree.
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->template('order_update', ['#12345', 'shipped'], '61491570010');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('template', $body['content_type']);
        $this->assertSame([
            'template' => [
                'name' => 'order_update',
                'parameters' => ['#12345', 'shipped'],
            ],
        ], $body['content']);
    }

    public function test_sends_no_top_level_name_key_so_flattening_the_content_envelope_fails_this_test(): void
    {
        // The skill names the flattened shape as the most common mistake: it is
        // content.template.name, never content.name and never a top-level name.
        // This inspects the body actually put on the wire, not the value object.
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->template('order_update', ['#12345'], '61491570010');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertArrayNotHasKey('name', $body);
        $this->assertArrayNotHasKey('parameters', $body);
        $this->assertArrayNotHasKey('template', $body);
        $this->assertArrayNotHasKey('name', $body['content']);
        $this->assertArrayHasKey('template', $body['content']);
        $this->assertSame('order_update', $body['content']['template']['name']);
    }

    public function test_nests_free_form_text_under_content_text_message(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(['content_type' => 'text']), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->text('Thanks — your refund is on its way.', '61491570010');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('text', $body['content_type']);
        $this->assertSame(['text' => ['message' => 'Thanks — your refund is on its way.']], $body['content']);
        $this->assertArrayNotHasKey('message', $body);
    }

    public function test_passes_a_custom_payload_through_verbatim_under_content_custom(): void
    {
        // custom is Meta's own schema, not Kudosity's — the SDK must not reshape it.
        $payload = [
            'type' => 'template',
            'template' => [
                'name' => 'template_img_simple_1',
                'language' => ['code' => 'en', 'policy' => 'deterministic'],
                'components' => [
                    [
                        'type' => 'HEADER',
                        'parameters' => [
                            ['type' => 'image', 'image' => ['link' => 'https://example.com/hero.jpg']],
                        ],
                    ],
                ],
            ],
        ];

        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(['content_type' => 'custom']), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->custom($payload, '61491570010');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('custom', $body['content_type']);
        $this->assertSame(['custom' => $payload], $body['content']);
    }

    public function test_derives_both_fields_from_whatever_content_object_send_is_handed(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(['content_type' => 'text']), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->send(new TextContent('Hi'), '61491570010');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertSame('text', $body['content_type']);
        $this->assertSame(['text' => ['message' => 'Hi']], $body['content']);
    }

    public function test_has_every_content_variant_report_its_own_content_type_through_the_contract(): void
    {
        $text = new TextContent('Hi');
        $template = new TemplateContent('order_update');
        $custom = new CustomContent(['type' => 'template']);

        $this->assertInstanceOf(WhatsAppContent::class, $text);
        $this->assertInstanceOf(WhatsAppContent::class, $template);
        $this->assertInstanceOf(WhatsAppContent::class, $custom);
        $this->assertSame('text', $text->contentType());
        $this->assertSame('template', $template->contentType());
        $this->assertSame('custom', $custom->contentType());
    }

    // ---------------------------------------------------------------------------
    // TemplateContent
    // ---------------------------------------------------------------------------

    public function test_omits_parameters_when_empty_and_locale_when_null(): void
    {
        // Folded from ValueObjectTest.php's identical
        // test_template_parameters_are_omitted_from_the_payload_when_there_are_none.
        $this->assertSame(['template' => ['name' => 'order_update']], (new TemplateContent('order_update'))->toArray());
    }

    public function test_includes_locale_when_given(): void
    {
        // Folded from ValueObjectTest.php's identical
        // test_template_locale_is_included_when_given.
        $this->assertSame(['template' => [
            'name' => 'order_update',
            'parameters' => ['#12345'],
            'locale' => 'en_US',
        ]], (new TemplateContent('order_update', ['#12345'], 'en_US'))->toArray());
    }

    public function test_reindexes_gapped_parameter_keys_so_they_serialise_as_a_positional_array(): void
    {
        // Parameters fill {1}, {2}, ... positionally. A gapped array — what
        // array_filter() leaves behind — would encode as a JSON object and break
        // the send.
        $this->assertSame(['template' => [
            'name' => 'order_update',
            'parameters' => ['#12345', 'shipped'],
        ]], (new TemplateContent('order_update', [1 => '#12345', 3 => 'shipped']))->toArray());
    }

    public function test_rejects_a_template_name_containing_an_uppercase_letter_naming_the_rule(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new TemplateContent('Order_Update');
    }

    public function test_rejects_a_template_name_containing_a_hyphen(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new TemplateContent('order-update');
    }

    public function test_rejects_a_template_name_containing_a_space(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new TemplateContent('order update');
    }

    public function test_rejects_a_template_name_with_a_trailing_newline(): void
    {
        // The rule's `D` modifier is what catches this. Without it PCRE's `$` also
        // matches immediately before a final newline, so a name read from a file or
        // a CSV cell validates and ships the newline. Its own test because that hole
        // survived every other name case.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new TemplateContent("order_update\n");
    }

    public function test_rejects_a_template_name_with_a_leading_newline(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new TemplateContent("\norder_update");
    }

    public function test_rejects_a_template_name_with_a_trailing_space(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/lowercase/');

        new TemplateContent('order_update ');
    }

    public function test_rejects_an_empty_template_name_through_the_empty_specific_guard(): void
    {
        // Asserts the FIELD_EMPTY code, not just the exception type: the name
        // pattern would reject '' on its own, so a type-only assertion stays
        // green if the dedicated guard — and its more useful message — is
        // deleted. Folded from ValueObjectTest.php's
        // test_template_rejects_an_empty_name, which asserted only the message.
        try {
            new TemplateContent('');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('FIELD_EMPTY', $e->getErrorCode());
            $this->assertStringContainsString('is required', $e->getMessage());
        }
    }

    public function test_accepts_a_lowercase_alphanumeric_name_with_underscores(): void
    {
        $this->assertSame(
            ['template' => ['name' => 'order_update_2']],
            (new TemplateContent('order_update_2'))->toArray(),
        );
    }

    public function test_rejects_a_non_string_template_parameter_rather_than_stringifying_it(): void
    {
        // Deliberate choice: reject. Template parameters are positional strings
        // upstream, so silently stringifying an int, array or object is exactly
        // the silent-wrong behaviour this channel exists to prevent. The name
        // here is valid, so only the parameter rule can be what fires. Folded
        // from ValueObjectTest.php's test_template_rejects_a_non_string_parameter.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/string/');

        new TemplateContent('order_update', [12345]);
    }

    public function test_rejects_an_array_template_parameter(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/string/');

        new TemplateContent('order_update', [['type' => 'image']]);
    }

    // ---------------------------------------------------------------------------
    // The send request
    // ---------------------------------------------------------------------------

    public function test_sends_a_template_whatsapp_message_and_returns_a_typed_dto(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $wa = (new WhatsAppResource($connector))->template(
            'order_update',
            ['#12345', 'shipped'],
            '61491570010',
            from: '14155238886',
            messageRef: 'order-12345',
        );

        $this->assertInstanceOf(WhatsAppMessageData::class, $wa);
        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $wa->id);
        $this->assertSame('61491570010', $wa->recipient);
        $this->assertSame('14155238886', $wa->sender);
        $this->assertSame('template', $wa->contentType);
        $this->assertSame('order-12345', $wa->messageRef);

        $this->assertSame([
            'recipient' => '61491570010',
            'content_type' => 'template',
            'content' => ['template' => ['name' => 'order_update', 'parameters' => ['#12345', 'shipped']]],
            'sender' => '14155238886',
            'message_ref' => 'order-12345',
        ], $mock->getLastPendingRequest()->body()->all());
    }

    public function test_omits_sender_so_the_account_default_applies_rather_than_substituting_a_placeholder(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->text('Hi', '61491570010');

        $body = $mock->getLastPendingRequest()->body()->all();

        $this->assertArrayNotHasKey('sender', $body);
        $this->assertArrayNotHasKey('sms_fallback', $body);
        $this->assertArrayNotHasKey('message_ref', $body);
    }

    public function test_normalises_a_spaced_international_recipient_rather_than_sending_it_raw(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->text('Hi', '+61 491 570 010');

        $this->assertSame('61491570010', $mock->getLastPendingRequest()->body()->all()['recipient']);
    }

    public function test_strips_punctuation_from_a_local_recipient_without_guessing_a_country(): void
    {
        // A leading-zero local number cannot be resolved to E.164 without knowing
        // the country, and the SDK does not pick one. Punctuation still goes, so
        // the value is never sent raw; the API rejects the remaining leading zero
        // with a 400, which is a loud failure rather than a silent misdial.
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->text('Hi', '0491 570 010');

        $this->assertSame('0491570010', $mock->getLastPendingRequest()->body()->all()['recipient']);
    }

    public function test_serialises_sms_fallback_through_sms_fallback_to_array(): void
    {
        $mock = new MockClient([SendWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->template(
            'order_update',
            ['#12345'],
            '61491570010',
            fallback: new SmsFallback('Order #12345 has shipped.', '61491570017'),
        );

        $this->assertSame(
            ['message' => 'Order #12345 has shipped.', 'sender' => '61491570017'],
            $mock->getLastPendingRequest()->body()->all()['sms_fallback'],
        );
    }

    public function test_rejects_a_message_ref_longer_than_the_documented_500_characters(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/500/');

        new SendWhatsAppRequest(new TextContent('Hi'), '61491570010', messageRef: str_repeat('a', 501));
    }

    // ---------------------------------------------------------------------------
    // Reading back
    // ---------------------------------------------------------------------------

    public function test_reads_the_id_out_of_the_data_envelope_rather_than_a_top_level_id(): void
    {
        // WhatsApp wraps its payload. A decoy top-level id sits alongside the
        // envelope here, so a DTO reading $json['id'] would resolve the wrong
        // message rather than merely reading null.
        $wa = self::whatsAppResource([
            SendWhatsAppRequest::class => MockResponse::make([
                'id' => 'top-level-decoy',
                'data' => self::whatsAppMessage(),
            ], 200),
        ])->text('Hi', '61491570010');

        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $wa->id);
    }

    public function test_reads_one_whatsapp_message_by_id(): void
    {
        $mock = new MockClient([GetWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        $wa = (new WhatsAppResource($connector))->get('6fdae71c-dad7-4c36-9734-a69693ecf3b4');

        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $wa->id);
        $this->assertSame(
            'https://api.transmitmessage.com/v2/whatsapp/messages/6fdae71c-dad7-4c36-9734-a69693ecf3b4',
            (string) $mock->getLastPendingRequest()->getUri(),
        );
    }

    public function test_sends_no_body_on_the_get_reader(): void
    {
        // Readers extend KudosityV2Request, which carries no body: a JSON body on
        // a GET — even an empty one — is stripped or rejected by some gateways.
        $mock = new MockClient([GetWhatsAppRequest::class => MockResponse::make(self::whatsAppSendBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        (new WhatsAppResource($connector))->get('abc');

        $pending = $mock->getLastPendingRequest();

        $this->assertNull($pending->headers()->get('Content-Type'));
        $this->assertSame('', (string) $pending->body());
    }

    public function test_turns_a_404_into_a_not_found_exception(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('WhatsApp message not found');

        self::whatsAppResource([
            GetWhatsAppRequest::class => MockResponse::make(['error' => 'WhatsApp message not found'], 404),
        ])->get('nope');
    }

    // ---------------------------------------------------------------------------
    // The list endpoint
    // ---------------------------------------------------------------------------

    public function test_declares_itself_cursor_paginated_with_items_at_data_messages_walked_forwards(): void
    {
        $this->assertInstanceOf(PaginatesV2Cursor::class, new ListWhatsAppRequest);
        $this->assertSame('data.messages', (new ListWhatsAppRequest)->paginationItemsKey());
        $this->assertSame('next', (new ListWhatsAppRequest)->paginationDirection());
    }

    public function test_pages_the_list_endpoint_reading_items_out_of_the_data_messages_path(): void
    {
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient(new MockClient([
            ListWhatsAppRequest::class => MockResponse::make(self::whatsAppListBody(), 200),
        ]));

        $items = iterator_to_array((new WhatsAppResource($connector))->list()->items());

        $this->assertCount(2, $items);
        $this->assertSame('6fdae71c-dad7-4c36-9734-a69693ecf3b4', $items[0]['id']);
        $this->assertSame('second', $items[1]['id']);
    }

    public function test_passes_list_filters_through_as_query_parameters(): void
    {
        $mock = new MockClient([ListWhatsAppRequest::class => MockResponse::make(self::whatsAppListBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array((new WhatsAppResource($connector))->list(
            dateRange: 'custom_date',
            startDate: '2026-07-01',
            endDate: '2026-07-31',
            campaignId: 'camp-1',
        )->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertSame('custom_date', $query->get('date_range'));
        $this->assertSame('2026-07-01', $query->get('start_date'));
        $this->assertSame('2026-07-31', $query->get('end_date'));
        $this->assertSame('camp-1', $query->get('campaign_id'));
        $this->assertSame('next', $query->get('direction'));
    }

    public function test_sends_no_filter_query_parameters_when_none_are_given(): void
    {
        $mock = new MockClient([ListWhatsAppRequest::class => MockResponse::make(self::whatsAppListBody(), 200)]);
        $connector = new KudosityV2Connector('key');
        $connector->withMockClient($mock);

        iterator_to_array((new WhatsAppResource($connector))->list()->items());

        $query = $mock->getLastPendingRequest()->query();

        $this->assertNull($query->get('date_range'));
        $this->assertNull($query->get('start_date'));
        $this->assertNull($query->get('end_date'));
        $this->assertNull($query->get('campaign_id'));
    }

    // The date_range allow-list and the custom_date pairing rule live in
    // Concerns\FiltersByDateRange and are asserted against this request,
    // alongside ListRcsRequest, in V2DateRangeFilterTest.php.

    // ---------------------------------------------------------------------------
    // WhatsAppMessageData
    // ---------------------------------------------------------------------------

    public function test_keeps_content_raw_because_the_response_echoes_whichever_variant_was_sent(): void
    {
        $wa = WhatsAppMessageData::fromArray(self::whatsAppMessage([
            'content' => ['template' => ['name' => 'order_update', 'parameters' => ['#12345']]],
        ]));

        $this->assertSame(['template' => ['name' => 'order_update', 'parameters' => ['#12345']]], $wa->content);
    }

    public function test_leaves_status_null_when_the_send_response_omits_it(): void
    {
        // The documented send response carries no status field at all, so a
        // non-nullable status would have to invent one.
        $this->assertNull(WhatsAppMessageData::fromArray(self::whatsAppMessage())->status);
    }

    public function test_resolves_a_present_status_through_message_status_from_api(): void
    {
        $this->assertSame(
            MessageStatus::Queued,
            WhatsAppMessageData::fromArray(self::whatsAppMessage(['status' => 'queued']))->status,
        );
    }

    public function test_reads_campaign_id_when_present_and_leaves_it_null_otherwise(): void
    {
        $this->assertSame('camp-1', WhatsAppMessageData::fromArray(self::whatsAppMessage(['campaign_id' => 'camp-1']))->campaignId);
        $this->assertNull(WhatsAppMessageData::fromArray(self::whatsAppMessage())->campaignId);
    }

    public function test_parses_sms_fallback_off_a_response_into_an_sms_fallback(): void
    {
        $wa = WhatsAppMessageData::fromArray(self::whatsAppMessage([
            'sms_fallback' => ['sender' => '61491570017', 'message' => 'Order #12345 has shipped.'],
        ]));

        $this->assertInstanceOf(SmsFallback::class, $wa->smsFallback);
        $this->assertSame('Order #12345 has shipped.', $wa->smsFallback?->message);
        $this->assertSame('61491570017', $wa->smsFallback?->sender);
    }

    public function test_leaves_sms_fallback_null_when_the_response_omits_it(): void
    {
        $this->assertNull(WhatsAppMessageData::fromArray(self::whatsAppMessage())->smsFallback);
    }

    public function test_leaves_sms_fallback_null_when_a_response_carries_a_fallback_with_no_message_rather_than_throwing(): void
    {
        // SmsFallback's constructor requires a message — correct for a
        // request-shaped object, wrong to impose on a response we do not control.
        // The invariant stays; the DTO only builds a fallback when the response
        // actually carries a non-empty message.
        $wa = WhatsAppMessageData::fromArray(self::whatsAppMessage(['sms_fallback' => ['sender' => '61491570017']]));

        $this->assertNull($wa->smsFallback);
    }

    public function test_parses_the_nine_fractional_digit_created_at_timestamp_that_rfc3339_extended_rejects(): void
    {
        $wa = WhatsAppMessageData::fromArray(self::whatsAppMessage(['created_at' => '2022-03-29T04:42:01.631708761Z']));

        $this->assertInstanceOf(DateTimeImmutable::class, $wa->createdAt);
        $this->assertNotNull($wa->createdAt);
    }

    public function test_returns_null_for_a_malformed_created_at_rather_than_throwing(): void
    {
        $this->assertNull(WhatsAppMessageData::fromArray(self::whatsAppMessage(['created_at' => 'not-a-date']))->createdAt);
    }
}

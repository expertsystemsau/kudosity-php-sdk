<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Tests;

use ExpertSystems\Kudosity\Webhooks\WebhookIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebhookIdentity::class)]
final class V2WebhookIdentityTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function equivalentUrls(): array
    {
        return [
            'the signature is ignored' => [
                'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=abc123',
                'https://app.example.com/webhooks/kudosity/events?h=aGFuZGxlcg&s=DIFFERENT',
            ],
            'the whole query is ignored' => [
                'https://app.example.com/webhooks/kudosity/events?h=a&c=b&s=c',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'the host is case-insensitive' => [
                'https://APP.Example.COM/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'the scheme is case-insensitive' => [
                'HTTPS://app.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'a trailing slash is not a different endpoint' => [
                'https://app.example.com/webhooks/kudosity/events/',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'an explicit default port is not a different endpoint' => [
                'https://app.example.com:443/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'an empty password is not a credential' => [
                'https://user:@app.example.com/h',
                'https://user@app.example.com/h',
            ],
        ];
    }

    #[DataProvider('equivalentUrls')]
    public function test_treats_urls_differing_only_outside_the_endpoint_as_the_same(string $a, string $b): void
    {
        $this->assertSame(WebhookIdentity::of($a), WebhookIdentity::of($b));
    }

    /** @return array<string, array{string, string}> */
    public static function distinctUrls(): array
    {
        return [
            'a different host is a different app' => [
                'https://staging.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'a different path is a different receiver' => [
                'https://app.example.com/webhooks/kudosity/events',
                'https://app.example.com/hooks/kudosity/events',
            ],
            'a non-default port is part of the endpoint' => [
                'https://app.example.com:8443/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'the path is case-sensitive, unlike the host' => [
                'https://app.example.com/Webhooks/Kudosity/Events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'plaintext is not the same endpoint as TLS' => [
                'http://app.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'credentials are part of the endpoint' => [
                'https://user:pass@app.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
            'a different user is a different endpoint' => [
                'https://alice@app.example.com/h',
                'https://bob@app.example.com/h',
            ],
            'a password-only credential is part of the endpoint' => [
                'https://:sup3rs3cret@app.example.com/webhooks/kudosity/events',
                'https://app.example.com/webhooks/kudosity/events',
            ],
        ];
    }

    #[DataProvider('distinctUrls')]
    public function test_keeps_genuinely_different_endpoints_apart(string $a, string $b): void
    {
        $this->assertNotSame(WebhookIdentity::of($a), WebhookIdentity::of($b));
    }

    public function test_an_unparseable_url_falls_back_to_itself_and_matches_no_real_endpoint(): void
    {
        // A registration made by another tool can hold anything at all. Falling back
        // to the raw string means such a row simply never matches ours, which is the
        // safe outcome: we leave it alone rather than PUTting over it.
        $this->assertSame('http:///nonsense', WebhookIdentity::of('http:///nonsense'));
        $this->assertNotSame(
            WebhookIdentity::of('http:///nonsense'),
            WebhookIdentity::of('https://app.example.com/webhooks/kudosity/events'),
        );
    }

    public function test_a_url_with_no_path_normalises_to_a_single_slash_rather_than_an_empty_string(): void
    {
        // Otherwise "https://a.com" and "https://a.com/" are different identities and
        // one deploy creates a duplicate of the other.
        $this->assertSame(
            WebhookIdentity::of('https://app.example.com'),
            WebhookIdentity::of('https://app.example.com/'),
        );
    }

    public function test_a_password_never_appears_in_the_identity_because_it_is_persisted_to_disk(): void
    {
        // The identity becomes a key in an on-disk fingerprint store, so carrying a
        // real password here would write a credential to the filesystem.
        $identity = WebhookIdentity::of('https://user:sup3rs3cret@app.example.com/h');

        $this->assertStringNotContainsString('sup3rs3cret', $identity);
        $this->assertStringContainsString('user', $identity);

        // Same redaction applies to the password-only (bearer token) form.
        $this->assertStringNotContainsString(
            'sup3rs3cret',
            WebhookIdentity::of('https://:sup3rs3cret@app.example.com/h'),
        );
    }
}

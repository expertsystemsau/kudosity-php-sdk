<?php

declare(strict_types=1);

namespace ExpertSystems\Kudosity\Webhooks;

/**
 * One media attachment on an inbound MMS, delivered **inline as base64**.
 *
 * This is not the shape the outbound API uses, and not the shape the upstream
 * documentation describes. An outbound MMS takes `content_urls` — a list of
 * URLs Kudosity fetches. An inbound MMS carries the bytes themselves, under
 * `mo.media[]`, as `{"content": "<base64>", "name": "image000000.jpg"}`.
 * {@see InboundEvent::$contentUrls} is therefore always empty for a real
 * inbound MMS; the picture is here.
 *
 * **Deliveries get large.** The captured photo that established this shape made
 * a 204KB POST body from a single image, essentially all of it this one field.
 * A receiver that logs `$raw` will log all of it. Each accessor below decodes
 * afresh, so hold the result rather than calling `bytes()` in a loop.
 *
 * **There is no content-type field.** The payload carries a filename and
 * nothing else, which is why {@see self::mimeType()} sniffs the decoded bytes.
 *
 * Captured 2026-08-06 — see `tests/Fixtures/V2Webhooks/README.md`.
 */
final readonly class InboundMedia
{
    /**
     * Signatures sufficient to identify what an MMS realistically carries.
     *
     * Offset-indexed because the ISO base-media brands (`mp4`, `3gp`) put their
     * marker after a four-byte box length.
     *
     * @var array<int, array{0: int, 1: string, 2: string}>
     */
    private const SIGNATURES = [
        [0, "\xFF\xD8\xFF", 'image/jpeg'],
        [0, "\x89PNG\r\n\x1A\n", 'image/png'],
        [0, 'GIF87a', 'image/gif'],
        [0, 'GIF89a', 'image/gif'],
        [0, '%PDF-', 'application/pdf'],
        [4, 'ftypmp4', 'video/mp4'],
        [4, 'ftypisom', 'video/mp4'],
        [4, 'ftypM4V', 'video/mp4'],
        [4, 'ftyp3gp', 'video/3gpp'],
        [4, 'ftyp3g2', 'video/3gpp2'],
    ];

    /**
     * Consulted only when the bytes decode but match no signature.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        '3gp' => 'video/3gpp',
        'amr' => 'audio/amr',
        'mp3' => 'audio/mpeg',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'vcf' => 'text/vcard',
    ];

    /**
     * @param  string  $content  The base64 exactly as delivered, undecoded.
     * @param  string|null  $name  The carrier's filename, e.g. `image000000.jpg`.
     */
    public function __construct(
        public string $content,
        public ?string $name,
    ) {}

    /**
     * Build from one `mo.media[]` entry, or null when the entry is not media.
     *
     * Null rather than an exception: an inbound message is whatever a stranger
     * sent, and a malformed entry must not take down a public endpoint. The
     * caller drops it.
     *
     * @param  mixed  $entry
     */
    public static function fromArray($entry): ?self
    {
        if (! is_array($entry) || ! is_string($entry['content'] ?? null)) {
            return null;
        }

        return new self(
            content: $entry['content'],
            name: is_string($entry['name'] ?? null) ? $entry['name'] : null,
        );
    }

    /**
     * The decoded bytes, or null when the content will not decode.
     *
     * Null rather than a throw, for the same reason {@see UnknownEvent} is
     * returned rather than thrown: a receiver does not choose what it is sent,
     * and a 500 earns a redelivery of the same undecodable payload.
     */
    public function bytes(): ?string
    {
        $decoded = base64_decode($this->content, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * The decoded size in bytes; 0 when the content will not decode.
     *
     * Worth checking before writing to disk — see the size note on the class.
     */
    public function sizeInBytes(): int
    {
        return strlen((string) $this->bytes());
    }

    /**
     * The media type, inferred — the payload states one nowhere.
     *
     * **Sniffed from the content, with the filename as a fallback only.** The
     * name arrives from whoever sent the message, so trusting its extension is
     * how a receiver ends up storing one kind of file under another kind's
     * name. Null when the bytes will not decode, and null when neither the
     * signature nor the extension is recognised — an honest "unknown" rather
     * than a guessed `application/octet-stream`.
     */
    public function mimeType(): ?string
    {
        $bytes = $this->bytes();

        if ($bytes === null) {
            return null;
        }

        foreach (self::SIGNATURES as [$offset, $magic, $type]) {
            if (substr($bytes, $offset, strlen($magic)) === $magic) {
                return $type;
            }
        }

        $extension = strtolower((string) pathinfo((string) $this->name, PATHINFO_EXTENSION));

        return self::EXTENSIONS[$extension] ?? null;
    }
}

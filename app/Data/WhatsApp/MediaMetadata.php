<?php

declare(strict_types=1);

namespace App\Data\WhatsApp;

use SensitiveParameter;

/**
 * What the WhatsApp Cloud API says about one inbound media object, BEFORE any
 * bytes are fetched.
 *
 * `url` IS A CREDENTIAL. It is a short-lived, signed download link that still
 * requires the access token, and it must never be logged, stored, rendered, or
 * put in an exception message — which is why it is marked sensitive and why
 * nothing outside the media client ever reads it.
 *
 * `sizeBytes` is the provider's CLAIM about the file. It is checked first
 * because refusing an oversized file before downloading it is free, but it is
 * not trusted as the fact: the bytes that actually arrive are checked again.
 */
final readonly class MediaMetadata
{
    public function __construct(
        public string $id,
        #[SensitiveParameter]
        public string $url,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?string $sha256 = null,
    ) {}

    /**
     * The MIME type without any parameters, lower-cased:
     * "audio/ogg; codecs=opus" → "audio/ogg". Comparing the bare type is what
     * makes an allowlist mean what it looks like it means.
     */
    public function baseMimeType(): ?string
    {
        if ($this->mimeType === null) {
            return null;
        }

        $base = strtolower(trim(explode(';', $this->mimeType, 2)[0]));

        return $base === '' ? null : $base;
    }
}

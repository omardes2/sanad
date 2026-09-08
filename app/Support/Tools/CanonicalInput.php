<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;
use Normalizer;

/**
 * The canonical form of one tool input and its hash (Phase F2).
 *
 * The hash decides REPLAY versus CONFLICT for a given invocation key, so its
 * bytes must be reproducible forever — across a PHP restart, across SQLite and
 * PostgreSQL, and whatever order the caller happened to build its array in.
 * The rules are therefore explicit and do not lean on framework or driver
 * serialisation:
 *
 *   1. every string value is Unicode-normalised to NFC before anything else,
 *      so the same text typed two ways hashes the same;
 *   2. an absent optional field and an explicit null are the SAME input — both
 *      are simply not present in the canonical form;
 *   3. the closed schema then validates and casts, emitting DECLARATION order,
 *      so the caller's key order cannot change the bytes;
 *   4. there are no floats anywhere — a decimal field casts to its string form
 *      and an integer to a JSON integer;
 *   5. the JSON is written with exactly `JSON_UNESCAPED_UNICODE |
 *      JSON_UNESCAPED_SLASHES`, no pretty printing, no partial output;
 *   6. the hash is sha256 of those bytes, lower-case hex.
 *
 * Nothing secret can reach here: a tool schema only ever declares the bounded
 * fields of its own contract, so the canonical form is safe to store and to
 * hash beside the invocation.
 */
final readonly class CanonicalInput
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, mixed>  $values  validated, in declaration order
     */
    private function __construct(public array $values, public string $json, public string $hash) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ToolRuleException
     */
    public static function of(ToolSchema $schema, array $payload): self
    {
        $values = $schema->validate(self::normalise($payload));
        $json = json_encode($values, self::FLAGS);

        return new self($values, $json, hash('sha256', $json));
    }

    /**
     * Rule 1 and rule 2, applied before the schema sees anything.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalise(array $payload): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue; // absent ≡ null: neither reaches the canonical form
            }

            $out[$key] = is_string($value) ? self::nfc($value) : $value;
        }

        return $out;
    }

    private static function nfc(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value; // ext-intl is present in CI and in the app image; never fail a call over it
        }

        $normalised = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalised === false ? $value : $normalised;
    }
}

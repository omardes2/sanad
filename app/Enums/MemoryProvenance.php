<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * WHERE a durable memory came from (Phase G), and therefore how much authority
 * it carries.
 *
 * `explicit` is the only value V1 ever writes: a memory exists because the
 * subscriber asked for it in a message Sanad can point at. `inferred` is
 * declared now — with no writer — so the column, the tests and the reasoning
 * are in place before any implicit-extraction phase exists, and so the rule
 * that an inferred memory may never overwrite or archive an explicit one has
 * somewhere to be stated.
 */
enum MemoryProvenance: string
{
    /** The subscriber instructed Sanad to remember it. */
    case Explicit = 'explicit';

    /**
     * Sanad concluded it from conversation. NOT WRITTEN IN V1 — there is no
     * extraction path, and a fact the subscriber never asked to keep is not
     * silently persisted.
     */
    case Inferred = 'inferred';

    /** May a memory of this provenance be created by the V1 write path? */
    public function writableInV1(): bool
    {
        return $this === self::Explicit;
    }

    public function label(): string
    {
        return match ($this) {
            self::Explicit => 'بطلب صريح',
            self::Inferred => 'مستنتج',
        };
    }
}

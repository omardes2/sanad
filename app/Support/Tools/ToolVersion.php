<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolDefinitionException;

/**
 * The contract version of a tool (Phase F1). A shipped version is FROZEN: any
 * change to what a tool accepts, returns or does is a NEW version, never an
 * edit of the old one — `task.create@1` and `task.create@2` coexist, and a
 * caller always names the one it was written against.
 */
final readonly class ToolVersion
{
    private function __construct(public int $value) {}

    public static function of(int $value): self
    {
        if ($value < 1 || $value > 999) {
            throw ToolDefinitionException::of("Tool version must be between 1 and 999, got [{$value}].");
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}

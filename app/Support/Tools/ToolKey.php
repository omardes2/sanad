<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolDefinitionException;

/**
 * The identity of one tool contract: `<area>.<action>@<version>` (Phase F1).
 *
 * The name is a bounded, lower-case, dotted identifier — it is an identifier,
 * never a class name, a URL, a file path or anything else that could be
 * executed or dereferenced. Parsing is strict and fails closed: an unparsable
 * or out-of-shape key is refused rather than normalised into something valid.
 */
final readonly class ToolKey
{
    /** `area.action`, 2–3 segments, letters/digits/underscore, lower-case. */
    private const NAME = '/^[a-z][a-z0-9_]{1,30}(\.[a-z][a-z0-9_]{1,30}){1,2}$/';

    private function __construct(public string $name, public ToolVersion $version) {}

    public static function of(string $name, int $version): self
    {
        $name = trim($name);

        if (preg_match(self::NAME, $name) !== 1) {
            throw ToolDefinitionException::of("Tool key [{$name}] is not a valid dotted identifier (area.action, lower-case).");
        }

        return new self($name, ToolVersion::of($version));
    }

    /** Parse `task.create@1`. Anything else — including a missing version — is refused. */
    public static function parse(string $key): self
    {
        $parts = explode('@', trim($key));

        if (count($parts) !== 2 || preg_match('/^[1-9][0-9]{0,2}$/', $parts[1]) !== 1) {
            throw ToolDefinitionException::of("Tool key [{$key}] must be written as name@version (e.g. task.create@1).");
        }

        return self::of($parts[0], (int) $parts[1]);
    }

    public function value(): string
    {
        return $this->name.'@'.$this->version->value;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name && $this->version->equals($other->version);
    }

    public function __toString(): string
    {
        return $this->value();
    }
}

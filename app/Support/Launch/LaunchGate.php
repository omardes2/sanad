<?php

declare(strict_types=1);

namespace App\Support\Launch;

use App\Data\Launch\GateOutcome;
use App\Enums\LaunchGateOwner;
use InvalidArgumentException;

/**
 * One V1 launch gate, declared in CODE.
 *
 * The check provider is a `[class, method]` pair resolved from the registry's
 * own constant — the same idiom `ToolIntentRequirements` uses for its verifiers.
 * A gate therefore CANNOT name an arbitrary callable, a URL, a shell command or
 * a class from a database row or a request payload, and no Markdown file can
 * introduce one. `docs/SANAD_V1_LAUNCH_SCOPE.md` mirrors this registry for
 * humans; it is never parsed and never has runtime authority.
 */
final readonly class LaunchGate
{
    /**
     * @param  callable(): GateOutcome  $check
     */
    private function __construct(
        public string $key,
        public string $title,
        public LaunchGateOwner $owner,
        public bool $requiredForV1,
        public string $why,
        private mixed $check,
    ) {}

    /**
     * @param  array{0: class-string, 1: string}  $check  resolved from code, never from data
     */
    public static function of(
        string $key,
        string $title,
        LaunchGateOwner $owner,
        bool $requiredForV1,
        string $why,
        array $check,
    ): self {
        $key = trim($key);
        $title = trim($title);

        if ($key === '' || $title === '') {
            throw new InvalidArgumentException('A launch gate needs a key and a title.');
        }

        if (! is_callable($check)) {
            throw new InvalidArgumentException("Launch gate [{$key}] declares a check that is not callable.");
        }

        return new self($key, $title, $owner, $requiredForV1, trim($why), $check);
    }

    public function evaluate(): GateOutcome
    {
        return ($this->check)();
    }
}

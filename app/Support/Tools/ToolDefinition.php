<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Enums\ToolCapability;
use App\Enums\ToolSideEffect;
use App\Exceptions\Tools\ToolDefinitionException;

/**
 * One immutable, versioned tool contract (Phase F1) — METADATA ONLY.
 *
 * What a definition deliberately CANNOT carry: a PHP class name, a URL, an
 * endpoint, SQL, a shell command, a template, or a raw permission string. It
 * names a capability from the enum and a side-effect class, and that is the
 * whole of its authority; the executor that F2+ adds resolves the handler from
 * code keyed by the tool key, so nothing outside this repository can ever say
 * what runs.
 *
 * Every instance is `readonly` and is built through `of()`, which enforces the
 * invariants that must hold for the life of the version:
 *   - the input and output contracts are CLOSED schemas;
 *   - an irreversible tool ALWAYS requires approval and can never disable it;
 *   - a read tool can never require approval (there is nothing to approve);
 *   - timeout, retries and the rate limit are present and bounded.
 * A shipped version is frozen: a semantic change is a new version, never an
 * edit — `task.create@1` keeps meaning what it meant.
 */
final readonly class ToolDefinition
{
    public const MAX_TIMEOUT_MS = 120_000;

    public const MAX_RETRIES = 5;

    private function __construct(
        public ToolKey $key,
        public string $title,
        public string $summary,
        public ToolCapability $capability,
        public ToolSideEffect $sideEffect,
        public bool $requiresApproval,
        public ToolSchema $input,
        public ToolSchema $output,
        public int $timeoutMs,
        public int $maxRetries,
        public int $rateLimitPerHour,
    ) {}

    public static function of(
        string $key,
        int $version,
        string $title,
        string $summary,
        ToolCapability $capability,
        ToolSideEffect $sideEffect,
        ToolSchema $input,
        ToolSchema $output,
        bool $requiresApproval = false,
        int $timeoutMs = 15_000,
        int $maxRetries = 0,
        int $rateLimitPerHour = 60,
    ): self {
        $toolKey = ToolKey::of($key, $version);

        if (trim($title) === '' || mb_strlen($title) > 80 || trim($summary) === '' || mb_strlen($summary) > 300) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] needs a title (≤ 80) and a summary (≤ 300).");
        }

        if ($sideEffect->requiresApprovalAlways() && ! $requiresApproval) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] is irreversible and cannot disable approval.");
        }

        if ($requiresApproval && ! $sideEffect->mayRequireApproval()) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] is a read and has nothing to approve.");
        }

        if ($timeoutMs < 100 || $timeoutMs > self::MAX_TIMEOUT_MS) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] needs a timeout between 100 and ".self::MAX_TIMEOUT_MS.' ms.');
        }

        if ($maxRetries < 0 || $maxRetries > self::MAX_RETRIES) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] needs 0 to ".self::MAX_RETRIES.' retries.');
        }

        if ($maxRetries > 0 && ! $sideEffect->retryable()) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] is {$sideEffect->value} and is at-most-once: it cannot declare retries.");
        }

        if ($rateLimitPerHour < 1 || $rateLimitPerHour > 10_000) {
            throw ToolDefinitionException::of("Tool [{$toolKey}] needs a rate limit between 1 and 10000 per hour.");
        }

        if (! in_array($capability->operatorPermission(), ToolCapability::ALLOWED_OPERATOR_PERMISSIONS, true)) {
            throw ToolDefinitionException::of("Capability [{$capability->value}] maps to a permission outside the allowlist.");
        }

        return new self($toolKey, trim($title), trim($summary), $capability, $sideEffect, $requiresApproval, $input, $output, $timeoutMs, $maxRetries, $rateLimitPerHour);
    }

    /** Approval is required when the class always demands it OR the definition asks for it. */
    public function needsApproval(): bool
    {
        return $this->sideEffect->requiresApprovalAlways() || $this->requiresApproval;
    }

    /**
     * The definition as bounded facts — for an admin page, an audit entry or a
     * future provider adapter. No PII, no free text beyond the declared title
     * and summary, and nothing executable.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'key' => $this->key->value(),
            'name' => $this->key->name,
            'version' => $this->key->version->value,
            'title' => $this->title,
            'summary' => $this->summary,
            'capability' => $this->capability->value,
            'operator_permission' => $this->capability->operatorPermission()->value,
            'side_effect' => $this->sideEffect->value,
            'requires_approval' => $this->needsApproval(),
            'retryable' => $this->sideEffect->retryable(),
            'input' => $this->input->names(),
            'output' => $this->output->names(),
            'timeout_ms' => $this->timeoutMs,
            'max_retries' => $this->maxRetries,
            'rate_limit_per_hour' => $this->rateLimitPerHour,
        ];
    }
}

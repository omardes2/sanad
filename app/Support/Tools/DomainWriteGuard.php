<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Defence in depth for the F3-V1 write boundary: while a write tool's domain
 * service runs, the only tables it may change are the ones ITS OWN contract
 * declares — plus the invocation's own infrastructure tables, which necessarily
 * move in the same transaction.
 *
 * It is not the primary control. That is structural: only `write` definitions
 * that require no approval are executable, their handlers are resolved from a
 * code allowlist in `WriteToolExecutor`, and the handlers are domain services
 * that touch one aggregate each. This guard is the second lock: if a future
 * edit makes a task tool write to reminders, or to anything else, the
 * invocation fails as `internal` and the transaction takes the stray write down
 * with it.
 *
 * The table list is declared per tool in code (`ToolWriteTargets`) — never
 * derived from the definition, the database or a payload.
 */
final class DomainWriteGuard
{
    private const WRITE = '/^\s*(insert|update|delete|truncate|alter|drop|create|replace|merge)\b/i';

    /** The invocation's own record; every write tool moves these in its settlement transaction. */
    private const INFRASTRUCTURE = ['tool_invocations', 'tool_invocation_events', 'audit_logs', 'usage_events'];

    private bool $listening = false;

    /** @var list<string>|null the tables allowed right now, or null when not guarding */
    private ?array $allowed = null;

    /** @var list<string> */
    private array $violations = [];

    /**
     * @template T
     *
     * @param  list<string>  $tables  the domain tables this tool may write
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ToolRuleException when the callback wrote anywhere else
     */
    public function run(array $tables, Closure $callback): mixed
    {
        $this->listen();

        $outerAllowed = $this->allowed;
        $outerViolations = $this->violations;
        $this->allowed = array_merge($tables, self::INFRASTRUCTURE);
        $this->violations = [];

        try {
            $result = $callback();
            $violations = $this->violations;
        } finally {
            $this->allowed = $outerAllowed;
            $this->violations = $outerViolations;
        }

        if ($violations !== []) {
            throw ToolRuleException::of('write_scope', 'أداة كتابة لمست جدولًا خارج نطاقها المعلن: '.implode(', ', array_unique($violations)).'.');
        }

        return $result;
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        DB::listen(function (QueryExecuted $query): void {
            if ($this->allowed === null || preg_match(self::WRITE, $query->sql) !== 1) {
                return;
            }

            foreach ($this->tables($query->sql) as $table) {
                if (! in_array($table, $this->allowed, true)) {
                    $this->violations[] = $table;
                }
            }
        });
    }

    /**
     * The tables a write statement names. Quoting differs by driver, so both
     * bare and quoted identifiers are read.
     *
     * @return list<string>
     */
    private function tables(string $sql): array
    {
        preg_match_all('/\b(?:into|update|from|table)\s+["`]?([a-z_][a-z0-9_]*)["`]?/i', $sql, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }
}

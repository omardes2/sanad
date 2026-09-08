<?php

declare(strict_types=1);

namespace App\Support\Tools;

use App\Exceptions\Tools\ToolRuleException;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Defence in depth for the F2 read boundary: while a read tool's own domain
 * reader is running, ANY write statement is a bug, and this notices it.
 *
 * It is deliberately not the primary control — that is structural: only
 * definitions whose side-effect class is `read` are executable in F2, their
 * handlers are resolved from a code allowlist in `ReadToolExecutor`, and the
 * handlers themselves are readers with no write path. This guard is the second
 * lock on the same door: if a future edit slips a write into a reader, the
 * invocation fails as `internal` instead of quietly changing data.
 *
 * The infrastructure writes of the invocation itself (projection, events,
 * audit, usage) happen OUTSIDE the guarded window, so the two kinds of write
 * never get confused with one another.
 */
final class ReadOnlyQueryGuard
{
    private const WRITE = '/^\s*(insert|update|delete|truncate|alter|drop|create|replace|merge)\b/i';

    private bool $listening = false;

    private bool $active = false;

    private int $writes = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ToolRuleException when the callback issued any write
     */
    public function run(Closure $callback): mixed
    {
        $this->listen();

        $outerActive = $this->active;
        $outerWrites = $this->writes;
        $this->active = true;
        $this->writes = 0;

        try {
            $result = $callback();
            $writes = $this->writes;
        } finally {
            $this->active = $outerActive;
            $this->writes = $outerWrites;
        }

        if ($writes > 0) {
            throw ToolRuleException::of('read_only', 'أداة قراءة حاولت الكتابة في قاعدة البيانات؛ رُفض التنفيذ.');
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
            if ($this->active && preg_match(self::WRITE, $query->sql) === 1) {
                $this->writes++;
            }
        });
    }
}

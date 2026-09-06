<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared CSV contract for every finance export (Phase E5.1):
 *  - UTF-8 BOM, streamed, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`;
 *  - every row starts with a `section` column; the `meta` section comes first
 *    (timezone=UTC, basis, reporting currency, window / month, filters,
 *    generated_at) and every section opens with its own header row;
 *  - unknown / not available numbers are EMPTY cells next to an explicit
 *    status column — never "0";
 *  - identifiers and bounded references only: no names, phones, e-mails,
 *    free text or raw payloads.
 * Writers read through the same services / projections as the pages; they
 * never write and never recompute.
 */
final class CsvWriter
{
    /** @var resource */
    private $out;

    private bool $open = false;

    /**
     * @param  resource  $out
     */
    private function __construct($out)
    {
        $this->out = $out;
    }

    /**
     * @param  callable(CsvWriter): void  $body
     */
    public static function download(string $filename, callable $body): StreamedResponse
    {
        return response()->streamDownload(static function () use ($body): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            $writer = new self($out);
            $body($writer);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The mandatory first section. `basis` names what the numbers are (LIVE / CURRENT,
     * FROZEN CLOSE REVISION n, CALCULATED …) so the file can never be read as something else.
     *
     * @param  array<string, string|int|null>  $meta
     */
    public function meta(array $meta): void
    {
        $this->section('meta', ['key', 'value']);

        foreach (['timezone' => 'UTC'] + $meta + ['generated_at' => CarbonImmutable::now('UTC')->toIso8601String()] as $key => $value) {
            $this->row('meta', [$key, $value === null ? '' : (string) $value]);
        }
    }

    /**
     * Open a section: blank separator line (except for the first) + header row.
     *
     * @param  list<string>  $columns
     */
    public function section(string $name, array $columns): void
    {
        if ($this->open) {
            fwrite($this->out, "\n");
        }

        $this->open = true;
        fputcsv($this->out, ['section', ...$columns]);
        $this->flush();
    }

    /**
     * @param  list<string|int|float|null>  $values  null ⇒ empty cell (never "0")
     */
    public function row(string $section, array $values): void
    {
        fputcsv($this->out, [$section, ...array_map(static fn ($v) => $v === null ? '' : (string) $v, $values)]);
    }

    public function flush(): void
    {
        if (function_exists('flush')) {
            flush();
        }
    }

    /** `Y-m-d\TH:i:s\Z` for a UTC timestamp, or empty. */
    public static function utc(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}

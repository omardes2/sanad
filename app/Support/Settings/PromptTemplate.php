<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * Deliberately dumb prompt templates: plain text plus `{placeholder}` tokens
 * from an explicit allowlist, substituted with strtr(). Nothing is executed —
 * no Blade, no PHP, no expression syntax — so an admin-edited template can
 * never run code. Validation rejects any placeholder outside the allowlist
 * and any missing required placeholder.
 */
final class PromptTemplate
{
    private const PLACEHOLDER = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     * @return list<string> human-readable validation errors (empty = valid)
     */
    public static function validate(string $template, array $allowed, array $required = []): array
    {
        $errors = [];
        $found = self::placeholders($template);

        foreach (array_diff($found, $allowed) as $unknown) {
            $errors[] = "العنصر {{$unknown}} غير معروف. المسموح: ".($allowed === [] ? 'لا شيء' : implode(', ', array_map(static fn (string $p): string => '{'.$p.'}', $allowed))).'.';
        }

        foreach (array_diff($required, $found) as $missing) {
            $errors[] = "العنصر {{$missing}} مطلوب ولم يُذكر في النص.";
        }

        return $errors;
    }

    /**
     * Substitute ONLY the given variables; anything else is left as literal text.
     *
     * @param  array<string, string|int|float>  $variables
     */
    public static function render(string $template, array $variables): string
    {
        $map = [];

        foreach ($variables as $name => $value) {
            $map['{'.$name.'}'] = (string) $value;
        }

        return strtr($template, $map);
    }

    /**
     * @return list<string>
     */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}

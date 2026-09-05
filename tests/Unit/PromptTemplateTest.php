<?php

declare(strict_types=1);

use App\Support\Settings\PromptTemplate;

it('renders allowlisted placeholders with strtr and nothing else', function () {
    $out = PromptTemplate::render('الوقت ({timezone}): {now}. {unknown} stays. {{now}} too.', ['timezone' => 'Asia/Hebron', 'now' => '10:00']);

    expect($out)->toBe('الوقت (Asia/Hebron): 10:00. {unknown} stays. {10:00} too.');
});

it('validates: unknown placeholders and missing required ones are rejected', function () {
    expect(PromptTemplate::validate('x {now} {timezone}', ['timezone', 'now'], ['now']))->toBe([])
        ->and(PromptTemplate::validate('x {now} {evil}', ['timezone', 'now'], ['now']))->toHaveCount(1)
        ->and(PromptTemplate::validate('x {timezone}', ['timezone', 'now'], ['now']))->toHaveCount(1)
        ->and(PromptTemplate::validate('no placeholders at all', [], []))->toBe([])
        ->and(PromptTemplate::validate('{anything}', [], []))->toHaveCount(1);
});

it('never executes anything: Blade, PHP and expression-like syntax are inert text', function () {
    $template = '{{ config("app.key") }} <?php echo 1; ?> @php(1) {now} $x #y';

    expect(PromptTemplate::validate($template, ['now'], ['now']))->toBe([])
        ->and(PromptTemplate::render($template, ['now' => 'T']))->toBe('{{ config("app.key") }} <?php echo 1; ?> @php(1) T $x #y')
        // Brace-wrapped names are placeholders and must be allowlisted — no exceptions.
        ->and(PromptTemplate::validate('{now} ${x}', ['now'], ['now']))->toHaveCount(1);
});

it('lists placeholders once each', function () {
    expect(PromptTemplate::placeholders('{a} {b} {a} {c_d} {1bad}'))->toBe(['a', 'b', 'c_d']);
});

<?php

declare(strict_types=1);

use App\Data\Launch\GateOutcome;
use App\Enums\LaunchGateOwner;
use App\Enums\LaunchGateState;
use App\Services\Launch\Checks\FeatureChecks;
use App\Services\Launch\LaunchReadiness;
use App\Support\Launch\LaunchGate;
use App\Support\Launch\LaunchGateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A check that fails, to prove an unreadable gate becomes NOT OBSERVED. */
final class ExplodingCheck
{
    public static function run(): GateOutcome
    {
        throw new RuntimeException('the check itself failed');
    }
}

/*
|--------------------------------------------------------------------------
| The registry is the runtime authority
|--------------------------------------------------------------------------
*/

it('declares every gate in code with a resolvable check', function () {
    $gates = app(LaunchGateRegistry::class)->all();

    expect($gates)->not->toBeEmpty();

    foreach ($gates as $gate) {
        expect($gate)->toBeInstanceOf(LaunchGate::class)
            ->and($gate->key)->not->toBe('')
            ->and($gate->title)->not->toBe('')
            ->and($gate->why)->not->toBe('')
            ->and($gate->owner)->toBeInstanceOf(LaunchGateOwner::class)
            // Every check runs and answers with a typed outcome.
            ->and($gate->evaluate())->toBeInstanceOf(GateOutcome::class);
    }
});

it('has unique gate keys', function () {
    $keys = app(LaunchGateRegistry::class)->keys();

    expect($keys)->toEqual(array_unique($keys));
});

it('refuses a gate whose check is not callable', function () {
    LaunchGate::of('x', 'X', LaunchGateOwner::Sanad, true, 'why', ['App\\Nope', 'missing']);
})->throws(InvalidArgumentException::class);

it('refuses a gate with no key or title', function () {
    LaunchGate::of('  ', 'X', LaunchGateOwner::Sanad, true, 'why', [FeatureChecks::class, 'morningBrief']);
})->throws(InvalidArgumentException::class);

/*
|--------------------------------------------------------------------------
| Runtime readiness NEVER parses documentation
|--------------------------------------------------------------------------
*/

it('never reads a markdown file to decide readiness', function () {
    $sources = [
        app_path('Support/Launch/LaunchGateRegistry.php'),
        app_path('Services/Launch/LaunchReadiness.php'),
        app_path('Services/Launch/OperationalWarnings.php'),
        ...glob(app_path('Services/Launch/Checks/*.php')),
    ];

    foreach ($sources as $file) {
        // COMMENTS STRIPPED: the registry's docblock names the document it
        // mirrors, which is useful prose. What must not exist is executable code
        // that reads it — so the assertion is about the code, not the comments.
        $code = php_strip_whitespace($file);

        expect($code)->not->toContain('.md')
            ->and($code)->not->toContain('SANAD_V1_LAUNCH_SCOPE')
            ->and($code)->not->toContain('file_get_contents')
            ->and($code)->not->toContain('file_exists')
            ->and($code)->not->toContain('base_path')
            ->and($code)->not->toContain('Storage::')
            ->and($code)->not->toContain('File::');
    }
});

/*
|--------------------------------------------------------------------------
| The V1 list, as corrected
|--------------------------------------------------------------------------
*/

it('requires exactly the approved V1 items', function () {
    $required = array_map(
        static fn (LaunchGate $gate): string => $gate->key,
        app(LaunchGateRegistry::class)->requiredForV1(),
    );

    sort($required);

    expect($required)->toBe([
        'ai.provider',
        'brief.morning',
        'infra.queue',
        'infra.scheduler_cron',
        'memory.encryption',
        'memory.system',
        'payments.cybersource',
        'provider.tool_calling',
        'reminders.delivery',
        'reminders.follow_up',
        'reminders.recurring',
        'reminders.scheduler',
        'reminders.template',
        'tools.rate_limiting',
        'voice.transcription',
        'whatsapp',
    ]);
});

it('treats implicit extraction and semantic retrieval as POST-V1, never as blockers', function () {
    $registry = app(LaunchGateRegistry::class);

    foreach (['memory.implicit_extraction', 'memory.semantic_retrieval'] as $key) {
        expect($registry->find($key)->requiredForV1)->toBeFalse();
    }

    $blockerKeys = array_map(
        static fn ($status): string => $status->gate->key,
        app(LaunchReadiness::class)->blockers(),
    );

    expect($blockerKeys)->not->toContain('memory.implicit_extraction')
        ->and($blockerKeys)->not->toContain('memory.semantic_retrieval');
});

it('does not make billing enforcement a launch blocker', function () {
    config()->set('billing.enforce', false);

    $registry = app(LaunchGateRegistry::class);

    expect($registry->find('billing.enforcement')->requiredForV1)->toBeFalse();

    $blockerKeys = array_map(
        static fn ($status): string => $status->gate->key,
        app(LaunchReadiness::class)->blockers(),
    );

    expect($blockerKeys)->not->toContain('billing.enforcement');
});

/*
|--------------------------------------------------------------------------
| Blocking semantics
|--------------------------------------------------------------------------
*/

it('never lets NOT OBSERVED or READY block a launch', function () {
    expect(LaunchGateState::NotObserved->blocksLaunch())->toBeFalse()
        ->and(LaunchGateState::Ready->blocksLaunch())->toBeFalse()
        ->and(LaunchGateState::NotReady->blocksLaunch())->toBeTrue()
        ->and(LaunchGateState::NotImplemented->blocksLaunch())->toBeTrue()
        ->and(LaunchGateState::BlockedExternal->blocksLaunch())->toBeTrue();
});

it('reports a check that throws as NOT OBSERVED rather than ready or broken', function () {
    $gate = LaunchGate::of('boom', 'Boom', LaunchGateOwner::Sanad, true, 'why', [ExplodingCheck::class, 'run']);

    $statuses = app(LaunchReadiness::class)->evaluateGates([$gate]);

    expect($statuses[0]->state)->toBe(LaunchGateState::NotObserved)
        // A gate we could not evaluate is unknown, and unknown must not block.
        ->and($statuses[0]->blocksLaunch())->toBeFalse();
});

it('counts a blocker only when the gate is required AND the state blocks', function () {
    $statuses = app(LaunchReadiness::class)->evaluate();

    foreach ($statuses as $status) {
        expect($status->blocksLaunch())
            ->toBe($status->gate->requiredForV1 && $status->state->blocksLaunch());
    }
});

/*
|--------------------------------------------------------------------------
| The document mirrors the registry — and is checked, not trusted
|--------------------------------------------------------------------------
|
| Runtime never reads this file (asserted above). But a mirror that silently
| drifts is worse than no mirror, so the agreement is a test rather than a
| convention: every V1 gate must be findable in the document, and the document
| must say plainly that it is not the authority.
*/

it('states in the document that the registry is the authority', function () {
    $doc = file_get_contents(base_path('docs/SANAD_V1_LAUNCH_SCOPE.md'));

    expect($doc)->toContain('LaunchGateRegistry')
        ->and($doc)->toContain('هذا الجدول مرآة، لا مرجع');
});

it('mentions every V1 gate in the launch scope document', function () {
    $doc = file_get_contents(base_path('docs/SANAD_V1_LAUNCH_SCOPE.md'));

    // Each gate is looked up by a phrase that identifies it to a human reader.
    $phrases = [
        'ai.provider' => 'جاهزية مزوّد الذكاء الاصطناعي',
        'whatsapp' => 'جاهزية واتساب',
        'provider.tool_calling' => 'rateLimitPerHour',
        'voice.transcription' => 'Voice Notes',
        'reminders.recurring' => 'Recurring Reminder Model',
        'reminders.follow_up' => 'Follow-Up Until Done',
        'brief.morning' => 'Morning Brief',
        'reminders.template' => 'WHATSAPP_REMINDER_TEMPLATE_READY',
        'memory.encryption' => 'MEMORY_KEY',
        'tools.rate_limiting' => 'Rate limiting / abuse protection',
        'infra.queue' => 'عامل الطابور',
        'payments.cybersource' => 'BANK_CYBERSOURCE_DETAILS',
    ];

    // `toContain` is VARIADIC in Pest — a second argument is another needle,
    // not a failure message. The gate key is asserted separately so a drift
    // still names the gate that drifted.
    foreach ($phrases as $key => $phrase) {
        $missing = str_contains($doc, $phrase) ? null : $key;

        expect($missing)->toBeNull();
    }
});

it('records the two POST-V1 items as deferred rather than deleting them', function () {
    $doc = file_get_contents(base_path('docs/SANAD_V1_LAUNCH_SCOPE.md'));

    expect($doc)->toContain('POST-V1 (مؤجَّل عن قصد — لا يحجب الإطلاق)')
        ->and($doc)->toContain('Implicit memory extraction')
        ->and($doc)->toContain('Semantic memory retrieval');
});

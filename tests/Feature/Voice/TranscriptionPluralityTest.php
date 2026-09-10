<?php

declare(strict_types=1);

use App\Contracts\Ai\SupportsTranscription;
use App\Data\Ai\Catalog\ModelSpec;
use App\Data\Ai\TranscriptionRequest;
use App\Data\Ai\TranscriptionResult;
use App\Enums\AiOperation;
use App\Enums\TranscriptionFailureReason;
use App\Enums\TranscriptionStatus;
use App\Exceptions\Ai\AiConfigurationException;
use App\Models\Message;
use App\Models\UsageEvent;
use App\Providers\Ai\GroqProvider;
use App\Providers\Ai\OpenAIProvider;
use App\Services\Ai\AiManager;
use App\Services\Ai\SanadAiRouter;
use App\Services\Voice\VoiceNoteTranscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * PROVIDER PLURALITY: transcription belongs to the platform, not to a vendor.
 *
 * The product requirement these tests defend is that an operator can choose
 * which provider transcribes voice notes, and change that choice, WITHOUT a
 * code change — through the same provider catalog, model catalog, capabilities
 * and routing that chat already uses. No voice-only routing architecture exists,
 * and the tests below would fail if one were introduced.
 *
 * The strongest of them is the last group: a provider the app has never heard
 * of, registered at runtime, drives the identical pipeline. If that holds, then
 * "adding a provider later is an adapter plus catalog data" is a fact rather
 * than an intention.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    voiceConfigure();
    Queue::fake();
});

/** Both catalogued, both keyed: the ambiguous case the router must resolve. */
function pluralConfigure(string $preferred = 'groq'): void
{
    config([
        'ai.provider' => $preferred,
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.transcription_model' => 'openai-transcribe',
        'ai.providers.groq.api_key' => 'test-groq-key',
        'ai.providers.groq.transcription_model' => 'groq-transcribe',
    ]);
}

/*
|--------------------------------------------------------------------------
| Both shipped adapters satisfy the contract
|--------------------------------------------------------------------------
*/

it('has both shipped providers satisfying SupportsTranscription through the same two lines', function (string $key, string $class) {
    $provider = app(AiManager::class)->provider($key);

    expect($provider)->toBeInstanceOf($class)
        ->and($provider)->toBeInstanceOf(SupportsTranscription::class)
        ->and($provider->supports(AiOperation::Transcription))->toBeTrue()
        ->and($provider->supports(AiOperation::Chat))->toBeTrue()
        // The capability is declared, not inferred: a provider that cannot
        // transcribe must be able to say so, and these say they can.
        ->and(method_exists($provider, 'transcribe'))->toBeTrue();
})->with([
    'openai' => ['openai', OpenAIProvider::class],
    'groq' => ['groq', GroqProvider::class],
]);

it('transcribes through either shipped adapter with the same generic request', function (string $key, string $host) {
    Http::fake([$host => Http::response(['text' => 'مرحبا', 'language' => 'ar'], 200)]);
    config(['ai.providers.'.$key.'.api_key' => 'test-'.$key.'-key']);

    /** @var SupportsTranscription $provider */
    $provider = app(AiManager::class)->provider($key);

    $result = $provider->transcribe(new TranscriptionRequest(
        path: base_path('tests/Fixtures/voice/voice-note-7500ms.ogg'),
        mimeType: 'audio/ogg; codecs=opus',
        spec: new ModelSpec($key, $key.'-transcribe', [AiOperation::Transcription]),
        languageHint: 'ar',
    ));

    expect($result)->toBeInstanceOf(TranscriptionResult::class)
        ->and($result->text)->toBe('مرحبا')
        ->and($result->provider)->toBe($key)
        // The model is the ROUTED one, echoed back — never a literal the adapter
        // chose for itself.
        ->and($result->model)->toBe($key.'-transcribe');

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/audio/transcriptions'));
})->with([
    'openai' => ['openai', 'api.openai.com/*'],
    'groq' => ['groq', 'api.groq.com/*'],
]);

/*
|--------------------------------------------------------------------------
| Routing selects either one, through the ordinary catalog
|--------------------------------------------------------------------------
*/

it('routes transcription to whichever provider the ordinary routing preference names', function (string $preferred, string $model) {
    pluralConfigure($preferred);

    $route = app(SanadAiRouter::class)->route(AiOperation::Transcription);

    expect($route->provider->name())->toBe($preferred)
        ->and($route->model)->toBe($model)
        ->and($route->provider)->toBeInstanceOf(SupportsTranscription::class);
})->with([
    'openai preferred' => ['openai', 'openai-transcribe'],
    'groq preferred' => ['groq', 'groq-transcribe'],
]);

it('changes the provider that actually transcribes by configuration alone — no code change', function () {
    pluralConfigure('groq');
    [$user, $account] = voiceSubscriber();

    // First voice note, with Groq preferred.
    voiceFakeHttp();
    $first = voiceNote($user, $account);
    app(VoiceNoteTranscriber::class)->transcribe($first->getKey());

    // The ONLY thing that changes between the two halves of this test is the
    // routing preference. No class is swapped, no branch is taken, no voice
    // setting is touched.
    config(['ai.provider' => 'openai']);

    voiceFakeHttp(['transcription' => Http::response(['text' => 'من أوبن إيه آي', 'language' => 'ar'], 200)]);
    Http::fake([
        'graph.facebook.com/v21.0/*' => Http::response([
            'url' => 'https://lookaside.example/media/test',
            'mime_type' => 'audio/ogg; codecs=opus',
            'file_size' => strlen(voiceFixtureBytes()),
        ], 200),
        'lookaside.example/*' => static fn () => Http::response(voiceFixtureBytes(), 200),
        'api.openai.com/*' => Http::response(['text' => 'من أوبن إيه آي', 'language' => 'ar'], 200),
    ]);

    $second = voiceNote($user, $account);
    app(VoiceNoteTranscriber::class)->transcribe($second->getKey());

    expect($first->refresh()->transcription_provider)->toBe('groq')
        ->and($first->transcription_model)->toBe('groq-transcribe')
        ->and($second->refresh()->transcription_provider)->toBe('openai')
        ->and($second->transcription_model)->toBe('openai-transcribe')
        // Both succeeded, identically, through one pipeline.
        ->and($first->transcription_status)->toBe(TranscriptionStatus::Transcribed)
        ->and($second->transcription_status)->toBe(TranscriptionStatus::Transcribed);
});

it('offers both providers as eligible when both are catalogued and keyed', function () {
    pluralConfigure('groq');

    $eligible = app(SanadAiRouter::class)->evaluate(AiOperation::Transcription)->eligible();
    $providers = array_map(static fn ($spec): string => $spec->provider, $eligible);

    // The admin/routing layer can pick either: that is what selectable means.
    expect($providers)->toContain('openai')
        ->and($providers)->toContain('groq');
});

/*
|--------------------------------------------------------------------------
| Eligibility is the ordinary five conditions, not a voice-only rule
|--------------------------------------------------------------------------
*/

it('rejects a chat-only model for transcription', function () {
    // The chat models are catalogued and keyed — they are simply not
    // transcription-capable, and capability is what decides.
    config([
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.providers.openai.transcription_model' => null,
        'ai.providers.groq.transcription_model' => null,
    ]);

    expect(fn () => app(SanadAiRouter::class)->route(AiOperation::Transcription))
        ->toThrow(AiConfigurationException::class);

    // And chat itself still routes: the chat rows were never the problem.
    expect(app(SanadAiRouter::class)->route(AiOperation::Chat)->model)->not->toBeEmpty();
});

it('rejects a provider whose adapter cannot transcribe, however the catalog advertises it', function () {
    voiceRegisterChatOnlyProvider('chatonly');

    config([
        'ai.providers.groq.transcription_model' => null,
        'ai.providers.openai.transcription_model' => null,
        'ai.catalog' => [[
            'provider' => 'chatonly',
            'model' => 'pretend-transcribe',
            'capabilities' => ['transcription'],
            'enabled' => true,
            'priority' => 100,
        ]],
    ]);

    // The router itself refuses it: the provider does not support the operation.
    $row = collect(app(SanadAiRouter::class)->evaluate(AiOperation::Transcription)->candidates)
        ->firstWhere(fn (array $r): bool => $r['spec']->provider === 'chatonly');

    expect($row['status'])->toBe('skipped')
        ->and($row['reason'])->toBe('provider_unsupported_operation');

    // And the voice pipeline refuses it a second time, because operator data
    // must never be able to reach a provider that cannot serve it.
    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $outcome = app(VoiceNoteTranscriber::class)->transcribe(voiceNote($user, $account)->getKey());

    expect($outcome->reason)->toBe(TranscriptionFailureReason::TranscriptionNotConfigured)
        ->and($outcome->providerRequested)->toBeFalse()
        ->and(collect(Http::recorded()))->toBeEmpty();
});

it('makes a provider ineligible when its credential is missing or failed closed', function (array $overrides) {
    config(array_merge([
        'ai.providers.openai.transcription_model' => null,
        'ai.providers.groq.transcription_model' => 'groq-transcribe',
    ], $overrides));

    expect(fn () => app(SanadAiRouter::class)->route(AiOperation::Transcription))
        ->toThrow(AiConfigurationException::class);
})->with([
    'missing key' => [['ai.providers.groq.api_key' => '']],
    'no base url' => [['ai.providers.groq.base_url' => '']],
]);

it('falls through to the other provider when the preferred one has no credential', function () {
    pluralConfigure('groq');
    config(['ai.providers.groq.api_key' => '']);

    $route = app(SanadAiRouter::class)->route(AiOperation::Transcription);

    // Plurality earning its keep: one unkeyed provider is a degraded
    // deployment, not a dead capability.
    expect($route->provider->name())->toBe('openai')
        ->and($route->model)->toBe('openai-transcribe');
});

/*
|--------------------------------------------------------------------------
| No lock-in: the pipeline is identical for any provider
|--------------------------------------------------------------------------
*/

it('drives a provider the app has never heard of through the identical pipeline', function () {
    $calls = [];
    voiceRegisterTranscribingProvider('acme', $calls, 'نصّ من مزوّد ثالث');

    config([
        'ai.providers.groq.transcription_model' => null,
        'ai.providers.openai.transcription_model' => null,
        'ai.catalog' => [[
            'provider' => 'acme',
            'model' => 'acme-whisper-1',
            'capabilities' => ['transcription'],
            'enabled' => true,
            'priority' => 100,
        ]],
    ]);

    [$user, $account] = voiceSubscriber();
    voiceFakeHttp();
    $message = voiceNote($user, $account);

    $outcome = app(VoiceNoteTranscriber::class)->transcribe($message->getKey());
    $message->refresh();

    expect($outcome->succeeded())->toBeTrue()
        ->and($message->text_content)->toBe('نصّ من مزوّد ثالث')
        ->and($message->transcription_provider)->toBe('acme')
        ->and($message->transcription_model)->toBe('acme-whisper-1')
        // Every other guarantee is unchanged for a provider nobody anticipated:
        // one attempt, the measured duration, and the usage rows.
        ->and($message->transcription_attempts)->toBe(1)
        ->and($message->voice_duration_ms)->toBe(7500)
        ->and(UsageEvent::count())->toBe(2)
        // And the request it received was the generic one, carrying the routed
        // model and nothing vendor-shaped.
        ->and($calls)->toHaveCount(1)
        ->and($calls[0])->toBeInstanceOf(TranscriptionRequest::class)
        ->and($calls[0]->spec->model)->toBe('acme-whisper-1')
        ->and($calls[0]->mimeType)->toBe('audio/ogg; codecs=opus')
        ->and($calls[0]->durationMs)->toBe(7500);
});

it('keeps the generic transcription DTOs free of any provider-specific field', function () {
    $request = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(TranscriptionRequest::class))->getProperties(),
    );
    $result = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(TranscriptionResult::class))->getProperties(),
    );

    sort($request);
    sort($result);

    expect($request)->toBe(['durationMs', 'languageHint', 'mimeType', 'path', 'spec', 'timeout'])
        ->and($result)->toBe(['durationMs', 'language', 'metadata', 'model', 'provider', 'text']);

    // `provider` and `model` are IDENTIFIERS of whoever served the request, not
    // vendor-shaped fields: they hold whatever the router resolved.
    foreach ([...$request, ...$result] as $name) {
        expect(strtolower($name))->not->toContain('groq')
            ->and(strtolower($name))->not->toContain('openai')
            ->and(strtolower($name))->not->toContain('whisper');
    }
});

it('keeps the messages schema free of any provider-specific column', function () {
    $columns = collect(Schema::getColumns('messages'))->pluck('name')->all();

    foreach ($columns as $column) {
        expect(strtolower($column))->not->toContain('groq')
            ->and(strtolower($column))->not->toContain('openai')
            ->and(strtolower($column))->not->toContain('whisper');
    }

    // The two provider-shaped facts are generic columns that RECORD what served
    // a voice note, not columns that presume who will.
    expect($columns)->toContain('transcription_provider')
        ->and($columns)->toContain('transcription_model');
});

it('contains no provider-name branching anywhere in the voice path', function () {
    $paths = [
        app_path('Services/Voice'),
        app_path('Jobs/TranscribeVoiceNote.php'),
        app_path('Data/Voice'),
        app_path('Data/Ai/TranscriptionRequest.php'),
        app_path('Data/Ai/TranscriptionResult.php'),
        app_path('Contracts/Ai/SupportsTranscription.php'),
        app_path('Enums/TranscriptionStatus.php'),
        app_path('Enums/TranscriptionFailureReason.php'),
        app_path('Support/Voice'),
        app_path('Services/WhatsApp/WhatsAppMediaClient.php'),
        config_path('voice.php'),
    ];

    // Comments are stripped first: the assertion is about CODE. A docblock may
    // legitimately name a vendor as an example; a branch may not.
    foreach ($paths as $path) {
        $files = is_dir($path) ? glob($path.'/*.php') : [$path];

        foreach ((array) $files as $file) {
            $code = strtolower(php_strip_whitespace((string) $file));
            // One needle per call: Pest's `toContain` is VARIADIC, so a second
            // argument would be read as another needle rather than a message —
            // quietly weakening the assertion instead of labelling it.
            $where = 'vendor name in '.basename((string) $file);

            foreach (['groq', 'openai', 'gemini'] as $vendor) {
                expect(str_contains($code, $vendor))->toBeFalse($where.': '.$vendor);
            }
        }
    }
});

it('takes the transcription model from the route, never from a provider-specific setting', function () {
    // The config key exists only as a CATALOG SOURCE. Prove it: catalogue the
    // model explicitly, leave every `transcription_model` unset, and the
    // pipeline still works — so nothing in the domain path reads those keys.
    config([
        'ai.providers.groq.transcription_model' => null,
        'ai.providers.openai.transcription_model' => null,
        'ai.providers.openai.api_key' => 'test-openai-key',
        'ai.catalog' => [[
            'provider' => 'openai',
            'model' => 'catalogued-only-transcribe',
            'capabilities' => ['transcription'],
            'enabled' => true,
            'priority' => 100,
        ]],
    ]);

    [$user, $account] = voiceSubscriber();
    Http::fake([
        'graph.facebook.com/v21.0/*' => Http::response([
            'url' => 'https://lookaside.example/media/test',
            'mime_type' => 'audio/ogg; codecs=opus',
            'file_size' => strlen(voiceFixtureBytes()),
        ], 200),
        'lookaside.example/*' => static fn () => Http::response(voiceFixtureBytes(), 200),
        'api.openai.com/*' => Http::response(['text' => 'من الفهرس', 'language' => 'ar'], 200),
    ]);

    $message = voiceNote($user, $account);
    app(VoiceNoteTranscriber::class)->transcribe($message->getKey());

    expect($message->refresh()->transcription_model)->toBe('catalogued-only-transcribe')
        ->and($message->transcription_provider)->toBe('openai')
        ->and(config('ai.providers.openai.transcription_model'))->toBeNull();
});

it('records the serving provider on the message, whichever one it was', function (string $preferred) {
    pluralConfigure($preferred);
    [$user, $account] = voiceSubscriber();

    Http::fake([
        'graph.facebook.com/v21.0/*' => Http::response([
            'url' => 'https://lookaside.example/media/test',
            'mime_type' => 'audio/ogg; codecs=opus',
            'file_size' => strlen(voiceFixtureBytes()),
        ], 200),
        'lookaside.example/*' => static fn () => Http::response(voiceFixtureBytes(), 200),
        'api.openai.com/*' => Http::response(['text' => 'نصّ', 'language' => 'ar'], 200),
        'api.groq.com/*' => Http::response(['text' => 'نصّ', 'language' => 'ar'], 200),
    ]);

    $message = voiceNote($user, $account);
    app(VoiceNoteTranscriber::class)->transcribe($message->getKey());

    // The ledger and the message both attribute the work to whoever did it —
    // which is the only way a cost report can ever be right across providers.
    $usage = DB::table('usage_events')->where('correlation_id', 'message:'.$message->getKey())->get();

    expect($message->refresh()->transcription_provider)->toBe($preferred)
        ->and($usage)->toHaveCount(2)
        ->and($usage->pluck('provider')->unique()->all())->toBe([$preferred]);
})->with(['openai', 'groq']);

it('leaves one reply and one transcript per voice note whichever provider serves it', function (string $preferred) {
    pluralConfigure($preferred);
    [$user, $account] = voiceSubscriber();

    Http::fake([
        'graph.facebook.com/v21.0/*' => Http::response([
            'url' => 'https://lookaside.example/media/test',
            'mime_type' => 'audio/ogg; codecs=opus',
            'file_size' => strlen(voiceFixtureBytes()),
        ], 200),
        'lookaside.example/*' => static fn () => Http::response(voiceFixtureBytes(), 200),
        'api.openai.com/*' => Http::response(['text' => 'نصّ', 'language' => 'ar'], 200),
        'api.groq.com/*' => Http::response(['text' => 'نصّ', 'language' => 'ar'], 200),
    ]);

    $message = voiceNote($user, $account);

    app(VoiceNoteTranscriber::class)->transcribe($message->getKey());
    app(VoiceNoteTranscriber::class)->transcribe($message->getKey());

    // The replay guard is the persisted transcript, not the provider's identity.
    expect(Message::count())->toBe(1)
        ->and($message->refresh()->transcription_attempts)->toBe(1);
})->with(['openai', 'groq']);

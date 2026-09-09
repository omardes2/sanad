<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Enums\ToolInvocationFailureKind;
use App\Exceptions\Memory\MemoryUnavailableException;
use App\Exceptions\Tools\ToolDomainException;
use App\Models\Memory;
use App\Models\User;
use App\Support\Memory\MemoryFingerprint;
use App\Support\Memory\MemoryText;
use App\Support\Memory\SensitiveContent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY writer and the only reader of `memories` (Phase G).
 *
 * DURABLE MEMORY IS NOT CONVERSATION HISTORY. History is `messages`, bounded to
 * the last few turns of one conversation and never curated. A memory is a small
 * fact with future value that follows the subscriber into every conversation,
 * and in V1 it exists for exactly one reason: THEY ASKED FOR IT. There is no
 * extraction path, no inference, no confidence threshold and no background job
 * that turns a message into a memory — a fact the subscriber did not choose to
 * keep is not kept.
 *
 * AUTHORITY IS CHECKED BEFORE THIS CLASS IS REACHED. The explicit-intent gate
 * lives in the tool executor (`ToolIntentRequirements`), consent lives in
 * `ToolConsentService`, and ownership comes from the invocation's stored
 * message. Nothing here takes a subscriber id, a memory id or a provenance from
 * a payload: `$subscriber` is passed in from trusted context, provenance is
 * always `explicit`, and no tool schema in the platform declares an id.
 *
 * WHAT IS BOUNDED, AND HOW:
 *   - content       ≤ `memory.max_content_chars`, enforced here as well as by
 *                   the tool schema, because a service must not trust that it
 *                   was reached through a tool;
 *   - active rows   ≤ `memory.max_active`. AT CAPACITY A NEW MEMORY IS REFUSED
 *                   (`memory_capacity_reached`). Nothing is evicted: an
 *                   explicit memory is removed only when the subscriber
 *                   explicitly forgets one;
 *   - decryption    one subscriber's active set, in application memory, per
 *                   call. There is no query that searches ciphertext and no
 *                   path that decrypts across subscribers.
 *
 * CONCURRENCY. Every write takes a row lock on the SUBSCRIBER first, so all of
 * one subscriber's memory writes serialise against each other; the unique index
 * on (user_id, category, fingerprint) is the second lock behind it. The whole
 * mutation runs inside the invocation's settlement transaction, so a refusal, a
 * crash or a lost settlement race leaves `memories` exactly as it was.
 */
final class MemoryService
{
    public function __construct(private readonly MemoryCipher $cipher) {}

    /**
     * `memory.write@1` — save what the subscriber asked to be remembered.
     *
     * The model never decides whether this is a new memory or an edit: the
     * SERVER decides, by fingerprint. Same normalised content in the same
     * category ⇒ the existing active memory is refreshed and `created` is
     * false. That is why there is no `memory.update@1` and why no tool schema
     * carries a memory id — an id the model could name is an id it could name
     * wrongly.
     *
     * @param  array{content: string, category: string, importance?: int}  $input  validated tool arguments
     * @return array{memory_id: int, created: bool}
     *
     * @throws ToolDomainException
     */
    public function remember(User $subscriber, array $input, ?int $sourceMessageId = null): array
    {
        $content = trim($input['content']);
        $category = $this->category($input['category']);
        $importance = $this->importance($input['importance'] ?? null);

        if ($content === '' || mb_strlen($content) > $this->maxContentChars()) {
            throw ToolDomainException::rule('محتوى الذاكرة يجب أن يكون نصًا ضمن الحد المعلن.');
        }

        if (MemoryText::normalize($content) === '') {
            throw ToolDomainException::rule('محتوى الذاكرة لا يحمل أي نص فعلي.');
        }

        // Refused WHOLE: the value is not stored, not logged and not redacted
        // into the row. Only the pattern name travels, for the log.
        if (($sensitive = SensitiveContent::match($content)) !== null) {
            Log::warning('sanad.memory.refused_sensitive', [
                'user_id' => $subscriber->getKey(),
                'pattern' => $sensitive,
            ]);

            throw new ToolDomainException(ToolInvocationFailureKind::SensitiveContent, 'محتوى حسّاس لا يُحفظ في الذاكرة.');
        }

        $subscriberId = (int) $subscriber->getKey();
        $fingerprint = $this->fingerprint($content);
        $sealed = $this->seal($content);

        // Serialise this subscriber's memory writes against each other. The
        // capacity check and the duplicate check are only meaningful together,
        // and only under one lock.
        $this->lockSubscriber($subscriberId);

        $existing = Memory::query()
            ->where('user_id', $subscriberId)
            ->where('category', $category->value)
            ->where('fingerprint', $fingerprint)
            ->whereNull('archived_at')
            ->first();

        if ($existing instanceof Memory) {
            return $this->refresh($existing, $importance, $sourceMessageId);
        }

        if ($this->activeCount($subscriberId) >= $this->maxActive()) {
            // NOT an eviction. An explicit memory the subscriber never asked to
            // forget is never displaced to make room for a newer one.
            throw new ToolDomainException(
                ToolInvocationFailureKind::MemoryCapacityReached,
                'ذاكرة المشترك ممتلئة؛ يلزم نسيان ذاكرة قائمة أولًا.',
            );
        }

        try {
            $memory = Memory::query()->create([
                'user_id' => $subscriberId,
                'category' => $category->value,
                'content' => $sealed,
                'fingerprint' => $fingerprint,
                'importance' => $importance,
                'provenance' => MemoryProvenance::Explicit->value,
                'source_message_id' => $sourceMessageId,
                'metadata' => null,
                'archived_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The database is the arbiter. A concurrent write of the same
            // memory won; this call refreshes the winner instead of inserting a
            // second row or reporting an error.
            $winner = Memory::query()
                ->where('user_id', $subscriberId)
                ->where('category', $category->value)
                ->where('fingerprint', $fingerprint)
                ->whereNull('archived_at')
                ->first();

            if (! $winner instanceof Memory) {
                throw ToolDomainException::rule('تعذّر حفظ الذاكرة بعد تصادم الهوية.');
            }

            return $this->refresh($winner, $importance, $sourceMessageId);
        }

        return ['memory_id' => (int) $memory->getKey(), 'created' => true];
    }

    /**
     * `memory.forget@1` — archive exactly one memory, resolved WITHOUT an id.
     *
     *   0 matches          → `not_found`
     *   1 unambiguous match → archived
     *   more than one      → `ambiguous`, and NOTHING is archived
     *
     * A vague query never sweeps several memories away. Archiving is not
     * deletion: the row stays, its `archived_at` is stamped, and its
     * fingerprint is released to NULL so the same memory can be saved again
     * later without colliding with the record of having forgotten it.
     *
     * @param  array{query: string}  $input  validated tool arguments
     * @return array{forgotten: int}
     *
     * @throws ToolDomainException
     */
    public function forget(User $subscriber, array $input): array
    {
        $subscriberId = (int) $subscriber->getKey();

        $this->lockSubscriber($subscriberId);

        $matches = $this->matching($subscriberId, $input['query']);

        if ($matches === []) {
            throw ToolDomainException::notFound('لا توجد ذاكرة مطابقة');
        }

        if (count($matches) > 1) {
            throw new ToolDomainException(
                ToolInvocationFailureKind::Ambiguous,
                'الطلب يطابق أكثر من ذاكرة؛ يلزم تحديد أدقّ.',
            );
        }

        /** @var Memory $memory */
        $memory = $matches[0]['memory'];

        $memory->forceFill([
            'archived_at' => now(),
            // Releases the unique slot: NULL never collides on either engine.
            'fingerprint' => null,
            'metadata' => array_merge((array) $memory->metadata, ['archived_reason' => 'subscriber_request']),
        ])->save();

        return ['forgotten' => 1];
    }

    /**
     * `memory.read@2` — the subscriber's own matching memories, as content.
     *
     * @param  array{query: string, limit?: int}  $input  validated tool arguments
     * @return array{memories: list<array{content: string, category: string, importance: int}>, truncated: bool}
     */
    public function recall(User $subscriber, array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? self::RECALL_DEFAULT), self::RECALL_MAX));
        $matches = $this->matching((int) $subscriber->getKey(), $input['query']);

        $rows = array_map(static fn (array $m): array => [
            'content' => $m['plain'],
            'category' => $m['memory']->category->value,
            'importance' => (int) $m['memory']->importance,
        ], array_slice($matches, 0, $limit));

        return ['memories' => $rows, 'truncated' => count($matches) > $limit];
    }

    /**
     * `memory.read@1` — the frozen counting contract, unchanged in meaning.
     *
     * Its implementation had to change: `content` is ciphertext now, so the
     * match happens in application memory over this subscriber's bounded active
     * set instead of as a SQL `ILIKE`. The declared contract — how many
     * memories match, and whether the bound cut the answer short — is exactly
     * what it always was.
     *
     * @param  array{query: string, limit?: int}  $input  validated tool arguments
     * @return array{matches: int, truncated: bool}
     */
    public function count(User $subscriber, array $input): array
    {
        $limit = max(1, min((int) ($input['limit'] ?? self::COUNT_DEFAULT), self::COUNT_MAX));
        $found = count($this->matching((int) $subscriber->getKey(), $input['query']));

        return ['matches' => min($found, $limit), 'truncated' => $found > $limit];
    }

    /**
     * The memories the prompt contributor may state, already decrypted and
     * ordered — most important first, then most recently confirmed.
     *
     * @return list<array{content: string, category: MemoryCategory, importance: int}>
     */
    public function forPrompt(User $subscriber, int $limit): array
    {
        $rows = [];

        foreach ($this->activeSet((int) $subscriber->getKey(), $limit) as $memory) {
            $plain = $this->open($memory);

            if ($plain !== null) {
                $rows[] = [
                    'content' => $plain,
                    'category' => $memory->category,
                    'importance' => (int) $memory->importance,
                ];
            }
        }

        return $rows;
    }

    public function available(): bool
    {
        return $this->cipher->available() && MemoryFingerprint::available();
    }

    // ------------------------------------------------------------ internals

    public const RECALL_MAX = 10;

    public const RECALL_DEFAULT = 5;

    public const COUNT_MAX = 50;

    public const COUNT_DEFAULT = 10;

    /**
     * A literal, case-insensitive substring match over this subscriber's
     * bounded active set, in the same order the contributor uses.
     *
     * The match is done on NORMALISED text on both sides, so the same word
     * typed with or without tashkeel matches — and, because the normaliser
     * folds no letters, two genuinely different words never do.
     *
     * @return list<array{memory: Memory, plain: string}>
     */
    private function matching(int $subscriberId, string $query): array
    {
        $needle = MemoryText::normalize($query);

        if ($needle === '') {
            return [];
        }

        $out = [];

        foreach ($this->activeSet($subscriberId, $this->maxActive()) as $memory) {
            $plain = $this->open($memory);

            if ($plain !== null && str_contains(MemoryText::normalize($plain), $needle)) {
                $out[] = ['memory' => $memory, 'plain' => $plain];
            }
        }

        return $out;
    }

    /**
     * ONE subscriber's active memories, hard-bounded. The write path keeps the
     * set within `memory.max_active`, so this bound is complete rather than
     * lossy; it is stated anyway so that no configuration change and no legacy
     * row can turn a read into an unbounded decryption.
     *
     * @return Collection<int, Memory>
     */
    private function activeSet(int $subscriberId, int $limit)
    {
        return Memory::query()
            ->forSubscriber($subscriberId)
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * The plaintext of one row, or null when it cannot be opened. An unopenable
     * row is SKIPPED by every caller rather than failing the request: one
     * corrupt or previous-key row must not take down every AI reply. Only the
     * row id is logged — never the sealed value.
     */
    private function open(Memory $memory): ?string
    {
        $plain = $this->cipher->open((string) $memory->getAttribute('content'));

        if ($plain === null) {
            Log::warning('sanad.memory.unreadable', ['memory_id' => $memory->getKey()]);
        }

        return $plain;
    }

    /**
     * @return array{memory_id: int, created: bool}
     */
    private function refresh(Memory $memory, int $importance, ?int $sourceMessageId): array
    {
        $memory->forceFill(array_filter([
            'importance' => $importance,
            'source_message_id' => $sourceMessageId,
        ], static fn ($v): bool => $v !== null))->touch();

        return ['memory_id' => (int) $memory->getKey(), 'created' => false];
    }

    /**
     * A per-subscriber mutex for the whole memory write. It is a READ lock on
     * the subscriber row, so it never writes outside this tool's declared
     * table and the write-scope guard stays satisfied.
     */
    private function lockSubscriber(int $subscriberId): void
    {
        if (DB::transactionLevel() > 0) {
            User::query()->whereKey($subscriberId)->lockForUpdate()->first();
        }
    }

    private function activeCount(int $subscriberId): int
    {
        return Memory::query()->where('user_id', $subscriberId)->whereNull('archived_at')->count();
    }

    private function fingerprint(string $content): string
    {
        try {
            return MemoryFingerprint::of($content);
        } catch (MemoryUnavailableException) {
            throw new ToolDomainException(ToolInvocationFailureKind::MemoryUnavailable, 'الذاكرة غير متاحة: لا يوجد مفتاح بصمة.');
        }
    }

    private function seal(string $content): string
    {
        try {
            return $this->cipher->seal($content);
        } catch (MemoryUnavailableException) {
            throw new ToolDomainException(ToolInvocationFailureKind::MemoryUnavailable, 'الذاكرة غير متاحة: لا يوجد مفتاح تشفير.');
        }
    }

    private function category(string $value): MemoryCategory
    {
        return MemoryCategory::tryFrom($value)
            ?? throw ToolDomainException::rule('صنف الذاكرة خارج القائمة المعلنة.');
    }

    private function importance(mixed $value): int
    {
        $importance = is_int($value) ? $value : 3;

        return max(1, min($importance, 5));
    }

    private function maxActive(): int
    {
        return max(1, (int) config('memory.max_active', 50));
    }

    private function maxContentChars(): int
    {
        return max(1, (int) config('memory.max_content_chars', 300));
    }
}

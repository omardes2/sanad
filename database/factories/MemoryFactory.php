<?php

namespace Database\Factories;

use App\Enums\MemoryCategory;
use App\Enums\MemoryProvenance;
use App\Models\Memory;
use App\Models\User;
use App\Services\Memory\MemoryCipher;
use App\Support\Memory\MemoryFingerprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Memory>
 */
class MemoryFactory extends Factory
{
    /** Makes each generated memory distinct without relying on faker's luck. */
    private static int $sequence = 0;

    /**
     * A factory memory is SEALED exactly as a real one is: `content` holds a
     * ciphertext envelope and `fingerprint` a keyed MAC. Writing plaintext here
     * would give the tests a row production can never produce.
     *
     * Pass the readable sentence through the `content` attribute as usual —
     * `configure()` seals it — or use `plain()` explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Distinct by construction. Two memories with the same normalised text
        // in the same category ARE one memory — the unique index says so — so a
        // factory that repeated itself would be building a row production can
        // never produce.
        self::$sequence++;

        return [
            'user_id' => User::factory(),
            'category' => fake()->randomElement(MemoryCategory::options()),
            'content' => fake()->sentence().' #'.self::$sequence,
            'importance' => fake()->numberBetween(1, 5),
            'provenance' => MemoryProvenance::Explicit->value,
            'source_message_id' => null,
            'metadata' => null,
            'archived_at' => null,
        ];
    }

    /** The readable sentence this memory holds. */
    public function plain(string $content): static
    {
        return $this->state(fn (array $attributes): array => ['content' => $content]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
            // Archiving releases the unique slot, exactly as the service does.
            'fingerprint' => null,
        ]);
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Memory $memory): void {
            $plain = (string) $memory->getAttribute('content');

            $memory->setAttribute('content', app(MemoryCipher::class)->seal($plain));

            if ($memory->getAttribute('archived_at') === null && $memory->getAttribute('fingerprint') === null) {
                // Scoped exactly as the one domain writer scopes it.
                /** @var MemoryCategory|string $category */
                $category = $memory->getAttribute('category');

                $memory->setAttribute('fingerprint', MemoryFingerprint::of(
                    (int) $memory->getAttribute('user_id'),
                    $category,
                    $plain,
                ));
            }
        });
    }
}

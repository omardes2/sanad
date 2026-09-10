<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Memory;

use App\Services\Memory\MemoryCipher;
use App\Services\Memory\MemoryOperationsQuery;
use App\Support\Rbac\Permission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Durable memory, as OPERATIONAL METADATA ONLY.
 *
 * No memory content is rendered on this page or the subscriber page beneath it —
 * not truncated, not blurred, not behind a toggle. There is no permission that
 * would reveal it, because none was created: every memory in V1 is something a
 * subscriber explicitly asked Sanad to keep about them, and an operator reading
 * it back is the most sensitive act the platform could offer. `MemoryProbe`
 * already exists for a developer with production shell access and an actual
 * reason; a dashboard button is a different thing entirely.
 *
 * The fingerprint is never rendered either — it is a keyed MAC over the content,
 * so showing one turns any viewer into a confirmation oracle for guesses.
 */
#[Title('الذاكرة الدائمة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Operations extends Component
{
    use WithPagination;

    #[Url]
    public string $subscriber_id = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can(Permission::MemoryOperationsView->value) ?? false, 403);
    }

    public function updated(string $property): void
    {
        if ($property === 'subscriber_id') {
            $this->resetPage();
        }
    }

    public function render(MemoryCipher $cipher)
    {
        $fingerprintKeySet = trim((string) config('memory.fingerprint_key', '')) !== '';

        return view('livewire.dashboard.memory.operations', [
            'overview' => MemoryOperationsQuery::overview(),
            'rows' => MemoryOperationsQuery::subscribers(['subscriber_id' => $this->subscriber_id]),
            // Presence booleans and a key ID. Never a key.
            'cipherAvailable' => $cipher->available(),
            'fingerprintKeySet' => $fingerprintKeySet,
            'keyId' => $cipher->available() ? $cipher->keyId() : null,
            'limits' => [
                'max_active' => (int) config('memory.max_active', 50),
                'max_content_chars' => (int) config('memory.max_content_chars', 300),
                'prompt_limit' => (int) config('memory.prompt_limit', 12),
                'prompt_max_chars' => (int) config('memory.prompt_max_chars', 1200),
            ],
        ]);
    }
}

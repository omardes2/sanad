<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Tools;

use App\Enums\ToolConsentReason;
use App\Exceptions\Tools\StaleToolConsentException;
use App\Exceptions\Tools\ToolRuleException;
use App\Models\ToolConsent;
use App\Services\Tools\ToolConsentService;
use App\Support\Rbac\Permission;
use App\Support\Tools\EvidenceRef;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One consent, its current state, and a REVOKE action. There is no grant action,
 * by design: staff may reduce a subscriber's authority, never create it.
 *
 * CONCURRENCY. `revoke()` carries the `expectedVersion` the page was rendered
 * with, and `ToolConsentService` refuses on a mismatch — so a revoke decided
 * from a stale screen writes NOTHING and says so, rather than overwriting a
 * decision someone made in the meantime. The same shape `SubscriberDetail` uses
 * for subscription transitions.
 *
 * AUTHORITY. The revoke permission is `subscribers.manage`, which is what
 * `ToolCapability::operatorPermission()` has declared since F1 — this page does
 * not introduce a second, competing permission for the same act, because the
 * service would ignore it and the RBAC matrix would then be describing a rule
 * that is not enforced.
 */
#[Title('موافقة أداة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class ConsentDetail extends Component
{
    public ToolConsent $consent;

    /** The version this page was rendered with — stated back on every mutation. */
    public int $expectedVersion = 0;

    public string $reasonCode = '';

    public string $evidence = '';

    public ?string $notice = null;

    public function mount(ToolConsent $consent): void
    {
        abort_unless(auth()->user()?->can(Permission::ToolsConsentsView->value) ?? false, 403);

        $this->consent = $consent;
        $this->expectedVersion = $consent->version;
        $this->reasonCode = ToolConsentReason::OperatorRequest->value;
    }

    public function revoke(ToolConsentService $consents): void
    {
        $this->notice = null;

        // Re-checked server-side even though the button is hidden without it.
        if (! $this->canRevoke()) {
            $this->addError('revoke', 'لا تملك صلاحية سحب الموافقة.');

            return;
        }

        if (! $this->consent->isGranted()) {
            $this->addError('revoke', 'هذه الموافقة مسحوبة بالفعل.');

            return;
        }

        $reason = ToolConsentReason::tryFrom($this->reasonCode);

        if ($reason === null) {
            $this->addError('reasonCode', 'سبب غير معروف.');

            return;
        }

        try {
            $consents->revoke(
                $this->consent->subscriber_id,
                $this->consent->capability,
                $this->expectedVersion,
                $reason,
                EvidenceRef::optional($this->evidence === '' ? null : $this->evidence),
            );
        } catch (StaleToolConsentException) {
            $this->refreshConsent();
            $this->addError('revoke', 'تغيّرت حالة الموافقة منذ فتح الصفحة (تعديل متزامن). لم يُنفَّذ شيء — راجع الحالة الحالية وقرّر مجددًا.');

            return;
        } catch (AuthorizationException) {
            $this->addError('revoke', 'لا تملك صلاحية سحب الموافقة.');

            return;
        } catch (ToolRuleException $e) {
            $this->addError('revoke', $e->getMessage());

            return;
        }

        $this->refreshConsent();
        $this->evidence = '';
        $this->notice = 'سُحبت الموافقة. سَنَد لن يستخدم هذه القدرة لهذا المشترك بعد الآن.';
    }

    public function canRevoke(): bool
    {
        return auth()->user()?->can($this->consent->capability->operatorPermission()->value) ?? false;
    }

    private function refreshConsent(): void
    {
        $this->consent->refresh();
        $this->expectedVersion = $this->consent->version;
    }

    public function render()
    {
        return view('livewire.dashboard.tools.consent-detail', [
            'consent' => $this->consent->loadMissing('subscriber:id,name'),
            'canRevoke' => $this->canRevoke(),
            'canAudit' => auth()->user()?->can(Permission::AuditView->value) ?? false,
            'reasons' => ToolConsentReason::cases(),
        ]);
    }
}

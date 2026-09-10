<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Enums\MessageDirection;
use App\Enums\ReminderStatus;
use App\Enums\ToolInvocationStatus;
use App\Enums\UsageDimension;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\ToolInvocation;
use App\Models\UsageEvent;
use App\Services\Launch\LaunchReadiness;
use App\Services\Launch\OperationalWarnings;
use App\Support\Rbac\Permission;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * The operator's first screen, rebuilt as an operational board.
 *
 * It used to be six lifetime `count()` calls — users, conversations, messages,
 * tasks, reminders, expenses. `Message::count()` only ever grows and tells you
 * nothing you can act on; "due reminders" did not distinguish pending from stuck.
 *
 * Every number here is therefore BOUNDED BY A WINDOW and chosen because someone
 * would do something differently if it changed. Cost is gated on
 * `usage.view_costs`, so Support never sees money. Each card is one aggregate
 * query, and a query-count test pins the total.
 *
 * Every read is wrapped: a dashboard that 500s because one optional table is
 * unreadable is worse than a dashboard with one dash in it.
 */
#[Title('نظرة عامة | سَنَد')]
#[Layout('components.layouts.dashboard')]
class Overview extends Component
{
    public function render(LaunchReadiness $readiness, OperationalWarnings $warnings)
    {
        $user = auth()->user();
        $since = CarbonImmutable::now()->subDay();

        $showCosts = $user?->can(Permission::UsageViewCosts->value) ?? false;
        $canSeeReadiness = $user?->can(Permission::LaunchReadinessView->value) ?? false;
        $canSeeWarnings = ($user?->can(Permission::RemindersDeliveryView->value) ?? false)
            || ($user?->can(Permission::ToolsInvocationsView->value) ?? false);

        return view('livewire.dashboard.overview', [
            'today' => $this->today($since, $showCosts),
            'showCosts' => $showCosts,
            'readiness' => $canSeeReadiness ? $this->readiness($readiness) : null,
            'warnings' => $canSeeWarnings
                ? $this->linkable($this->guard(static fn (): array => $warnings->all(), []))
                : null,
            'warningWindowDays' => OperationalWarnings::WINDOW_DAYS,
        ]);
    }

    /**
     * A route for a card, but ONLY when this account could actually open it.
     *
     * A link that can only ever produce a 403 is worse than no link: it wastes a
     * click and it tells an operator a page exists that they are not permitted to
     * know about. The same rule the sidebar follows applies to every link on this
     * board.
     */
    private function linkTo(string $route, Permission $permission, bool $legacy = false): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        // Mirrors EnsureLegacyAdminOrPermission for the routes that pre-date
        // RBAC, so a legacy admin keeps a link they can genuinely open.
        if ($legacy && $user->isAdmin()) {
            return $route;
        }

        return $user->can($permission->value) ? $route : null;
    }

    /**
     * Strip the route from any warning whose destination this account cannot
     * open. The count still shows — knowing something needs attention is not the
     * same as being allowed to inspect it.
     *
     * @param  list<array{key: string, label: string, count: int, hint: string, route: ?string, params: array<string, string>}>  $warnings
     * @return list<array{key: string, label: string, count: int, hint: string, route: ?string, params: array<string, string>}>
     */
    private function linkable(array $warnings): array
    {
        // route => [permission, is the route legacy-gated]
        $permissions = [
            'dashboard.tools.invocations' => [Permission::ToolsInvocationsView, false],
            'dashboard.reminders' => [Permission::RemindersView, true],
        ];

        return array_map(function (array $warning) use ($permissions): array {
            $route = $warning['route'];

            if ($route !== null && isset($permissions[$route])) {
                $warning['route'] = $this->linkTo($route, $permissions[$route][0], legacy: $permissions[$route][1]);
            }

            return $warning;
        }, $warnings);
    }

    /**
     * @return array<string, array{label: string, value: string, hint: string, route: ?string, params: array<string, string>}>
     */
    private function today(CarbonImmutable $since, bool $showCosts): array
    {
        $cards = [
            'messages_in' => [
                'label' => 'رسائل واردة (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => Message::query()
                    ->where('direction', MessageDirection::Inbound->value)
                    ->where('created_at', '>=', $since)->count(), '—'),
                'hint' => 'ما وصل من المشتركين.',
                'route' => null,
                'params' => [],
            ],
            'messages_out' => [
                'label' => 'رسائل صادرة (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => Message::query()
                    ->where('direction', MessageDirection::Outbound->value)
                    ->where('created_at', '>=', $since)->count(), '—'),
                'hint' => 'ما أرسله سَنَد.',
                'route' => null,
                'params' => [],
            ],
            'invocations' => [
                'label' => 'استدعاءات أدوات (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => ToolInvocation::query()
                    ->where('created_at', '>=', $since)->count(), '—'),
                'hint' => 'كل ما قرّره الخادم بعد اقتراح النموذج.',
                'route' => $this->linkTo('dashboard.tools.invocations', Permission::ToolsInvocationsView),
                'params' => [],
            ],
            'invocations_bad' => [
                'label' => 'منها مرفوض أو فاشل (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => ToolInvocation::query()
                    ->whereIn('status', [
                        ToolInvocationStatus::Refused->value,
                        ToolInvocationStatus::Failed->value,
                        ToolInvocationStatus::TimedOut->value,
                    ])
                    ->where('created_at', '>=', $since)->count(), '—'),
                'hint' => 'رفض أو فشل أو انتهاء مهلة.',
                'route' => $this->linkTo('dashboard.tools.invocations', Permission::ToolsInvocationsView),
                'params' => ['status' => ToolInvocationStatus::Refused->value],
            ],
            'reminders_sent' => [
                'label' => 'تذكيرات مُسلَّمة (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => Reminder::query()
                    ->where('status', ReminderStatus::Sent->value)
                    ->where('sent_at', '>=', $since)->count(), '—'),
                'hint' => 'وصلت فعلًا.',
                'route' => $this->linkTo('dashboard.reminders', Permission::RemindersView, legacy: true),
                'params' => ['status' => ReminderStatus::Sent->value],
            ],
            'reminders_failed' => [
                'label' => 'تذكيرات فاشلة (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => Reminder::query()
                    ->where('status', ReminderStatus::Failed->value)
                    ->where('updated_at', '>=', $since)->count(), '—'),
                'hint' => 'استقرّت فاشلة بسبب محدود.',
                'route' => $this->linkTo('dashboard.reminders', Permission::RemindersView, legacy: true),
                'params' => ['status' => ReminderStatus::Failed->value],
            ],
            'whatsapp_out' => [
                'label' => 'إرسال واتساب مُحتسَب (٢٤س)',
                'value' => (string) $this->guard(static fn (): int => UsageEvent::query()
                    ->where('type', UsageDimension::WhatsAppOutbound->value)
                    ->where('occurred_at', '>=', $since)->count(), '—'),
                'hint' => 'صف استخدام واحد لكل إرسال مقبول — ليس عدد التذكيرات.',
                'route' => null,
                'params' => [],
            ],
        ];

        if ($showCosts) {
            $cards['cost'] = [
                'label' => 'تكلفة المزوّد (٢٤س)',
                // PRICED rows only. An unpriced row's zero is not "free", so it
                // is counted separately by the Usage page and never summed here.
                'value' => $this->guard(static fn (): string => number_format(
                    (float) (UsageEvent::query()->priced()->where('occurred_at', '>=', $since)->sum('total_cost') ?? 0), 4, '.', ''
                ), '—'),
                'hint' => 'الصفوف المُسعَّرة فقط؛ صفر غير المُسعَّرة ليس «بلا تكلفة».',
                'route' => $this->linkTo('dashboard.usage', Permission::UsageView),
                'params' => [],
            ];
        }

        return $cards;
    }

    /**
     * @return array{blockers: int, required: int, ready: int}|null
     */
    private function readiness(LaunchReadiness $readiness): ?array
    {
        try {
            $statuses = $readiness->evaluate();
            $summary = $readiness->summary($statuses);

            return [
                'blockers' => $summary['blockers'],
                'required' => $summary['required'],
                'ready' => $summary['ready'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @param  T|string  $fallback
     * @return T|string
     */
    private function guard(callable $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return $fallback;
        }
    }
}

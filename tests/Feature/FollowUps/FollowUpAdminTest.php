<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\FollowUps\FollowUpOperationsQuery;
use App\Services\FollowUps\FollowUpService;
use App\Support\Rbac\Permission;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The admin surface: metadata, and not what the subscriber said.
 *
 * The question on a follow-up is the subscriber's own words about something
 * unfinished in their life. Every operational question this page exists to answer
 * is answerable without it, so the column is unreachable from here rather than
 * merely unrendered — and a content-level permission is PROPOSED for review rather
 * than created as a side effect of building a page.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fuConfigure();
});

it('never renders the subscriber\'s question text on the follow-ups page', function () {
    [$user, , $conversation] = fuSubscriber();
    $secret = 'نتيجة تحليل الدم من المستشفى';

    $followUp = fuCreate($user, $conversation, ['question' => $secret]);

    // The row really does hold it.
    expect(DB::table('follow_ups')->where('id', $followUp->id)->value('question'))->toBe($secret);

    $admin = userWithRole(Role::SuperAdmin);

    $this->actingAs($admin)->get(route('dashboard.follow_ups'))
        ->assertOk()
        ->assertDontSee($secret)
        // The metadata an operator actually needs IS there.
        ->assertSee('بيانات تشغيلية فقط', escape: false)
        ->assertSee('مفتوحة', escape: false);
});

it('cannot even select the question column', function () {
    [$user, , $conversation] = fuSubscriber();
    fuCreate($user, $conversation, ['question' => 'سؤال خاص']);

    // Not a policy someone must remember: the select list is the guarantee.
    expect(in_array('question', FollowUpOperationsQuery::SELECTS, true))->toBeFalse();

    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    FollowUpOperationsQuery::overview();
    FollowUpOperationsQuery::paginate([]);
    FollowUpOperationsQuery::build(['status' => 'open', 'subscriber_id' => (string) $user->id])->get();

    expect($sql)->not->toBeEmpty();

    foreach ($sql as $statement) {
        expect(str_contains($statement, 'question'))->toBeFalse($statement);
    }
});

it('requires the follow-ups permission, and that permission reveals no content', function () {
    [$user, , $conversation] = fuSubscriber();
    fuCreate($user, $conversation, ['question' => 'سؤال خاص جدا']);

    // A plain authenticated user with no dashboard permission is refused.
    $plain = User::factory()->create(['is_admin' => false]);
    $this->actingAs($plain)->get(route('dashboard.follow_ups'))->assertForbidden();

    // Support holds it, because "why did Sanad ask me twice?" is a support
    // question — and still sees no question text.
    $support = userWithRole(Role::Support);

    expect($support->can(Permission::FollowUpsView->value))->toBeTrue();

    $this->actingAs($support)->get(route('dashboard.follow_ups'))
        ->assertOk()
        ->assertDontSee('سؤال خاص جدا');

    // Finance has no business here at all.
    $finance = userWithRole(Role::Finance);

    expect($finance->can(Permission::FollowUpsView->value))->toBeFalse();
    $this->actingAs($finance)->get(route('dashboard.follow_ups'))->assertForbidden();
});

it('counts the platform\'s loops by state without reading any of them', function () {
    [$user, , $conversation] = fuSubscriber();

    $open = fuCreate($user, $conversation, ['question' => 'أ']);
    $awaiting = fuAwaiting($user, $conversation, 'ب');
    $cancelled = fuCreate($user, $conversation, ['question' => 'ج']);
    app(FollowUpService::class)->cancel($user, (int) $cancelled->getKey());

    $overview = FollowUpOperationsQuery::overview();

    expect($overview['live'])->toBe(2)
        ->and($overview['awaiting'])->toBe(1)
        ->and($overview['cancelled'])->toBe(1)
        ->and($overview['blocked'])->toBe(0)
        ->and($overview['subscribers'])->toBe(1);
});

it('shows the nav entry only to an account that holds the permission', function () {
    [$user, , $conversation] = fuSubscriber();
    fuCreate($user, $conversation);

    $this->actingAs(userWithRole(Role::SuperAdmin))->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('dashboard.follow_ups'), escape: false);

    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('dashboard.follow_ups'), escape: false);
});

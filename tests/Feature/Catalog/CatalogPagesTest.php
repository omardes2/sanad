<?php

declare(strict_types=1);

use App\Livewire\Dashboard\Ai\Models as ModelsPage;
use App\Livewire\Dashboard\Ai\Pricing as PricingPage;
use App\Livewire\Dashboard\Ai\Providers as ProvidersPage;
use App\Livewire\Dashboard\Ai\Routing as RoutingPage;
use App\Models\AiModel;
use App\Models\AuditLog;
use App\Models\ModelPrice;
use App\Models\User;
use App\Support\Audit\AuditActions;
use App\Support\Rbac\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

$pages = [
    'providers' => ['dashboard.ai.providers', ['super' => 200, 'ops' => 200, 'finance' => 200, 'support' => 403]],
    'models' => ['dashboard.ai.models', ['super' => 200, 'ops' => 200, 'finance' => 403, 'support' => 403]],
    'pricing' => ['dashboard.ai.pricing', ['super' => 200, 'ops' => 403, 'finance' => 200, 'support' => 403]],
    'routing' => ['dashboard.ai.routing', ['super' => 200, 'ops' => 200, 'finance' => 403, 'support' => 403]],
    'usage' => ['dashboard.usage', ['super' => 200, 'ops' => 200, 'finance' => 200, 'support' => 200]],
];

it('applies the role matrix to every C2 page; guests → login; no role / legacy is_admin → 403 (fail closed)', function (string $route, array $expected) {
    $this->get(route($route))->assertRedirect(route('login'));

    rbacSync();
    $this->actingAs(User::factory()->create(['is_admin' => false]))->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route($route))->assertForbidden();

    foreach (['super' => Role::SuperAdmin, 'ops' => Role::Operations, 'finance' => Role::Finance, 'support' => Role::Support] as $key => $role) {
        $this->actingAs(userWithRole($role))->get(route($route))->assertStatus($expected[$key]);
    }
})->with($pages);

it('shows each nav link only to accounts holding its permission', function () {
    $this->actingAs(userWithRole(Role::Support))->get(route('dashboard'))
        ->assertSee(route('dashboard.usage'))
        ->assertDontSee(route('dashboard.ai.providers'))
        ->assertDontSee(route('dashboard.ai.pricing'));

    $this->actingAs(userWithRole(Role::Finance))->get(route('dashboard'))
        ->assertSee(route('dashboard.ai.pricing'))
        ->assertSee(route('dashboard.ai.providers'))
        ->assertDontSee(route('dashboard.ai.models'))
        ->assertDontSee(route('dashboard.ai.routing'));
});

it('providers page: finance sees the table without an editor and cannot call the write action; operations edits with audit', function () {
    $fx = catalogFixture();

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(ProvidersPage::class)
        ->assertOk()
        ->assertSee('groq')
        ->assertDontSee('تعديل')
        ->call('edit', $fx['openai']->id)
        ->assertForbidden();

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ProvidersPage::class)
        ->call('edit', $fx['openai']->id)
        ->set('form.base_url', 'http://insecure.example.com')
        ->call('save')
        ->assertSee('https')
        ->set('form.base_url', 'https://proxy.example.com/v1')
        ->set('form.name', 'OpenAI (proxy)')
        ->call('save')
        ->assertSee('تم حفظ المزوّد');

    expect($fx['openai']->fresh()->base_url)->toBe('https://proxy.example.com/v1')
        ->and(AuditLog::where('action', AuditActions::AiProviderUpdated)->count())->toBe(1);
});

it('providers page: the key status is a yes/no only — never a value', function () {
    catalogFixture();

    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(ProvidersPage::class)
        ->assertSee('موجود')
        ->assertDontSee('test-groq-key')
        ->assertDontSee('test-openai-key');
});

it('models page: disabling the selected route asks for the typed confirmation, then applies it; last viable route is refused', function () {
    $fx = catalogFixture();

    $page = Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ModelsPage::class)
        ->call('edit', $fx['llama']->id)
        ->set('form.is_enabled', '0')
        ->call('save')
        ->assertSee('اكتب')
        ->assertSee('openai:gpt-4.1-mini');

    expect($fx['llama']->fresh()->is_enabled)->toBeTrue();

    $page->set('confirmation', 'openai:gpt-4.1-mini')
        ->call('save')
        ->assertSee('تم حفظ النموذج');

    expect($fx['llama']->fresh()->is_enabled)->toBeFalse();

    // Now openai's model is the last viable route: disabling it is refused.
    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ModelsPage::class)
        ->call('edit', $fx['mini']->id)
        ->set('form.is_enabled', '0')
        ->call('save')
        ->assertSee('بلا أي مرشّح صالح');

    expect($fx['mini']->fresh()->is_enabled)->toBeTrue();
});

it('models page: creates a model with aliases and a fallback, refuses a duplicate alias, and deletes only when unreferenced', function () {
    $fx = catalogFixture();

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ModelsPage::class)
        ->call('create')
        ->set('form.provider_id', (string) $fx['openai']->id)
        ->set('form.external_id', 'gpt-4.1')
        ->set('form.name', 'GPT-4.1')
        ->set('form.aliases', 'gpt-4.1-2025-04-14')
        ->set('form.fallback_model_id', (string) $fx['mini']->id)
        ->call('save')
        ->assertSee('أُنشئ النموذج');

    $created = AiModel::where('external_id', 'gpt-4.1')->firstOrFail();
    expect($created->aliases)->toBe(['gpt-4.1-2025-04-14'])->and($created->fallback_model_id)->toBe($fx['mini']->id)->and($created->is_enabled)->toBeFalse();

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ModelsPage::class)
        ->call('create')
        ->set('form.provider_id', (string) $fx['openai']->id)
        ->set('form.external_id', 'gpt-4.1-2025-04-14')
        ->set('form.name', 'dup')
        ->call('save')
        ->assertSee('مستخدم بالفعل');

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(ModelsPage::class)
        ->call('delete', $created->id)
        ->assertSee('حُذف النموذج');

    expect(AiModel::find($created->id))->toBeNull();
});

it('models page refuses finance even when the component is invoked directly', function () {
    catalogFixture();
    Livewire::actingAs(userWithRole(Role::Finance))->test(ModelsPage::class)->assertForbidden();
});

it('pricing page: finance previews then publishes through PriceBook with audit; a second publication closes the first', function () {
    $fx = catalogFixture();

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(PricingPage::class)
        ->assertSee('بلا سعر ساري')
        ->call('start', $fx['mini']->id)
        ->call('publish')
        ->assertSee('اعرض المعاينة أولًا')
        ->set('form.input', '0.40')
        ->set('form.output', '1.60')
        ->call('previewPublication')
        ->assertSee('معاينة قبل النشر')
        ->assertSee('1000 in + 300 out')
        ->call('publish')
        ->assertSee('نُشر السعر');

    $first = ModelPrice::where('model_id', $fx['mini']->id)->firstOrFail();

    expect($first->effective_until)->toBeNull()
        ->and((string) $first->input_per_million)->toBe('0.40000000')
        ->and($first->created_by)->not->toBeNull()
        ->and(AuditLog::where('action', AuditActions::AiPricePublished)->count())->toBe(1);

    Livewire::actingAs(userWithRole(Role::Finance))
        ->test(PricingPage::class)
        ->call('start', $fx['mini']->id)
        ->set('form.input', '0.50')
        ->set('form.output', '2.00')
        ->set('form.effective_from', now()->addMinute()->format('Y-m-d\TH:i'))
        ->call('previewPublication')
        ->assertSee('ستُغلق الفترة المفتوحة الحالية')
        ->call('publish')
        ->assertSee('نُشر السعر');

    expect($first->fresh()->effective_until)->not->toBeNull()
        ->and(ModelPrice::where('model_id', $fx['mini']->id)->whereNull('effective_until')->count())->toBe(1);
});

it('pricing page: operations cannot publish even by calling the actions directly', function () {
    $fx = catalogFixture();

    Livewire::actingAs(userWithRole(Role::Operations))->test(PricingPage::class)->assertForbidden();

    // A finance-visible page but a manage action invoked without the manage permission → 403.
    $viewer = userWithRole(Role::Finance);
    Spatie\Permission\Models\Role::findByName(Role::Finance->value)->revokePermissionTo('ai.pricing.manage');

    Livewire::actingAs($viewer->fresh())
        ->test(PricingPage::class)
        ->assertOk()
        ->assertDontSee('نشر سعر')
        ->call('start', $fx['mini']->id)
        ->assertForbidden();

    expect(ModelPrice::count())->toBe(0);
});

it('routing page shows the live evaluation with reasons and a what-if that writes nothing; estimates only for cost viewers', function () {
    $fx = catalogFixture();
    ModelPrice::factory()->for($fx['mini'], 'model')->create();
    config(['ai.providers.openai.api_key' => '']);

    Livewire::actingAs(userWithRole(Role::Operations))
        ->test(RoutingPage::class)
        ->assertOk()
        ->assertSee('selected: groq:llama-3.3-70b-versatile')
        ->assertSee('المزوّد بلا مفتاح/إعداد في البيئة')
        ->assertDontSee('تقدير/طلب')
        ->set('preferred', 'openai')
        ->assertSee('المحاكاة (ماذا لو)')
        ->set('operation', 'vision')
        ->assertSee('لا مسار صالح لهذه العملية');

    config(['ai.providers.openai.api_key' => 'k']);
    Livewire::actingAs(userWithRole(Role::SuperAdmin))
        ->test(RoutingPage::class)
        ->assertSee('تقدير/طلب')
        ->set('maxUnitCost', '0.000001')
        ->assertSee('التكلفة المقدّرة تتجاوز الحد');

    expect(AuditLog::where('action', 'like', 'ai.%')->count())->toBe(0);
});

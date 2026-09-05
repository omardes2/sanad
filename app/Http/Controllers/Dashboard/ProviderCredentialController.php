<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use App\Exceptions\Credentials\CredentialLifecycleException;
use App\Exceptions\Credentials\VaultUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\ProviderCredential;
use App\Services\Credentials\CredentialManager;
use App\Support\Rbac\Permission;
use App\Support\Security\SecretString;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * WRITE-ONLY credential entry (Phase C3): plain POST forms, never a Livewire
 * property, so the plaintext exists only in this request. Every action
 * re-checks `ai.credentials.manage` (the route carries it too) and the
 * lifecycle rules live in CredentialManager. Responses are redirects with a
 * flash message that never contains the secret.
 */
class ProviderCredentialController extends Controller
{
    public function store(Request $request, AiProvider $provider, CredentialManager $manager): RedirectResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'secret' => ['required', 'string', 'max:4096'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $credential = $manager->create($provider, new SecretString($validated['secret']), $validated['label'] ?? null);
        } catch (VaultUnavailableException|CredentialLifecycleException $e) {
            return $this->back($provider)->with('credential_error', $e->getMessage());
        }

        return $this->back($provider)->with('credential_status', "أُضيف المفتاح [{$credential->masked()}] قيد الانتظار. اختبره ثم فعّله.");
    }

    public function activate(Request $request, ProviderCredential $credential, CredentialManager $manager): RedirectResponse
    {
        $this->authorizeManage($request);
        $provider = $credential->provider;

        try {
            $manager->activate($credential);
        } catch (CredentialLifecycleException $e) {
            return $this->back($provider)->with('credential_error', $e->getMessage());
        }

        return $this->back($provider)->with('credential_status', "فُعّل المفتاح [{$credential->fresh()->masked()}]؛ المفتاح السابق (إن وُجد) أُلغي.");
    }

    public function revoke(Request $request, ProviderCredential $credential, CredentialManager $manager): RedirectResponse
    {
        $this->authorizeManage($request);
        $provider = $credential->provider;

        $validated = $request->validate(['confirmation' => ['required', 'string', 'max:191']]);
        $typed = trim($validated['confirmation']);

        // Two accepted confirmations: the provider key (normal case), or the
        // NEW route handle when the manager asked for a routing confirmation.
        $routingConfirmation = $typed === $provider->key ? null : $typed;

        if ($routingConfirmation !== null && $request->session()->get('credential_routing_expected') !== $routingConfirmation) {
            return $this->back($provider)->with('credential_error', "اكتب معرّف المزوّد «{$provider->key}» لتأكيد الإلغاء.");
        }

        try {
            $manager->revoke($credential, $routingConfirmation);
        } catch (RoutingChangeConfirmationRequired $e) {
            return $this->back($provider)
                ->with('credential_error', $e->getMessage())
                ->with('credential_routing_expected', $e->expectedConfirmation());
        } catch (LastViableRouteException|CredentialLifecycleException $e) {
            return $this->back($provider)->with('credential_error', $e->getMessage());
        }

        return $this->back($provider)->with('credential_status', "أُلغي المفتاح [{$credential->masked()}].");
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can(Permission::AiCredentialsManage->value) ?? false, 403);
    }

    private function back(AiProvider $provider): RedirectResponse
    {
        return redirect()->route('dashboard.ai.providers', ['open' => $provider->id]);
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Ai\Concerns;

use App\Exceptions\Ai\CatalogValidationException;
use App\Exceptions\Ai\FallbackCycleException;
use App\Exceptions\Ai\LastViableRouteException;
use App\Exceptions\Ai\RoutingChangeConfirmationRequired;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shared outcome handling for CatalogAdmin writes on the Providers / Models
 * pages: validation problems become inline messages, a refused last-viable-
 * route change is shown as a hard error, and a routing change turns into the
 * typed-confirmation prompt (the admin must type the NEW route handle and
 * submit again). Authorization failures are never softened — 403.
 */
trait HandlesCatalogWrites
{
    /** @var list<string> */
    public array $problems = [];

    public ?string $notice = null;

    /** @var array{before: ?string, after: ?string, expected: string}|null */
    public ?array $confirmPrompt = null;

    public string $confirmation = '';

    /**
     * @param  Closure(?string): string  $write  receives the typed confirmation (or null) and returns the success notice
     */
    protected function attemptCatalogWrite(Closure $write): bool
    {
        $this->problems = [];
        $this->notice = null;
        $typed = trim($this->confirmation) !== '' ? trim($this->confirmation) : null;

        try {
            $this->notice = $write($typed);
            $this->confirmPrompt = null;
            $this->confirmation = '';

            return true;
        } catch (RoutingChangeConfirmationRequired $e) {
            $this->confirmPrompt = ['before' => $e->before, 'after' => $e->after, 'expected' => $e->expectedConfirmation()];
            $this->confirmation = '';
            $this->problems = [$e->getMessage()];
        } catch (LastViableRouteException|FallbackCycleException $e) {
            $this->confirmPrompt = null;
            $this->problems = [$e->getMessage()];
        } catch (CatalogValidationException $e) {
            $this->confirmPrompt = null;
            $this->problems = $e->errors;
        } catch (AuthorizationException) {
            abort(403);
        }

        return false;
    }

    public function cancelConfirmation(): void
    {
        $this->confirmPrompt = null;
        $this->confirmation = '';
        $this->problems = [];
    }
}

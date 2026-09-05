<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Contracts\Security\HasSensitiveAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * EXPLICIT registry of sensitive fields — the primary source for redaction.
 *
 *  - keys(): field/context keys that are secrets wherever they appear
 *    (config keys, request input, audit context, log context);
 *  - model attributes: per model class, declared here or by the model itself
 *    through HasSensitiveAttributes.
 *
 * Later phases register their own entries (credentials, settings) from the
 * registries that own them; the defensive name/value patterns in
 * SecretRedactor remain a second layer, never the only one.
 */
final class SensitiveFieldRegistry
{
    /** @var array<string, true> */
    private array $keys = [];

    /** @var array<class-string<Model>, list<string>> */
    private array $modelAttributes = [];

    public function __construct()
    {
        $this->registerKeys([
            // Application / framework
            'password', 'password_confirmation', 'current_password', 'remember_token', 'app_key',
            // AI providers (config/ai.php)
            'api_key', 'openai_api_key', 'groq_api_key', 'gemini_api_key', 'ollama_api_key',
            // WhatsApp (config/whatsapp.php)
            'access_token', 'app_secret', 'verify_token',
            // Generic transport
            'authorization', 'bearer', 'secret', 'token', 'credentials', 'private_key',
        ]);
    }

    /**
     * @param  list<string>  $keys
     */
    public function registerKeys(array $keys): void
    {
        foreach ($keys as $key) {
            $this->keys[strtolower($key)] = true;
        }
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $attributes
     */
    public function registerModel(string $model, array $attributes): void
    {
        $this->modelAttributes[$model] = array_values(array_unique([...($this->modelAttributes[$model] ?? []), ...$attributes]));
    }

    public function isSensitiveKey(string $key): bool
    {
        return isset($this->keys[strtolower($key)]);
    }

    /**
     * @return list<string>
     */
    public function attributesFor(Model|string $model): array
    {
        $class = is_string($model) ? $model : $model::class;
        $declared = $this->modelAttributes[$class] ?? [];

        if ($model instanceof HasSensitiveAttributes) {
            $declared = [...$declared, ...$model->sensitiveAttributes()];
        } elseif (is_string($model) && is_subclass_of($model, HasSensitiveAttributes::class)) {
            $declared = [...$declared, ...(new $model)->sensitiveAttributes()];
        }

        return array_values(array_unique($declared));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->keys);
    }
}

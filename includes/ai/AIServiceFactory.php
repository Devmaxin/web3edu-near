<?php
require_once __DIR__ . '/AIProviderInterface.php';
require_once __DIR__ . '/providers/GroqProvider.php';
require_once __DIR__ . '/providers/OpenAIProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/providers/AnthropicProvider.php';
require_once __DIR__ . '/providers/OpenRouterProvider.php';
require_once __DIR__ . '/providers/OllamaProvider.php';

/**
 * AIServiceFactory
 * ============================================================
 * Reads the active provider + model + credentials from site_settings
 * (admin-controlled, no code changes needed) and builds the matching
 * AIProviderInterface implementation.
 *
 * Frontend code never sees this — only AIService talks to it.
 * ============================================================
 */
class AIServiceFactory
{
    /** Providers selectable in the admin UI. Add a new key here + a class to support a new vendor. */
    public const SUPPORTED_PROVIDERS = ['groq', 'openai', 'gemini', 'anthropic', 'openrouter', 'ollama'];

    public static function make(): AIProviderInterface
    {
        $provider = strtolower(trim(getSetting('ai_provider', 'groq')));
        $model    = trim(getSetting('ai_model', 'llama-3.3-70b-versatile'));
        $timeout  = (int)getSetting('ai_request_timeout', '30');
        $timeout  = $timeout > 0 ? $timeout : 30;

        return match ($provider) {
            'groq'       => new GroqProvider(getSetting('ai_api_key_groq', ''), $model, $timeout),
            'openai'     => new OpenAIProvider(getSetting('ai_api_key_openai', ''), $model, $timeout),
            'gemini'     => new GeminiProvider(getSetting('ai_api_key_gemini', ''), $model, $timeout),
            'anthropic'  => new AnthropicProvider(getSetting('ai_api_key_anthropic', ''), $model, $timeout),
            'openrouter' => new OpenRouterProvider(getSetting('ai_api_key_openrouter', ''), $model, $timeout),
            'ollama'     => new OllamaProvider(getSetting('ai_ollama_base_url', 'http://localhost:11434'), $model, $timeout),
            default      => throw new AIProviderException("Unknown AI provider configured: {$provider}"),
        };
    }
}

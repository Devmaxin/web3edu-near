<?php
/**
 * AIProviderInterface
 * ============================================================
 * Contract every AI provider adapter must implement.
 *
 * This is the seam that keeps Web3edu decoupled from any single
 * AI vendor. The frontend talks to AIService, AIService talks to
 * whichever class implements this interface — Groq today, maybe
 * OpenAI or Anthropic tomorrow — and nothing above this layer
 * ever needs to change.
 * ============================================================
 */

interface AIProviderInterface
{
    /**
     * Send a chat-style conversation to the provider and get a reply.
     *
     * @param array $messages Array of ['role' => 'system'|'user'|'assistant', 'content' => string]
     * @param array $options  Optional overrides: ['temperature' => float, 'max_tokens' => int, 'json_mode' => bool,
     *                        'json_schema' => array{name:string,strict:bool,schema:array}, 'reasoning_effort' => string]
     *                        json_schema (Groq Structured Outputs) is the more specific request; a provider that
     *                        doesn't support it should simply ignore the key rather than erroring. json_mode is the
     *                        schema-less fallback (valid JSON guaranteed, shape is not). reasoning_effort
     *                        ('low'|'medium'|'high') applies only to reasoning models that support it (e.g. Groq's
     *                        GPT-OSS 20B/120B); providers/models that don't support it should ignore the key.
     *
     * @return array{
     *   content: string,
     *   tokens_input: int,
     *   tokens_output: int,
     *   raw: mixed
     * }
     *
     * @throws AIProviderException on network/auth/parse failure.
     */
    public function chat(array $messages, array $options = []): array;

    /** Machine-readable provider name, e.g. "groq". */
    public function getName(): string;
}

/**
 * Thrown by any provider when a request fails. AIService catches this
 * and turns it into a safe, user-facing fallback message.
 */
class AIProviderException extends \RuntimeException {}

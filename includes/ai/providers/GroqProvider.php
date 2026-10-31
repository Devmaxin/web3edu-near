<?php
require_once __DIR__ . '/../AIProviderInterface.php';
require_once __DIR__ . '/../AIHttpClientTrait.php';

/**
 * GroqProvider
 * ============================================================
 * Talks to Groq's OpenAI-compatible /chat/completions endpoint.
 * This is the default/initial provider for Web3edu.
 * ============================================================
 */
class GroqProvider implements AIProviderInterface
{
    use AIHttpClientTrait;

    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $timeoutSeconds = 30
    ) {}

    public function getName(): string { return 'groq'; }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new AIProviderException('Groq API key is not configured.');
        }

        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.6,
            'max_tokens'  => $options['max_tokens']  ?? 600,
        ];
        // Two JSON-enforcement levels, most specific wins:
        //  - options['json_schema']: Groq Structured Outputs
        //    (response_format: json_schema). Currently only guaranteed
        //    (strict: true, constrained decoding) on openai/gpt-oss-20b and
        //    openai/gpt-oss-120b — see https://console.groq.com/docs/structured-outputs.
        //    Expects the raw ['name' => ..., 'strict' => bool, 'schema' => [...]] shape.
        //  - options['json_mode']: the older, schema-less
        //    response_format: json_object — valid JSON guaranteed, shape is not.
        // Callers building for a schema-capable model/provider should prefer
        // json_schema; json_mode remains for providers/models that only
        // support the basic JSON object mode.
        if (!empty($options['json_schema']) && is_array($options['json_schema'])) {
            $body['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => $options['json_schema'],
            ];
        } elseif (!empty($options['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }
        // reasoning_effort ('low'|'medium'|'high') only applies to
        // openai/gpt-oss-20b and openai/gpt-oss-120b; Groq defaults to
        // 'medium' when omitted. Only sent when a caller explicitly asks
        // for it, so this is a no-op for every other model/request.
        if (!empty($options['reasoning_effort'])) {
            $body['reasoning_effort'] = $options['reasoning_effort'];
        }

        $data = $this->postJson(self::API_URL, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ], $body, $this->timeoutSeconds);

        $content = $data['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            throw new AIProviderException('Groq response missing message content.');
        }

        return [
            'content'       => $content,
            'tokens_input'  => (int)($data['usage']['prompt_tokens']     ?? 0),
            'tokens_output' => (int)($data['usage']['completion_tokens'] ?? 0),
            'raw'           => $data,
        ];
    }
}

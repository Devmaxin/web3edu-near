<?php
require_once __DIR__ . '/../AIProviderInterface.php';
require_once __DIR__ . '/../AIHttpClientTrait.php';

/**
 * OpenAIProvider — talks to OpenAI's /chat/completions endpoint.
 */
class OpenAIProvider implements AIProviderInterface
{
    use AIHttpClientTrait;

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $timeoutSeconds = 30
    ) {}

    public function getName(): string { return 'openai'; }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new AIProviderException('OpenAI API key is not configured.');
        }

        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.6,
            'max_tokens'  => $options['max_tokens']  ?? 600,
        ];
        if (!empty($options['json_mode'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $data = $this->postJson(self::API_URL, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ], $body, $this->timeoutSeconds);

        $content = $data['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            throw new AIProviderException('OpenAI response missing message content.');
        }

        return [
            'content'       => $content,
            'tokens_input'  => (int)($data['usage']['prompt_tokens']     ?? 0),
            'tokens_output' => (int)($data['usage']['completion_tokens'] ?? 0),
            'raw'           => $data,
        ];
    }
}

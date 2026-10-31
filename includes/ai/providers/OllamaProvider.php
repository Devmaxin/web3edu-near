<?php
require_once __DIR__ . '/../AIProviderInterface.php';
require_once __DIR__ . '/../AIHttpClientTrait.php';

/**
 * OllamaProvider — talks to a local/self-hosted Ollama server.
 * No API key required; base URL is configurable.
 */
class OllamaProvider implements AIProviderInterface
{
    use AIHttpClientTrait;

    public function __construct(
        private string $baseUrl,
        private string $model,
        private int $timeoutSeconds = 30
    ) {}

    public function getName(): string { return 'ollama'; }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->baseUrl)) {
            throw new AIProviderException('Ollama base URL is not configured.');
        }

        $url  = rtrim($this->baseUrl, '/') . '/api/chat';
        $body = [
            'model'    => $this->model,
            'messages' => $messages,
            'stream'   => false,
            'options'  => [
                'temperature' => $options['temperature'] ?? 0.6,
            ],
        ];
        if (!empty($options['json_mode'])) {
            $body['format'] = 'json';
        }

        $data = $this->postJson($url, ['Content-Type: application/json'], $body, $this->timeoutSeconds);

        $content = $data['message']['content'] ?? null;
        if ($content === null) {
            throw new AIProviderException('Ollama response missing message content.');
        }

        return [
            'content'       => $content,
            'tokens_input'  => (int)($data['prompt_eval_count'] ?? 0),
            'tokens_output' => (int)($data['eval_count']        ?? 0),
            'raw'           => $data,
        ];
    }
}

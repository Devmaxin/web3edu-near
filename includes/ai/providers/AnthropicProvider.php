<?php
require_once __DIR__ . '/../AIProviderInterface.php';
require_once __DIR__ . '/../AIHttpClientTrait.php';

/**
 * AnthropicProvider — talks to the Claude Messages API.
 * Anthropic takes "system" as a top-level string, not a message role,
 * so this adapter splits it out from the shared message format.
 */
class AnthropicProvider implements AIProviderInterface
{
    use AIHttpClientTrait;

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $timeoutSeconds = 30
    ) {}

    public function getName(): string { return 'anthropic'; }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new AIProviderException('Anthropic API key is not configured.');
        }

        $system   = '';
        $turns    = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system .= ($system === '' ? '' : "\n") . $m['content'];
                continue;
            }
            $turns[] = ['role' => $m['role'], 'content' => $m['content']];
        }

        $body = [
            'model'       => $this->model,
            'messages'    => $turns,
            'max_tokens'  => $options['max_tokens']  ?? 600,
            'temperature' => $options['temperature'] ?? 0.6,
        ];
        if ($system !== '') {
            $body['system'] = $system;
        }

        $data = $this->postJson(self::API_URL, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . self::API_VERSION,
        ], $body, $this->timeoutSeconds);

        $content = $data['content'][0]['text'] ?? null;
        if ($content === null) {
            throw new AIProviderException('Anthropic response missing content.');
        }

        return [
            'content'       => $content,
            'tokens_input'  => (int)($data['usage']['input_tokens']  ?? 0),
            'tokens_output' => (int)($data['usage']['output_tokens'] ?? 0),
            'raw'           => $data,
        ];
    }
}

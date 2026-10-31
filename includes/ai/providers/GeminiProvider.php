<?php
require_once __DIR__ . '/../AIProviderInterface.php';
require_once __DIR__ . '/../AIHttpClientTrait.php';

/**
 * GeminiProvider — talks to Google's generateContent REST endpoint.
 * Gemini doesn't use OpenAI-style role arrays, so this adapter
 * translates the shared ['role','content'] format into Gemini's
 * 'contents' / 'systemInstruction' shape.
 */
class GeminiProvider implements AIProviderInterface
{
    use AIHttpClientTrait;

    public function __construct(
        private string $apiKey,
        private string $model,
        private int $timeoutSeconds = 30
    ) {}

    public function getName(): string { return 'gemini'; }

    public function chat(array $messages, array $options = []): array
    {
        if (empty($this->apiKey)) {
            throw new AIProviderException('Gemini API key is not configured.');
        }

        $systemParts = [];
        $contents    = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemParts[] = ['text' => $m['content']];
                continue;
            }
            $contents[] = [
                'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $m['content']]],
            ];
        }

        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'temperature'     => $options['temperature'] ?? 0.6,
                'maxOutputTokens' => $options['max_tokens']  ?? 600,
            ],
        ];
        if (!empty($systemParts)) {
            $body['systemInstruction'] = ['parts' => $systemParts];
        }
        if (!empty($options['json_mode'])) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($this->model) . ':generateContent?key=' . urlencode($this->apiKey);

        $data = $this->postJson($url, ['Content-Type: application/json'], $body, $this->timeoutSeconds);

        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($content === null) {
            throw new AIProviderException('Gemini response missing content.');
        }

        return [
            'content'       => $content,
            'tokens_input'  => (int)($data['usageMetadata']['promptTokenCount']     ?? 0),
            'tokens_output' => (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            'raw'           => $data,
        ];
    }
}

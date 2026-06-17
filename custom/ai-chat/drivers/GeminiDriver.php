<?php

class GeminiDriver implements DriverInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public static function driverKey(): string { return 'gemini'; }
    public static function label(): string     { return 'Google Gemini'; }

    public static function envVars(): array
    {
        return [
            'GEMINI_API_KEY' => '(必填) Google AI API 金鑰',
            'GEMINI_MODEL'   => '(選填) 模型名稱，預設 gemini-2.0-flash',
        ];
    }

    public static function make(): static
    {
        $key = env('GEMINI_API_KEY');
        if (!$key) throw new \RuntimeException('GEMINI_API_KEY is not set');

        return new static(
            apiKey: $key,
            model:  env('GEMINI_MODEL', 'gemini-2.0-flash'),
        );
    }

    public function endpoint(): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:streamGenerateContent?alt=sse&key={$this->apiKey}";
    }

    public function headers(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function body(string $query, string $systemPrompt): array
    {
        $body = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $query]]]],
        ];
        if (!empty($systemPrompt)) {
            $body['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        return $body;
    }

    public function parseLine(string $raw): array
    {
        if (trim($raw) === '') return ['done' => true];
        $data = json_decode($raw, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        return $text !== null ? ['text' => $text] : [];
    }
}

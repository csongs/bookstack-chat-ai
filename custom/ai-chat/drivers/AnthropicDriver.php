<?php

class AnthropicDriver implements DriverInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public static function driverKey(): string { return 'anthropic'; }
    public static function label(): string     { return 'Anthropic'; }

    public static function envVars(): array
    {
        return [
            'ANTHROPIC_API_KEY' => '(必填) API 金鑰',
            'ANTHROPIC_MODEL'   => '(選填) 模型名稱，預設 claude-sonnet-4-6',
        ];
    }

    public static function make(): static
    {
        $key = env('ANTHROPIC_API_KEY');
        if (!$key) throw new \RuntimeException('ANTHROPIC_API_KEY is not set');

        return new static(
            apiKey: $key,
            model:  env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        );
    }

    public function endpoint(): string
    {
        return 'https://api.anthropic.com/v1/messages';
    }

    public function headers(): array
    {
        return [
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type'      => 'application/json',
        ];
    }

    public function body(string $query, string $systemPrompt): array
    {
        return [
            'model'      => $this->model,
            'max_tokens' => 1024,
            'system'     => $systemPrompt,
            'messages'   => [['role' => 'user', 'content' => $query]],
            'stream'     => true,
        ];
    }

    public function parseLine(string $raw): array
    {
        if (str_contains($raw, 'message_stop')) return ['done' => true];
        $data = json_decode($raw, true);
        $text = $data['delta']['text'] ?? null;
        return $text !== null ? ['text' => $text] : [];
    }
}

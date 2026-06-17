<?php

class OpenAiDriver implements DriverInterface
{
    use ParsesOpenAiStream;

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $endpointUrl,
    ) {}

    public static function driverKey(): string { return 'openai'; }
    public static function label(): string     { return 'OpenAI'; }

    public static function envVars(): array
    {
        return [
            'OPENAI_API_KEY' => '(必填) API 金鑰',
            'OPENAI_MODEL'   => '(選填) 模型名稱，預設 gpt-4o',
            'OPENAI_ENDPOINT'=> '(選填) 自訂 endpoint，預設官方 URL',
        ];
    }

    public static function make(): static
    {
        $key = env('OPENAI_API_KEY');
        if (!$key) throw new \RuntimeException('OPENAI_API_KEY is not set');

        return new static(
            apiKey:      $key,
            model:       env('OPENAI_MODEL', 'gpt-4o'),
            endpointUrl: env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        );
    }

    public function endpoint(): string { return $this->endpointUrl; }

    public function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
        ];
    }

    public function body(string $query, string $systemPrompt): array
    {
        return [
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $query],
            ],
            'stream' => true,
        ];
    }
}

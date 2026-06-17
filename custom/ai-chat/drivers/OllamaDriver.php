<?php

class OllamaDriver implements DriverInterface
{
    public function __construct(
        private string $model,
        private string $endpointUrl,
    ) {}

    public static function driverKey(): string { return 'ollama'; }
    public static function label(): string     { return 'Ollama（本地）'; }

    public static function envVars(): array
    {
        return [
            'OLLAMA_MODEL'    => '(選填) 模型名稱，預設 llama3',
            'OLLAMA_ENDPOINT' => '(選填) API URL，預設 http://localhost:11434/api/chat',
        ];
    }

    public static function make(): static
    {
        return new static(
            model:       env('OLLAMA_MODEL', 'llama3'),
            endpointUrl: env('OLLAMA_ENDPOINT', 'http://localhost:11434/api/chat'),
        );
    }

    public function endpoint(): string { return $this->endpointUrl; }

    public function headers(): array
    {
        return ['Content-Type' => 'application/json'];
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

    public function parseLine(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!$data) return [];
        if ($data['done'] ?? false) return ['done' => true];
        $text = $data['message']['content'] ?? null;
        return $text !== null ? ['text' => $text] : [];
    }
}

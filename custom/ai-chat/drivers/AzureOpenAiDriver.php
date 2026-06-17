<?php

class AzureOpenAiDriver implements DriverInterface
{
    use ParsesOpenAiStream;

    public function __construct(
        private string $apiKey,
        private string $endpointUrl,
    ) {}

    public static function driverKey(): string { return 'azure'; }
    public static function label(): string     { return 'Azure OpenAI'; }

    public static function envVars(): array
    {
        return [
            'AZURE_OPENAI_ENDPOINT' => '(必填) 完整 deployment URL（含 api-version）',
            'AZURE_OPENAI_API_KEY'  => '(必填) Azure API 金鑰',
        ];
    }

    public static function make(): static
    {
        $endpoint = env('AZURE_OPENAI_ENDPOINT');
        $key      = env('AZURE_OPENAI_API_KEY');

        if (!$endpoint) throw new \RuntimeException('AZURE_OPENAI_ENDPOINT is not set');
        if (!$key)      throw new \RuntimeException('AZURE_OPENAI_API_KEY is not set');

        return new static(apiKey: $key, endpointUrl: $endpoint);
    }

    public function endpoint(): string { return $this->endpointUrl; }

    public function headers(): array
    {
        return [
            'api-key'      => $this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    public function body(string $query, string $systemPrompt): array
    {
        // deployment 由 endpoint URL 決定，body 不帶 model
        return [
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $query],
            ],
            'stream' => true,
        ];
    }
}

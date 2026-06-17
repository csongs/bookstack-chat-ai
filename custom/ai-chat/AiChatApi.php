<?php

use Illuminate\Support\Facades\Http;

class AiChatApi
{
    public static function discover(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        foreach (get_declared_classes() as $class) {
            if (in_array('DriverInterface', class_implements($class) ?: [])) {
                $cache[$class::driverKey()] = $class;
            }
        }
        return $cache;
    }

    public static function driverList(): array
    {
        return array_map(fn($class) => [
            'label'   => $class::label(),
            'envVars' => $class::envVars(),
        ], self::discover());
    }

    // ── Private helpers ────────────────────────────────────────

    private function makeDriver(): DriverInterface
    {
        $provider = AiChatSettings::get('ai-chat-provider', 'openai');
        $drivers  = self::discover();
        if (!isset($drivers[$provider])) {
            throw new \InvalidArgumentException("Unknown AI provider: {$provider}");
        }
        return $drivers[$provider]::make();
    }

    /**
     * Core HTTP stream — yields raw text chunks from the AI driver.
     * Shared by both callJson() and streamWithContext().
     */
    private function streamDriver(string $query, string $systemPrompt): \Generator
    {
        $driver   = $this->makeDriver();
        $response = Http::withHeaders($driver->headers())
            ->timeout(60)
            ->withOptions(['stream' => true])
            ->post($driver->endpoint(), $driver->body($query, $systemPrompt));

        $body   = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(1024);
            $lines   = explode("\n", $buffer);
            $buffer  = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data:')) continue;

                $result = $driver->parseLine(trim(substr($line, 5)));
                if ($result['done'] ?? false) return;
                if (isset($result['text']))   yield $result['text'];
            }
        }
    }

    /**
     * Non-streaming AI call: accumulates full text and returns it.
     * Reuses streaming drivers — no interface changes needed.
     */
    private function callJson(string $query, string $systemPrompt): string
    {
        $result = '';
        foreach ($this->streamDriver($query, $systemPrompt) as $chunk) {
            $result .= $chunk;
        }
        return trim($result);
    }

    // ── Public API ─────────────────────────────────────────────

    /**
     * Ask the AI to extract 3-5 FULLTEXT search keywords from the user's question.
     * Returns an array of keyword strings.
     */
    public function generateKeywords(string $query): array
    {
        $system = <<<PROMPT
你是搜尋關鍵字產生器。
根據使用者問題，產生 3-5 個適合 MySQL FULLTEXT 搜尋的關鍵字。
只回傳 JSON 陣列，例如：["防火牆", "網路安全", "VPN"]
不要解釋，不要其他文字。
PROMPT;

        $raw  = $this->callJson($query, $system);
        // Strip markdown code fences if the model wraps in ```json ... ```
        $raw  = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw  = preg_replace('/\s*```$/', '', trim($raw));
        $data = json_decode($raw, true);

        if (!is_array($data)) return [];

        return array_values(array_filter(array_map('strval', $data)));
    }

    public function stream(string $query): \Generator
    {
        // placeholder — will be replaced in Task 6
        $systemPrompt = AiChatSettings::get('ai-chat-system-prompt', '');
        yield from $this->streamDriver($query, $systemPrompt);
    }
}

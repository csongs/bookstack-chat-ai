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

    /**
     * Stream an AI answer using retrieved pages as context.
     * Yields: ['sources' => [...]] first, then ['text' => '...'] chunks.
     *
     * @param object[] $pages  Results from BookStackSearcher::search()
     */
    private function streamWithContext(string $query, array $pages): \Generator
    {
        // Build sources payload for the frontend
        $appUrl  = rtrim(env('APP_URL', ''), '/');
        $sources = array_map(fn($p) => [
            'name' => $p->name,
            'book' => $p->book_name,
            'url'  => "{$appUrl}/books/{$p->book_slug}/page/{$p->slug}",
        ], $pages);

        yield ['sources' => $sources];

        // Build context block — each page truncated to ~1500 chars
        $contextBlocks = array_map(function ($p) {
            $excerpt = mb_substr(strip_tags($p->text ?? ''), 0, 1500);
            return "--- 頁面：{$p->name}（書：{$p->book_name}）---\n{$excerpt}";
        }, $pages);

        $contextText = implode("\n\n", $contextBlocks);

        $basePrompt   = AiChatSettings::get('ai-chat-system-prompt', '');
        $systemPrompt = <<<PROMPT
{$basePrompt}

你是公司內部知識庫助理。請根據以下文件回答問題。
若文件中找不到答案，請明確說明。不要憑空捏造文件中沒有的資訊。

[知識庫文件]
{$contextText}
PROMPT;

        foreach ($this->streamDriver($query, $systemPrompt) as $text) {
            yield ['text' => $text];
        }
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

    /**
     * Ask the AI whether the retrieved pages are relevant to the user's question.
     *
     * @param object[] $pages  Each has ->name and ->book_name properties
     */
    public function isRelevant(array $pages, string $query): bool
    {
        if (empty($pages)) return false;

        $titles = implode("\n", array_map(
            fn($p) => "- {$p->name}（書：{$p->book_name}）",
            $pages
        ));

        $system = <<<PROMPT
以下是從知識庫搜到的頁面標題，判斷是否與使用者問題相關。
只回傳 JSON：{"relevant": true} 或 {"relevant": false}
不要解釋，不要其他文字。
PROMPT;

        $user = "使用者問題：{$query}\n\n搜到的頁面：\n{$titles}";

        $raw  = $this->callJson($user, $system);
        $raw  = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw  = preg_replace('/\s*```$/', '', trim($raw));
        $data = json_decode($raw, true);

        return (bool) ($data['relevant'] ?? false);
    }

    public function stream(string $query): \Generator
    {
        $searcher = new BookStackSearcher();

        for ($round = 0; $round < 2; $round++) {
            $keywords = $this->generateKeywords($query);
            $pages    = $searcher->search($keywords);

            if (!empty($pages) && $this->isRelevant($pages, $query)) {
                yield from $this->streamWithContext($query, $pages);
                return;
            }
        }

        // No relevant documents found after 2 rounds
        yield ['text' => '很抱歉，在知識庫中找不到與您問題相關的文件。請嘗試換個方式提問，或直接聯繫相關人員。'];
    }
}

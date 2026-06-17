# RAG SQL Search Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add RAG (Retrieval-Augmented Generation) to the BookStack AI Chat: AI generates search keywords → MySQL FULLTEXT search → AI validates relevance (max 2 rounds) → streams answer with source links, or reports no results.

**Architecture:** `AiChatApi::stream()` becomes a multi-step orchestrator: two non-streaming AI calls (keyword gen + relevance check) use the existing driver streaming infrastructure under the hood, then a final streaming call injects retrieved page content as context. Sources are delivered as a special SSE event before the text stream starts.

**Tech Stack:** PHP (BookStack theme plugin), MySQL 8 FULLTEXT index, Laravel `Http` facade, vanilla JS EventSource.

---

## Pre-requisite: Add FULLTEXT Index

Run this SQL once against the BookStack database (MySQL 8):

```sql
ALTER TABLE pages ADD FULLTEXT INDEX ft_pages_name_text (name, text);
```

Via Docker:
```bash
docker compose exec db mysql -u bookstack -pbookstack bookstack \
  -e "ALTER TABLE pages ADD FULLTEXT INDEX ft_pages_name_text (name, text);"
```

If the index already exists you'll get a "Duplicate key name" warning — safe to ignore.

---

## Task 1: Extract `streamDriver()` helper + add `callJson()`

**Files:**
- Modify: `custom/ai-chat/AiChatApi.php`

**What to do:**

Extract the HTTP streaming logic from `stream()` into a private `streamDriver(string $query, string $systemPrompt): \Generator`, then add `callJson()` which accumulates streamed chunks into a single string (reuses all drivers with zero interface changes).

Replace the entire `AiChatApi.php` with:

```php
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

    private function makeDriver(): object
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
    private function callJson(string $systemPrompt, string $userMessage): string
    {
        $result = '';
        foreach ($this->streamDriver($userMessage, $systemPrompt) as $chunk) {
            $result .= $chunk;
        }
        return trim($result);
    }

    // ── Public API ─────────────────────────────────────────────

    public function stream(string $query): \Generator
    {
        // placeholder — will be replaced in Task 6
        $systemPrompt = AiChatSettings::get('ai-chat-system-prompt', '');
        yield from $this->streamDriver($query, $systemPrompt);
    }
}
```

**Verify:** Visit `/ai-chat`, ask a question — chat still works exactly as before.

**Commit:**
```bash
git add custom/ai-chat/AiChatApi.php
git commit -m "refactor: extract streamDriver helper, add callJson to AiChatApi"
```

---

## Task 2: Add `generateKeywords()`

**Files:**
- Modify: `custom/ai-chat/AiChatApi.php`

**What to do:**

Add this method to `AiChatApi` (inside the class, before `stream()`):

```php
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

    $raw  = $this->callJson($system, $query);
    // Strip markdown code fences if the model wraps in ```json ... ```
    $raw  = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw  = preg_replace('/\s*```$/', '', trim($raw));
    $data = json_decode($raw, true);

    if (!is_array($data)) return [];

    return array_values(array_filter(array_map('strval', $data)));
}
```

**Verify:** No UI change yet; logic will be exercised in Task 6.

**Commit:**
```bash
git add custom/ai-chat/AiChatApi.php
git commit -m "feat: add generateKeywords to AiChatApi"
```

---

## Task 3: Add `isRelevant()`

**Files:**
- Modify: `custom/ai-chat/AiChatApi.php`

**What to do:**

Add this method to `AiChatApi`:

```php
/**
 * Ask the AI whether the retrieved pages are relevant to the user's question.
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

    $raw  = $this->callJson($system, $user);
    $raw  = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw  = preg_replace('/\s*```$/', '', trim($raw));
    $data = json_decode($raw, true);

    return (bool) ($data['relevant'] ?? false);
}
```

**Commit:**
```bash
git add custom/ai-chat/AiChatApi.php
git commit -m "feat: add isRelevant to AiChatApi"
```

---

## Task 4: Create `BookStackSearcher`

**Files:**
- Create: `custom/ai-chat/BookStackSearcher.php`
- Modify: `custom/ai-chat/include.php`

**Step 1: Create the file**

```php
<?php

use Illuminate\Support\Facades\DB;

class BookStackSearcher
{
    /**
     * Search BookStack pages using MySQL FULLTEXT index.
     * Requires: ALTER TABLE pages ADD FULLTEXT INDEX ft_pages_name_text (name, text);
     *
     * @return object[]  Each object has: id, name, slug, text, book_name, book_slug
     */
    public function search(array $keywords, int $limit = 5): array
    {
        if (empty($keywords)) return [];

        $queryStr = implode(' ', array_map(
            fn($k) => '+' . preg_replace('/[^\p{L}\p{N}_\-]/u', '', $k),
            $keywords
        ));

        if (empty(trim($queryStr, '+'))) return [];

        return DB::table('pages as p')
            ->join('books as b', 'b.id', '=', 'p.book_id')
            ->select([
                'p.id',
                'p.name',
                'p.slug',
                'p.text',
                'b.name as book_name',
                'b.slug as book_slug',
                DB::raw('MATCH(p.name, p.text) AGAINST(? IN BOOLEAN MODE) as score'),
            ])
            ->whereRaw(
                'MATCH(p.name, p.text) AGAINST(? IN BOOLEAN MODE)',
                [$queryStr, $queryStr]
            )
            ->where('p.draft', false)
            ->orderByDesc('score')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
```

Note: The `+` prefix in BOOLEAN MODE means each keyword is required. If too strict, remove the `+` prefix mapping.

**Step 2: Register in `include.php`**

After `include_once 'AiChatApi.php';`, add:

```php
include_once 'BookStackSearcher.php';
```

**Commit:**
```bash
git add custom/ai-chat/BookStackSearcher.php custom/ai-chat/include.php
git commit -m "feat: add BookStackSearcher with MySQL FULLTEXT search"
```

---

## Task 5: Add `streamWithContext()` to `AiChatApi`

**Files:**
- Modify: `custom/ai-chat/AiChatApi.php`

**What to do:**

Add this method to `AiChatApi`. It first yields a `sources` payload (for the JS to render chips), then streams the AI answer with page content injected as context.

```php
/**
 * Stream an AI answer using retrieved pages as context.
 * Yields: ['sources' => [...]] first, then ['text' => '...'] chunks.
 *
 * @param object[] $pages  Results from BookStackSearcher::search()
 */
private function streamWithContext(string $query, array $pages): \Generator
{
    // Build sources payload for the frontend
    $appUrl = rtrim(env('APP_URL', ''), '/');
    $sources = array_map(fn($p) => [
        'name'     => $p->name,
        'book'     => $p->book_name,
        'url'      => "{$appUrl}/books/{$p->book_slug}/page/{$p->slug}",
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
```

**Commit:**
```bash
git add custom/ai-chat/AiChatApi.php
git commit -m "feat: add streamWithContext to AiChatApi"
```

---

## Task 6: Replace `stream()` with RAG loop + update controller

**Files:**
- Modify: `custom/ai-chat/AiChatApi.php`
- Modify: `custom/ai-chat/AiChatController.php`

**Step 1: Replace `stream()` in `AiChatApi`**

Replace the placeholder `stream()` method added in Task 1 with:

```php
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
```

**Step 2: Update `AiChatController::ask()`**

`stream()` now yields `['text' => ...]` and `['sources' => ...]` arrays — pass them through directly instead of wrapping:

```php
public function ask()
{
    $this->authorizeChat();

    $query = trim(request()->get('query', ''));
    if (!$query) abort(400);

    $api = new AiChatApi();

    return response()->eventStream(function () use ($api, $query) {
        foreach ($api->stream($query) as $chunk) {
            yield $chunk;
        }
    });
}
```

**Commit:**
```bash
git add custom/ai-chat/AiChatApi.php custom/ai-chat/AiChatController.php
git commit -m "feat: implement RAG loop in AiChatApi::stream, update controller"
```

---

## Task 7: Update frontend JS to handle sources

**Files:**
- Modify: `custom/ai-chat/chat.blade.php`

**What to do:**

The JS `send()` method currently handles `data.text` only. Add handling for `data.sources` to render source chips in the bot bubble.

**Step 1: Add `renderSources()` method to `AiChatManager`**

Add after the `escHtml()` method:

```js
renderSources(botBubble, sources) {
    if (!sources || !sources.length) return;

    const wrap = document.createElement('div');
    wrap.className = 'ai-sources';
    wrap.innerHTML = `
        <div class="ai-sources-header">
            <strong>參考來源</strong>
            <button class="ai-sources-toggle js-sources-toggle">展開</button>
        </div>
        <div class="response-sources collapsed">
            <div class="entity-list">
                ${sources.map(s => `
                    <a href="${this.escHtml(s.url)}" target="_blank" rel="noopener"
                       class="entity-list-item page">
                        <span class="icon">📄</span>
                        <div>
                            <h4>${this.escHtml(s.book)}</h4>
                            <span>${this.escHtml(s.name)}</span>
                        </div>
                    </a>
                `).join('')}
            </div>
        </div>
    `;
    botBubble.appendChild(wrap);
}
```

**Step 2: Update `send()` to handle `data.sources`**

In the `update` event listener, add the `data.sources` branch:

```js
es.addEventListener('update', e => {
    const raw = e.data || '';

    if (raw === '</stream>') {
        es.close();
        if (typingEl.parentNode) typingEl.replaceWith(botBubble);
        this.fieldset.disabled = false;
        this.input.placeholder = 'Ask further questions...';
        this.input.focus();
        return;
    }

    let data;
    try { data = JSON.parse(raw); } catch { return; }

    if (data.sources) {
        // Sources arrive before text — ensure bubble is in DOM
        if (typingEl.parentNode) typingEl.replaceWith(botBubble);
        this.renderSources(botBubble.querySelector('.ai-msg__bubble'), data.sources);
        return;
    }

    if (data.text) {
        if (typingEl.parentNode) typingEl.replaceWith(botBubble);
        botBubble.querySelector('.ai-msg__bubble').append(data.text);
        window.scrollTo(0, document.body.scrollHeight);
    }
});
```

Note: `append(data.text)` (not `textContent +=`) preserves existing DOM children (the sources block).

**Commit:**
```bash
git add custom/ai-chat/chat.blade.php
git commit -m "feat: render RAG source chips in chat frontend"
```

---

## Task 8: End-to-end verification

1. Ensure FULLTEXT index exists (see Pre-requisite above)
2. Go to `/ai-chat`
3. Ask a question that matches content in BookStack:
   - Expected: typing indicator → source chips appear → answer streams in
4. Ask a question with no matching content:
   - Expected: answer says "找不到相關文件"
5. Check BookStack pages have content in the `text` column (plain-text field); if only `html` is populated, adjust `BookStackSearcher` to use `html` or add `html` to the FULLTEXT index

**If sources don't appear:** Open DevTools → Network → EventStream tab → check if `sources` event arrives with correct JSON.

**If FULLTEXT search returns 0 results for known content:** The `+keyword` boolean mode may be too strict. Remove the `+` prefix in `BookStackSearcher::search()` to use simple `keyword` matching instead.

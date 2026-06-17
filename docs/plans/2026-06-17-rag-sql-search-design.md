# RAG SQL Search Design
Date: 2026-06-17

## 目標

在現有 AI Chat 基礎上實作 RAG（Retrieval-Augmented Generation）：
使用者提問 → AI 生成關鍵字 → MySQL FULLTEXT 搜尋 BookStack 文件 → AI 帶 context 回答 → 顯示來源連結。

---

## 整體流程

```
使用者問題
    │
    ▼
[Step 1] AI 生成關鍵字
    │  從問題萃取 3-5 個搜尋關鍵字（non-streaming JSON）
    │
    ▼
[Step 2] MySQL FULLTEXT 搜尋
    │  搜 pages.name + pages.text，取前 5 篇最相關
    │
    ▼
[Step 3] AI 判斷相關性（non-streaming JSON）
    ├─ 相關 → 進入 Step 4
    └─ 不相關 → 換關鍵字重搜（最多 2 輪）
                    └─ 仍無結果 → 回覆「找不到相關文件」
    │
    ▼
[Step 4] AI 生成回答（streaming）
    │  將找到的頁面 text 注入 system prompt
    │
    ▼
回覆串流 + 顯示來源連結（書名 / 頁面標題 + URL）
```

---

## 元件設計

### 新增：`BookStackSearcher`

```php
class BookStackSearcher
{
    public function search(array $keywords, int $limit = 5): array
    {
        $query = implode(' ', $keywords);

        return DB::table('pages as p')
            ->join('books as b', 'b.id', '=', 'p.book_id')
            ->select([
                'p.id', 'p.name', 'p.slug', 'p.text',
                'b.name as book_name', 'b.slug as book_slug',
                DB::raw('MATCH(p.name, p.text) AGAINST(? IN BOOLEAN MODE) as score'),
            ])
            ->whereRaw('MATCH(p.name, p.text) AGAINST(? IN BOOLEAN MODE)', [$query, $query])
            ->where('p.draft', false)
            ->orderByDesc('score')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
```

> 需確認 `pages.name` 與 `pages.text` 有 FULLTEXT index，否則需 migration 新增。

---

### 修改：`AiChatApi`

新增兩個 non-streaming 方法：

- `generateKeywords(string $query): array` — 叫 AI 從問題萃取 3-5 個關鍵字，回傳 JSON 陣列
- `isRelevant(array $pages, string $query): bool` — 叫 AI 判斷搜到的頁面是否相關，回傳 JSON boolean

修改後的 `stream()` 主流程：

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

    yield '很抱歉，在知識庫中找不到與您問題相關的文件。';
}
```

---

## Prompt 設計

### Keyword Generation Prompt

```
你是搜尋關鍵字產生器。
根據使用者問題，產生 3-5 個適合 MySQL FULLTEXT 搜尋的關鍵字。
只回傳 JSON 陣列，例如：["防火牆", "網路安全", "VPN"]
不要解釋，不要其他文字。
```

### Relevance Check Prompt

```
以下是從知識庫搜到的頁面摘要，判斷是否與使用者問題相關。
只回傳 JSON：{"relevant": true} 或 {"relevant": false}

使用者問題：{query}
搜到的頁面標題：{titles}
```

### 最終回答 System Prompt（注入 context）

```
你是公司內部知識庫助理。請根據以下文件回答問題。
若文件中找不到答案，請明確說明。不要憑空捏造文件中沒有的資訊。

[文件內容]
--- 頁面：{title} ---
{text 前 1500 字}
...（最多 5 篇）
```

---

## 前端顯示來源

擴充現有 `response-sources.blade.php`，在回答下方列出：

```
參考來源：
- [書名 / 頁面標題](https://bookstack.example.com/books/{book_slug}/page/{page_slug})
```

來源資料透過 SSE 事件傳遞（在串流結束前 yield 一個 `sources` event）。

---

## 限制與注意事項

- 每頁 text 最多取前 1500 字，避免超過 context window
- FULLTEXT index 必須存在，否則查詢會 fallback 到全表掃描
- `generateKeywords` 和 `isRelevant` 使用 non-streaming 呼叫，需考慮 timeout（建議 15s）
- 最多 2 輪搜尋，避免延遲過長

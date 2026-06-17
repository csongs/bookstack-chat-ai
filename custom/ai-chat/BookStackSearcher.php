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
            ->select(['p.id', 'p.name', 'p.slug', 'p.text', 'b.name as book_name', 'b.slug as book_slug'])
            ->selectRaw('MATCH(p.name, p.text) AGAINST(? IN BOOLEAN MODE) as score', [$queryStr])
            ->whereRaw('MATCH(p.name, p.text) AGAINST(? IN BOOLEAN MODE)', [$queryStr])
            ->where('p.draft', false)
            ->orderByDesc('score')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}

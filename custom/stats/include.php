<?php

use BookStack\Facades\Theme;
use BookStack\Theming\ThemeEvents;
use BookStack\Theming\ThemeViews;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

Theme::listen(ThemeEvents::THEME_REGISTER_VIEWS, function (ThemeViews $themeViews) {
    view()->getFinder()->prependLocation(theme_path('views'));
});

Theme::listen(ThemeEvents::ROUTES_REGISTER_WEB_AUTH, function (\Illuminate\Routing\Router $router) {

    // --- API: get books for a given shelf (cascading dropdown) ---
    $router->get('/stats-dashboard/books-by-shelf', function () {
        $user = user();
        if (!$user || !$user->can('settings-manage') || !$user->can('users-manage')) {
            abort(403);
        }

        $shelfId = (int) request()->get('shelf_id', 0);
        if ($shelfId <= 0) {
            return response()->json([]);
        }

        $books = DB::table('bookshelves_books')
            ->join('entities', function ($join) {
                $join->on('bookshelves_books.book_id', '=', 'entities.id')
                     ->where('entities.type', '=', 'book');
            })
            ->where('bookshelves_books.bookshelf_id', $shelfId)
            ->whereNull('entities.deleted_at')
            ->select('entities.id', 'entities.name')
            ->orderBy('entities.name')
            ->get();

        return response()->json($books);
    });

    $router->get('/stats-dashboard', function () {

        // --- Permission check: admin only ---
        $user = user();
        if (!$user || !$user->can('settings-manage') || !$user->can('users-manage')) {
            abort(403);
        }

        // --- Read filter params ---
        $filterTag     = trim(request()->get('filter_tag', ''));
        $filterShelfId = (int) request()->get('filter_shelf', 0);
        $filterBookId  = (int) request()->get('filter_book', 0);
        $filterType    = trim(request()->get('filter_type', ''));

        // --- Resolve book IDs for shelf filter ---
        $bookIdsInShelf = null;
        if ($filterShelfId > 0) {
            $bookIdsInShelf = DB::table('bookshelves_books')
                ->where('bookshelf_id', $filterShelfId)
                ->pluck('book_id')
                ->toArray();
        }

        // Helper: apply common page filters to a query builder
        $applyFilters = function ($query) use ($filterTag, $filterBookId, $bookIdsInShelf, $filterType) {
            if ($filterTag !== '') {
                $query->whereIn('entities.id', function ($sub) use ($filterTag) {
                    $sub->select('entity_id')
                        ->from('tags')
                        ->where('entity_type', 'page')
                        ->where('name', $filterTag);
                });
            }
            if ($filterBookId > 0) {
                $query->where('entities.book_id', $filterBookId);
            } elseif ($bookIdsInShelf !== null) {
                if (empty($bookIdsInShelf)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('entities.book_id', $bookIdsInShelf);
                }
            }
            if ($filterType === 'published') {
                $query->where('epd.draft', false)->where('epd.template', false);
            } elseif ($filterType === 'draft') {
                $query->where('epd.draft', true);
            } elseif ($filterType === 'template') {
                $query->where('epd.template', true);
            }
            return $query;
        };

        // --- Pagination & sorting setup ---
        $perPage = 20;
        $mvPage  = max(1, (int) request()->get('mv_page', 1));
        $luPage  = max(1, (int) request()->get('lu_page', 1));
        $spPage  = max(1, (int) request()->get('sp_page', 1));

        $mvSort = request()->get('mv_sort', 'total_views');
        $mvDir  = request()->get('mv_dir', 'desc');
        $luSort = request()->get('lu_sort', 'updated_at');
        $luDir  = request()->get('lu_dir', 'asc');
        $spSort = request()->get('sp_sort', 'updated_at');
        $spDir  = request()->get('sp_dir', 'asc');

        $mvDir = in_array($mvDir, ['asc', 'desc']) ? $mvDir : 'desc';
        $luDir = in_array($luDir, ['asc', 'desc']) ? $luDir : 'asc';
        $spDir = in_array($spDir, ['asc', 'desc']) ? $spDir : 'asc';

        $paginationOpts = function ($pageName) {
            return [
                'path'     => url('/stats-dashboard'),
                'pageName' => $pageName,
                'query'    => request()->except($pageName),
            ];
        };

        // --- 1. Most viewed pages ---
        $mvBaseQuery = DB::table('views')
            ->join('entities', function ($join) {
                $join->on('views.viewable_id', '=', 'entities.id')
                     ->where('views.viewable_type', '=', 'page');
            })
            ->join('entity_page_data as epd', 'epd.page_id', '=', 'entities.id')
            ->where('entities.type', '=', 'page')
            ->whereNull('entities.deleted_at');
        $applyFilters($mvBaseQuery);

        $mvTotal = (clone $mvBaseQuery)->distinct()->count('entities.id');

        $mostViewed = (clone $mvBaseQuery)
            ->select(
                'entities.id',
                'entities.name',
                'entities.slug',
                'entities.book_id',
                'epd.draft',
                'epd.template',
                DB::raw('SUM(views.views) as total_views'),
                DB::raw('COUNT(DISTINCT views.user_id) as unique_viewers')
            )
            ->groupBy('entities.id', 'entities.name', 'entities.slug', 'entities.book_id', 'epd.draft', 'epd.template');

        $mvSortCol = match($mvSort) {
            'type'           => DB::raw('CASE WHEN epd.draft THEN 0 WHEN epd.template THEN 1 ELSE 2 END'),
            'unique_viewers' => DB::raw('COUNT(DISTINCT views.user_id)'),
            default          => DB::raw('SUM(views.views)'),
        };
        $mostViewed = new LengthAwarePaginator(
            $mostViewed->orderBy($mvSortCol, $mvDir)->offset(($mvPage - 1) * $perPage)->limit($perPage)->get(),
            $mvTotal, $perPage, $mvPage, $paginationOpts('mv_page')
        );

        // --- 2. Least recently updated pages ---
        $luBaseQuery = DB::table('entities')
            ->join('entity_page_data as epd', 'epd.page_id', '=', 'entities.id')
            ->select('entities.id', 'entities.name', 'entities.slug', 'entities.book_id', 'entities.updated_at', 'epd.draft', 'epd.template')
            ->where('entities.type', '=', 'page')
            ->whereNull('entities.deleted_at');
        $applyFilters($luBaseQuery);

        $luTotal = (clone $luBaseQuery)->count();
        $luSortCol = match($luSort) {
            'type' => DB::raw('CASE WHEN epd.draft THEN 0 WHEN epd.template THEN 1 ELSE 2 END'),
            default => 'entities.updated_at',
        };
        $leastUpdated = new LengthAwarePaginator(
            (clone $luBaseQuery)->orderBy($luSortCol, $luDir)->offset(($luPage - 1) * $perPage)->limit($perPage)->get(),
            $luTotal, $perPage, $luPage, $paginationOpts('lu_page')
        );

        // --- 3. Stale / "expired" pages ---
        $staleDays = (int) request()->get('stale_days', 180);
        $staleDate = now()->subDays($staleDays)->toDateString();

        $spBaseQuery = DB::table('entities')
            ->join('entity_page_data as epd', 'epd.page_id', '=', 'entities.id')
            ->select('entities.id', 'entities.name', 'entities.slug', 'entities.book_id', 'entities.updated_at', 'epd.draft', 'epd.template')
            ->where('entities.type', '=', 'page')
            ->whereNull('entities.deleted_at')
            ->where('entities.updated_at', '<', $staleDate);
        $applyFilters($spBaseQuery);

        $spTotal = (clone $spBaseQuery)->count();
        $spSortCol = match($spSort) {
            'type' => DB::raw('CASE WHEN epd.draft THEN 0 WHEN epd.template THEN 1 ELSE 2 END'),
            default => 'entities.updated_at',
        };
        $stalePages = new LengthAwarePaginator(
            (clone $spBaseQuery)->orderBy($spSortCol, $spDir)->offset(($spPage - 1) * $perPage)->limit($perPage)->get(),
            $spTotal, $perPage, $spPage, $paginationOpts('sp_page')
        );

        // --- Dropdown data ---
        $allTags = DB::table('tags')
            ->where('entity_type', 'page')
            ->select('name')->distinct()->orderBy('name')->pluck('name');

        $allShelves = DB::table('entities')
            ->where('type', 'bookshelf')->whereNull('deleted_at')
            ->select('id', 'name')->orderBy('name')->get();

        $allBooks = DB::table('entities')
            ->where('type', 'book')->whereNull('deleted_at')
            ->select('id', 'name')->orderBy('name')->get();

        if ($filterShelfId > 0 && $bookIdsInShelf !== null) {
            $allBooks = $allBooks->whereIn('id', $bookIdsInShelf)->values();
        }

        return view('stats-dashboard', [
            'mostViewed'    => $mostViewed,
            'leastUpdated'  => $leastUpdated,
            'stalePages'    => $stalePages,
            'staleDays'     => $staleDays,
            'allTags'       => $allTags,
            'allShelves'    => $allShelves,
            'allBooks'      => $allBooks,
            'filterTag'     => $filterTag,
            'filterShelfId' => $filterShelfId,
            'filterBookId'  => $filterBookId,
            'filterType'    => $filterType,
            'mvSort' => $mvSort, 'mvDir' => $mvDir,
            'luSort' => $luSort, 'luDir' => $luDir,
            'spSort' => $spSort, 'spDir' => $spDir,
        ]);
    });
});

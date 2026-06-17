@extends('layouts.simple')

@php
    function sortUrl($prefix, $col, $currentSort, $currentDir) {
        $params = request()->all();
        $newDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
        $params[$prefix . '_sort'] = $col;
        $params[$prefix . '_dir']  = $newDir;
        unset($params[$prefix . '_page']);
        return url('/stats-dashboard') . '?' . http_build_query($params);
    }
    function sortIcon($prefix, $col, $currentSort, $currentDir) {
        if ($currentSort !== $col) return ' <span style="opacity:.4">↑↓</span>';
        return $currentDir === 'asc' ? ' ▲' : ' ▼';
    }
@endphp

@push('head')
<style nonce="{{ $cspNonce }}">
    a.sort-header { text-decoration: none; color: inherit; }
    a.sort-header:hover { text-decoration: underline; }
</style>
@endpush

@section('body')
    <div class="container">

        <div class="py-m">
            @include('settings/parts/navbar', ['selected' => 'stats-dashboard'])
        </div>

        {{-- ============================== --}}
        {{-- Filters                        --}}
        {{-- ============================== --}}
        <div class="card content-wrap auto-height mb-xl">
            <h2 class="list-heading">@icon('search') 過濾條件</h2>
            <hr class="mt-m mb-s">
            <form id="filter-form" action="{{ url('/stats-dashboard') }}" method="get" class="flex-container-row items-end gap-x-m gap-y-s wrap">

                {{-- Tag filter --}}
                <div class="flex-2" style="min-width:180px">
                    <label for="filter_tag" class="setting-list-label">Tag</label>
                    <select id="filter_tag" name="filter_tag" class="input-base w-100">
                        <option value="">-- 全部 --</option>
                        @foreach($allTags as $tag)
                            <option value="{{ $tag }}" @if($filterTag === $tag) selected @endif>{{ $tag }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Shelf filter --}}
                <div class="flex-2" style="min-width:180px">
                    <label for="filter_shelf" class="setting-list-label">書架</label>
                    <select id="filter_shelf" name="filter_shelf" class="input-base w-100">
                        <option value="">-- 全部 --</option>
                        @foreach($allShelves as $shelf)
                            <option value="{{ $shelf->id }}" @if($filterShelfId === $shelf->id) selected @endif>{{ $shelf->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Book filter --}}
                <div class="flex-2" style="min-width:180px">
                    <label for="filter_book" class="setting-list-label">書</label>
                    <select id="filter_book" name="filter_book" class="input-base w-100">
                        <option value="">-- 全部 --</option>
                        @foreach($allBooks as $book)
                            <option value="{{ $book->id }}" @if($filterBookId === $book->id) selected @endif>{{ $book->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Page type filter --}}
                <div class="flex" style="min-width:140px">
                    <label for="filter_type" class="setting-list-label">頁面類型</label>
                    <select id="filter_type" name="filter_type" class="input-base w-100">
                        <option value="">-- 全部 --</option>
                        <option value="published" @if($filterType === 'published') selected @endif>已發布</option>
                        <option value="draft" @if($filterType === 'draft') selected @endif>草稿</option>
                        <option value="template" @if($filterType === 'template') selected @endif>範本</option>
                    </select>
                </div>

                {{-- Stale days --}}
                <div class="flex" style="min-width:120px">
                    <label for="stale_days" class="setting-list-label">過期天數</label>
                    <input type="number" id="stale_days" name="stale_days" value="{{ $staleDays }}" min="1" max="9999" class="input-base w-100">
                </div>

                <div style="padding-bottom:2px">
                    <button type="submit" class="button outline small">套用</button>
                    <a href="{{ url('/stats-dashboard') }}" class="button outline small text-muted ml-xs">清除</a>
                </div>
            </form>
        </div>

        {{-- ============================== --}}
        {{-- Most Viewed Pages              --}}
        {{-- ============================== --}}
        <div class="card content-wrap auto-height mb-xl">
            <h2 class="list-heading">@icon('popular') 最多人看的文件 ({{ $mostViewed->total() }} 筆)</h2>
            <p class="text-muted">依照累積瀏覽次數排序</p>
            <hr class="mt-m mb-s">

            @if($mostViewed->isEmpty())
                <p class="text-muted italic">目前沒有瀏覽資料</p>
            @else
                <div class="item-list">
                    <div class="item-list-row flex-container-row items-center bold hide-under-m">
                        <div class="px-m py-xs" style="width:40px">#</div>
                        <div class="flex px-m py-xs">頁面名稱</div>
                        <div class="px-m py-xs text-center" style="width:80px"><a href="{{ sortUrl('mv', 'type', $mvSort, $mvDir) }}" class="sort-header">類型{!! sortIcon('mv', 'type', $mvSort, $mvDir) !!}</a></div>
                        <div class="px-m py-xs text-right" style="width:120px"><a href="{{ sortUrl('mv', 'total_views', $mvSort, $mvDir) }}" class="sort-header">總瀏覽次數{!! sortIcon('mv', 'total_views', $mvSort, $mvDir) !!}</a></div>
                        <div class="px-m py-xs text-right" style="width:120px"><a href="{{ sortUrl('mv', 'unique_viewers', $mvSort, $mvDir) }}" class="sort-header">不重複訪客{!! sortIcon('mv', 'unique_viewers', $mvSort, $mvDir) !!}</a></div>
                    </div>
                    @foreach($mostViewed as $index => $page)
                        <div class="item-list-row flex-container-row items-center py-xxs">
                            <div class="px-m py-xxs text-muted" style="width:40px">{{ $mostViewed->firstItem() + $index }}</div>
                            <div class="flex px-m py-xxs">
                                <a href="{{ url('/link/' . $page->id) }}" class="{{ $page->draft ? 'text-page draft' : '' }}">
                                    <span class="icon text-page {{ $page->draft ? 'draft' : '' }}">@icon('page')</span>
                                    {{ $page->name }}
                                </a>
                            </div>
                            <div class="px-m py-xxs text-center" style="width:80px">
                                @if($page->draft)<span style="color:var(--color-page-draft);font-weight:bold;font-size:.85em">草稿</span>
                                @elseif($page->template)<span style="color:var(--color-page);font-weight:bold;font-size:.85em">範本</span>
                                @else<span class="text-muted" style="font-size:.85em">已發布</span>
                                @endif
                            </div>
                            <div class="px-m py-xxs text-right" style="width:120px">{{ number_format($page->total_views) }}</div>
                            <div class="px-m py-xxs text-right" style="width:120px">{{ number_format($page->unique_viewers) }}</div>
                        </div>
                    @endforeach
                </div>
                @if($mostViewed->hasPages())
                <div class="flex-container-row justify-center items-center gap-x-m mt-m">
                    @if($mostViewed->onFirstPage())
                        <span class="text-muted">&laquo; 上一頁</span>
                    @else
                        <a href="{{ $mostViewed->previousPageUrl() }}">&laquo; 上一頁</a>
                    @endif
                    <span class="text-muted">第 {{ $mostViewed->currentPage() }} / {{ $mostViewed->lastPage() }} 頁</span>
                    @if($mostViewed->hasMorePages())
                        <a href="{{ $mostViewed->nextPageUrl() }}">下一頁 &raquo;</a>
                    @else
                        <span class="text-muted">下一頁 &raquo;</span>
                    @endif
                </div>
                @endif
            @endif
        </div>

        {{-- ============================== --}}
        {{-- Least Updated Pages            --}}
        {{-- ============================== --}}
        <div class="card content-wrap auto-height mb-xl">
            <h2 class="list-heading">@icon('history') 最久未更新文件 ({{ $leastUpdated->total() }} 筆)</h2>
            <p class="text-muted">依更新時間排序的所有頁面</p>
            <hr class="mt-m mb-s">

            @if($leastUpdated->isEmpty())
                <p class="text-muted italic">目前沒有頁面資料</p>
            @else
                <div class="item-list">
                    <div class="item-list-row flex-container-row items-center bold hide-under-m">
                        <div class="px-m py-xs" style="width:40px">#</div>
                        <div class="flex px-m py-xs">頁面名稱</div>
                        <div class="px-m py-xs text-center" style="width:80px"><a href="{{ sortUrl('lu', 'type', $luSort, $luDir) }}" class="sort-header">類型{!! sortIcon('lu', 'type', $luSort, $luDir) !!}</a></div>
                        <div class="px-m py-xs text-right" style="width:260px"><a href="{{ sortUrl('lu', 'updated_at', $luSort, $luDir) }}" class="sort-header">最後更新時間{!! sortIcon('lu', 'updated_at', $luSort, $luDir) !!}</a></div>
                    </div>
                    @foreach($leastUpdated as $index => $page)
                        <div class="item-list-row flex-container-row items-center py-xxs">
                            <div class="px-m py-xxs text-muted" style="width:40px">{{ $leastUpdated->firstItem() + $index }}</div>
                            <div class="flex px-m py-xxs">
                                <a href="{{ url('/link/' . $page->id) }}" class="{{ $page->draft ? 'text-page draft' : '' }}">
                                    <span class="icon text-page {{ $page->draft ? 'draft' : '' }}">@icon('page')</span>
                                    {{ $page->name }}
                                </a>
                            </div>
                            <div class="px-m py-xxs text-center" style="width:80px">
                                @if($page->draft)<span style="color:var(--color-page-draft);font-weight:bold;font-size:.85em">草稿</span>
                                @elseif($page->template)<span style="color:var(--color-page);font-weight:bold;font-size:.85em">範本</span>
                                @else<span class="text-muted" style="font-size:.85em">已發布</span>
                                @endif
                            </div>
                            <div class="px-m py-xxs text-right text-muted" style="width:260px">
                                {{ \Carbon\Carbon::parse($page->updated_at)->format('Y-m-d H:i') }}
                                <small>({{ \Carbon\Carbon::parse($page->updated_at)->diffForHumans() }})</small>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($leastUpdated->hasPages())
                <div class="flex-container-row justify-center items-center gap-x-m mt-m">
                    @if($leastUpdated->onFirstPage())
                        <span class="text-muted">&laquo; 上一頁</span>
                    @else
                        <a href="{{ $leastUpdated->previousPageUrl() }}">&laquo; 上一頁</a>
                    @endif
                    <span class="text-muted">第 {{ $leastUpdated->currentPage() }} / {{ $leastUpdated->lastPage() }} 頁</span>
                    @if($leastUpdated->hasMorePages())
                        <a href="{{ $leastUpdated->nextPageUrl() }}">下一頁 &raquo;</a>
                    @else
                        <span class="text-muted">下一頁 &raquo;</span>
                    @endif
                </div>
                @endif
            @endif
        </div>

        {{-- ============================== --}}
        {{-- Stale / Expired Pages          --}}
        {{-- ============================== --}}
        <div class="card content-wrap auto-height mb-xl">
            <h2 class="list-heading">@icon('warning') 文件過期 - 超過 {{ $staleDays }} 天未更新 ({{ $stalePages->total() }} 筆)</h2>

            <hr class="mt-m mb-s">

            @if($stalePages->isEmpty())
                <p class="text-muted italic">沒有超過 {{ $staleDays }} 天未更新的頁面 🎉</p>
            @else
                <div class="item-list">
                    <div class="item-list-row flex-container-row items-center bold hide-under-m">
                        <div class="px-m py-xs" style="width:40px">#</div>
                        <div class="flex px-m py-xs">頁面名稱</div>
                        <div class="px-m py-xs text-center" style="width:80px"><a href="{{ sortUrl('sp', 'type', $spSort, $spDir) }}" class="sort-header">類型{!! sortIcon('sp', 'type', $spSort, $spDir) !!}</a></div>
                        <div class="px-m py-xs text-right" style="width:260px"><a href="{{ sortUrl('sp', 'updated_at', $spSort, $spDir) }}" class="sort-header">最後更新時間{!! sortIcon('sp', 'updated_at', $spSort, $spDir) !!}</a></div>
                    </div>
                    @foreach($stalePages as $index => $page)
                        <div class="item-list-row flex-container-row items-center py-xxs">
                            <div class="px-m py-xxs text-muted" style="width:40px">{{ $stalePages->firstItem() + $index }}</div>
                            <div class="flex px-m py-xxs">
                                <a href="{{ url('/link/' . $page->id) }}" class="{{ $page->draft ? 'text-page draft' : '' }}">
                                    <span class="icon text-page {{ $page->draft ? 'draft' : '' }}">@icon('page')</span>
                                    {{ $page->name }}
                                </a>
                            </div>
                            <div class="px-m py-xxs text-center" style="width:80px">
                                @if($page->draft)<span style="color:var(--color-page-draft);font-weight:bold;font-size:.85em">草稿</span>
                                @elseif($page->template)<span style="color:var(--color-page);font-weight:bold;font-size:.85em">範本</span>
                                @else<span class="text-muted" style="font-size:.85em">已發布</span>
                                @endif
                            </div>
                            <div class="px-m py-xxs text-right text-muted" style="width:260px">
                                {{ \Carbon\Carbon::parse($page->updated_at)->format('Y-m-d H:i') }}
                                <small class="text-neg">({{ \Carbon\Carbon::parse($page->updated_at)->diffForHumans() }})</small>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if($stalePages->hasPages())
                <div class="flex-container-row justify-center items-center gap-x-m mt-m">
                    @if($stalePages->onFirstPage())
                        <span class="text-muted">&laquo; 上一頁</span>
                    @else
                        <a href="{{ $stalePages->previousPageUrl() }}">&laquo; 上一頁</a>
                    @endif
                    <span class="text-muted">第 {{ $stalePages->currentPage() }} / {{ $stalePages->lastPage() }} 頁</span>
                    @if($stalePages->hasMorePages())
                        <a href="{{ $stalePages->nextPageUrl() }}">下一頁 &raquo;</a>
                    @else
                        <span class="text-muted">下一頁 &raquo;</span>
                    @endif
                </div>
                @endif
            @endif
        </div>

    </div>

<script nonce="{{ $cspNonce }}">
(function () {
    // --- Shelf → Book cascading ---
    const shelfSelect = document.getElementById('filter_shelf');
    const bookSelect  = document.getElementById('filter_book');
    const savedBookId = {{ $filterBookId }};

    shelfSelect.addEventListener('change', function () {
        const shelfId = this.value;
        bookSelect.innerHTML = '<option value="">-- 全部 --</option>';

        if (!shelfId) {
            // Reload all books
            fetch('{{ url("/stats-dashboard/books-by-shelf") }}?shelf_id=0')
                .then(r => r.json())
                .catch(() => []);
            return;
        }

        fetch('{{ url("/stats-dashboard/books-by-shelf") }}?shelf_id=' + encodeURIComponent(shelfId))
            .then(r => r.json())
            .then(books => {
                books.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b.id;
                    opt.textContent = b.name;
                    bookSelect.appendChild(opt);
                });
            })
            .catch(() => {});
    });
})();
</script>
@stop

{{--
    用法：@include('ai-chat.parts.response-sources', ['entities' => $entities])
    $entities：BookStack entity 集合，傳給 entities.list
--}}

@once
@push('head')
<style>
    .response-sources.collapsed .entity-list {
        margin-inline: -4px;
    }
    .response-sources.collapsed .entity-list-item {
        display: inline-flex;
        width: auto;
        border: 2px solid currentColor;
        border-radius: 24px;
        padding: 4px;
        padding-inline-end: 8px;
        gap: 6px;
        margin-block-end: 6px;
    }
    .response-sources.collapsed .entity-list-item.page      { color: var(--color-page); }
    .response-sources.collapsed .entity-list-item.chapter   { color: var(--color-chapter); }
    .response-sources.collapsed .entity-list-item.book      { color: var(--color-book); }
    .response-sources.collapsed .entity-list-item.bookshelf { color: var(--color-bookshelf); }
    .response-sources.collapsed .entity-list-item:hover {
        border-radius: 24px !important;
    }
    .response-sources.collapsed .entity-list-item span.icon {
        width: 1.44em;
        height: 1.44em;
    }
    .response-sources.collapsed h4 {
        font-size: 0.75rem;
        color: currentColor;
        font-weight: bold;
    }
    .response-sources.collapsed .entity-item-snippet {
        display: none;
    }

    .response-sources:not(.collapsed) .entity-list {
        display: flex;
        flex-wrap: wrap;
        flex-direction: row;
    }
    .response-sources:not(.collapsed) .entity-list .entity-list-item {
        flex: 1;
        min-width: 320px;
    }
    .response-sources:not(.collapsed) .entity-list .entity-item-snippet {
        display: block;
    }

    .response-sources .entity-list-item {
        animation: itemFadeIn 240ms ease-in-out forwards;
        opacity: 0;
    }
    @keyframes itemFadeIn {
        from { opacity: 0; transform: translateX(-10px); }
        to   { opacity: 1; transform: translateX(0); }
    }
</style>
@endpush
@endonce

<div class="flex-container-row justify-flex-start v-center gap-m my-m">
    <h2 style="font-size:1rem;font-weight:bold;margin-block:0;">Relevant Sources Considered</h2>
    <button class="text-button text-muted bold js-sources-toggle">Expand</button>
</div>
<div class="response-sources collapsed">
    @include('entities.list', ['entities' => $entities, 'style' => 'compact'])
</div>

@once
@push('body-end')
<script>
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-sources-toggle');
        if (!btn) return;
        const sources = btn.closest('div').nextElementSibling;
        sources.classList.toggle('collapsed');
        btn.textContent = sources.classList.contains('collapsed') ? 'Expand' : 'Collapse';
    });
</script>
@endpush
@endonce

@extends('layouts.simple')

@section('body')
<div component="ai-chat-manager" class="container small pt-xxl">

    <div id="ai-chat-response-area" refs="ai-chat-manager@response-area"></div>

    <div refs="ai-chat-manager@form-card" class="card content-wrap auto-height">
        <fieldset class="ai-chat-form" refs="ai-chat-manager@fieldset">
            <textarea refs="ai-chat-manager@input"
                      placeholder="Ask a question..."
                      autocomplete="off"
                      rows="1"></textarea>
            <div class="flex-container-row items-center justify-space-between mt-s">
                <button type="button" refs="ai-chat-manager@reset-button" class="button outline">
                    New Conversation
                </button>
                <button type="button" refs="ai-chat-manager@send-button" class="button">
                    Send
                </button>
            </div>
        </fieldset>
    </div>

</div>
@stop

@push('head')
<style>
    /* ── Layout ── */
    #ai-chat-response-area {
        display: flex;
        flex-direction: column;
        gap: 16px;
        margin-bottom: 16px;
    }

    /* ── Form ── */
    .ai-chat-form textarea {
        width: 100%;
        min-width: 100%;
        max-width: 100%;
        min-height: 72px;
        font-size: 1.1rem;
        border: 0;
        resize: none;
    }
    .ai-chat-form fieldset[disabled] button[type="button"]:last-child {
        opacity: 0.6;
        pointer-events: none;
    }
    .ai-chat-form fieldset[disabled] textarea {
        background: #eee;
        cursor: not-allowed;
    }

    /* ── Message bubbles ── */
    .ai-msg {
        display: flex;
        align-items: flex-end;
        gap: 10px;
    }
    .ai-msg--user {
        flex-direction: row-reverse;
    }
    .ai-msg__bubble {
        max-width: 78%;
        padding: 10px 14px;
        border-radius: 18px;
        font-size: 0.95rem;
        line-height: 1.6;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .ai-msg--user .ai-msg__bubble {
        background: var(--color-primary);
        color: #fff;
        border-bottom-right-radius: 4px;
    }
    .ai-msg--bot .ai-msg__bubble {
        background: #fff;
        border: 1px solid #e0e4ea;
        border-bottom-left-radius: 4px;
        color: #333;
    }

    /* ── Typing indicator ── */
    .ai-typing {
        display: flex;
        gap: 5px;
        padding: 12px 16px;
        background: #fff;
        border: 1px solid #e0e4ea;
        border-radius: 18px;
        border-bottom-left-radius: 4px;
        width: fit-content;
    }
    .ai-typing span {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #aab;
        animation: ai-bounce 1.2s infinite ease-in-out;
    }
    .ai-typing span:nth-child(2) { animation-delay: .2s; }
    .ai-typing span:nth-child(3) { animation-delay: .4s; }
    @keyframes ai-bounce {
        0%, 60%, 100% { transform: translateY(0); }
        30%            { transform: translateY(-6px); }
    }

    /* ── Sources ── */
    .ai-sources {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid #e8eaed;
    }
    .ai-sources-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
    }
    .ai-sources-header strong {
        font-size: 0.85rem;
    }
    .ai-sources-toggle {
        font-size: 0.8rem;
        color: #666;
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
    }

    /* Sources chip / list styles (reuse from response-sources partial) */
    .response-sources.collapsed .entity-list { margin-inline: -4px; }
    .response-sources.collapsed .entity-list-item {
        display: inline-flex; width: auto;
        border: 2px solid currentColor; border-radius: 24px;
        padding: 4px; padding-inline-end: 8px;
        gap: 6px; margin-block-end: 6px; text-decoration: none;
    }
    .response-sources.collapsed .entity-list-item.page      { color: var(--color-page); }
    .response-sources.collapsed .entity-list-item.chapter   { color: var(--color-chapter); }
    .response-sources.collapsed .entity-list-item.book      { color: var(--color-book); }
    .response-sources.collapsed .entity-list-item:hover     { border-radius: 24px !important; }
    .response-sources.collapsed .entity-list-item span.icon { width: 1.44em; height: 1.44em; }
    .response-sources.collapsed h4 { font-size: 0.75rem; color: currentColor; font-weight: bold; margin: 0; }
    .response-sources.collapsed .entity-item-snippet { display: none; }
    .response-sources:not(.collapsed) .entity-list { display: flex; flex-wrap: wrap; }
    .response-sources:not(.collapsed) .entity-list .entity-list-item { flex: 1; min-width: 280px; }
</style>
@endpush

@push('body-end')
<script type="module" nonce="{{ $cspNonce ?? '' }}">
class AiChatManager {
    setup() {
        this.responseArea = this.$refs.responseArea;
        this.formCard     = this.$refs.formCard;
        this.fieldset     = this.$refs.fieldset;
        this.input        = this.$refs.input;
        this.sendButton   = this.$refs.sendButton;
        this.resetButton  = this.$refs.resetButton;

        this.input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.send(); }
        });
        this.input.addEventListener('input', () => {
            this.input.style.height = 'auto';
            this.input.style.height = Math.min(this.input.scrollHeight, 180) + 'px';
        });
        this.sendButton.addEventListener('click',  () => this.send());
        this.resetButton.addEventListener('click', () => window.location = window.baseUrl('/ai-chat'));
    }

    async send() {
        const query = this.input.value.trim();
        if (!query || this.fieldset.disabled) return;

        this.appendUserBubble(query);
        this.input.value = '';
        this.input.style.height = 'auto';
        this.fieldset.disabled = true;

        const typingEl  = this.appendTyping();
        const botBubble = this.createBotBubble();

        const url = window.baseUrl('/ai-chat/ask') + '?query=' + encodeURIComponent(query);
        const es  = new EventSource(url, { withCredentials: true });

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

        es.addEventListener('error', () => {
            es.close();
            if (typingEl.parentNode) typingEl.remove();
            this.fieldset.disabled = false;
            this.input.focus();
        });
    }

    appendUserBubble(text) {
        const el = document.createElement('div');
        el.className = 'ai-msg ai-msg--user';
        el.innerHTML = `<div class="ai-msg__bubble">${this.escHtml(text)}</div>`;
        this.responseArea.appendChild(el);
    }

    appendTyping() {
        const el = document.createElement('div');
        el.className = 'ai-msg ai-msg--bot';
        el.innerHTML = '<div class="ai-typing"><span></span><span></span><span></span></div>';
        this.responseArea.appendChild(el);
        this.responseArea.scrollTop = this.responseArea.scrollHeight;
        return el;
    }

    createBotBubble() {
        const el = document.createElement('div');
        el.className = 'ai-msg ai-msg--bot';
        el.innerHTML = '<div class="ai-msg__bubble"></div>';
        return el;
    }

    escHtml(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    renderSources(bubble, sources) {
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
        bubble.appendChild(wrap);
    }
}

window.$components.register({'ai-chat-manager': AiChatManager});
window.$components.init(document.body);
</script>
@endpush

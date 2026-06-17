@php
    $aiAllowedRoles = AiChatSettings::getRoles();
    $userRoleIds    = user()->roles->pluck('id')->toArray();
    $canUseAi       = user()->can('settings-manage')
                   || (!empty($aiAllowedRoles) && !empty(array_intersect($aiAllowedRoles, $userRoleIds)));
@endphp
@if(!user()->isGuest() && $canUseAi)

<div id="ai-chat-overlay"></div>

<div id="ai-chat-panel" role="dialog" aria-label="Ask AI" aria-modal="true">
    <div id="ai-chat-header">
        <div class="ai-chat-title">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true">
                <path d="M12 2a7 7 0 0 1 7 7c0 3.5-2.5 6.5-6 7v2H9v-2c-3.5-.5-6-3.5-6-7a7 7 0 0 1 7-7z"/>
                <line x1="9" y1="21" x2="15" y2="21"/>
                <line x1="9" y1="18" x2="15" y2="18"/>
                <circle cx="9" cy="10" r="1" fill="currentColor"/>
                <circle cx="15" cy="10" r="1" fill="currentColor"/>
            </svg>
            <span>Ask AI</span>
        </div>
        <button type="button" id="ai-chat-close" aria-label="Close">&times;</button>
    </div>

    <div id="ai-chat-messages">
        <div class="ai-msg ai-msg--bot">
            <div class="ai-msg__avatar">AI</div>
            <div class="ai-msg__bubble">
                Hi! Ask me anything about your knowledge base.
            </div>
        </div>
    </div>

    <div id="ai-chat-input-area">
        <textarea id="ai-chat-input"
                  placeholder="Ask a question... (Enter to send)"
                  rows="1"
                  maxlength="2000"></textarea>
        <button type="button" id="ai-chat-send" aria-label="Send">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
                <line x1="22" y1="2" x2="11" y2="13"/>
                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
        </button>
    </div>
</div>

<style @if($cspNonce ?? false) nonce="{{ $cspNonce }}" @endif>
#ai-sidebar-trigger {
    display: inline-block;
    padding: 10px 16px;
    color: #FFF;
    border-radius: 3px;
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: inherit;
    font-family: inherit;
    line-height: inherit;
    transition: background-color 0.15s;
}
#ai-sidebar-trigger:hover {
    text-decoration: none;
    background-color: rgba(255, 255, 255, 0.15);
}

#ai-chat-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    z-index: 1040;
    transition: opacity 0.25s;
    opacity: 0;
}
#ai-chat-overlay.show {
    display: block;
    opacity: 1;
}

#ai-chat-panel {
    position: fixed;
    top: 0;
    right: 0;
    width: 380px;
    max-width: 100vw;
    height: 100vh;
    background: #fff;
    box-shadow: -4px 0 24px rgba(0, 0, 0, 0.18);
    z-index: 1050;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    font-family: inherit;
}
#ai-chat-panel.open {
    transform: translateX(0);
}

#ai-chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: var(--color-primary, #0288D1);
    color: #fff;
    flex-shrink: 0;
}
.ai-chat-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1rem;
    font-weight: 600;
}
#ai-chat-close {
    background: transparent;
    border: none;
    color: #fff;
    font-size: 1.5rem;
    line-height: 1;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    opacity: 0.85;
    transition: opacity 0.15s, background 0.15s;
}
#ai-chat-close:hover {
    opacity: 1;
    background: rgba(255, 255, 255, 0.2);
}

#ai-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: #f7f8fa;
}

.ai-msg {
    display: flex;
    align-items: flex-end;
    gap: 8px;
}
.ai-msg--user {
    flex-direction: row-reverse;
}
.ai-msg__avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--color-primary, #0288D1);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    text-transform: uppercase;
}
.ai-msg--user .ai-msg__avatar {
    background: #546e7a;
}
.ai-msg__bubble {
    max-width: 80%;
    padding: 10px 13px;
    border-radius: 16px;
    font-size: 0.875rem;
    line-height: 1.55;
    white-space: pre-wrap;
    word-break: break-word;
}
.ai-msg--bot .ai-msg__bubble {
    background: #fff;
    border: 1px solid #e0e4ea;
    border-bottom-left-radius: 4px;
    color: #333;
}
.ai-msg--user .ai-msg__bubble {
    background: var(--color-primary, #0288D1);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.ai-typing {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 10px 13px;
    background: #fff;
    border: 1px solid #e0e4ea;
    border-radius: 16px;
    border-bottom-left-radius: 4px;
    width: fit-content;
}
.ai-typing span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #aab;
    display: inline-block;
    animation: ai-bounce 1.2s infinite ease-in-out;
}
.ai-typing span:nth-child(2) { animation-delay: 0.2s; }
.ai-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes ai-bounce {
    0%, 60%, 100% { transform: translateY(0); }
    30%            { transform: translateY(-6px); }
}

#ai-chat-input-area {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 12px 14px;
    border-top: 1px solid #e0e4ea;
    background: #fff;
    flex-shrink: 0;
}
#ai-chat-input {
    flex: 1;
    resize: none;
    border: 1px solid #cdd2d9;
    border-radius: 20px;
    padding: 9px 14px;
    font-size: 0.875rem;
    font-family: inherit;
    line-height: 1.5;
    outline: none;
    transition: border-color 0.15s;
    max-height: 120px;
    overflow-y: auto;
}
#ai-chat-input:focus {
    border-color: var(--color-primary, #0288D1);
}
#ai-chat-send {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--color-primary, #0288D1);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: opacity 0.15s, transform 0.1s;
}
#ai-chat-send:hover {
    opacity: 0.9;
    transform: scale(1.05);
}
#ai-chat-send:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    transform: none;
}

@media (max-width: 480px) {
    #ai-chat-panel { width: 100vw; }
}
</style>

<script @if($cspNonce ?? false) nonce="{{ $cspNonce }}" @endif>
(function () {
    const trigger  = document.getElementById('ai-sidebar-trigger');
    const panel    = document.getElementById('ai-chat-panel');
    const overlay  = document.getElementById('ai-chat-overlay');
    const closeBtn = document.getElementById('ai-chat-close');
    const messages = document.getElementById('ai-chat-messages');
    const input    = document.getElementById('ai-chat-input');
    const sendBtn  = document.getElementById('ai-chat-send');

    if (!trigger || !panel) return;

    function openPanel() {
        panel.classList.add('open');
        overlay.classList.add('show');
        input.focus();
    }
    function closePanel() {
        panel.classList.remove('open');
        overlay.classList.remove('show');
    }

    trigger.addEventListener('click', function () {
        panel.classList.contains('open') ? closePanel() : openPanel();
    });
    closeBtn.addEventListener('click', closePanel);
    overlay.addEventListener('click', closePanel);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && panel.classList.contains('open')) closePanel();
    });

    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    sendBtn.addEventListener('click', sendMessage);

    function appendMessage(role, text) {
        const div = document.createElement('div');
        div.className = 'ai-msg ai-msg--' + role;
        div.innerHTML =
            '<div class="ai-msg__avatar">' + (role === 'user' ? 'You' : 'AI') + '</div>' +
            '<div class="ai-msg__bubble">' + escapeHtml(text) + '</div>';
        messages.appendChild(div);
        scrollBottom();
        return div;
    }

    function showTyping() {
        const wrapper = document.createElement('div');
        wrapper.className = 'ai-msg ai-msg--bot';
        wrapper.innerHTML =
            '<div class="ai-msg__avatar">AI</div>' +
            '<div class="ai-typing"><span></span><span></span><span></span></div>';
        messages.appendChild(wrapper);
        scrollBottom();
        return wrapper;
    }

    function createBotBubble() {
        const div = document.createElement('div');
        div.className = 'ai-msg ai-msg--bot';
        div.innerHTML = '<div class="ai-msg__avatar">AI</div><div class="ai-msg__bubble"></div>';
        return div;
    }

    function sendMessage() {
        const text = input.value.trim();
        if (!text || sendBtn.disabled) return;

        appendMessage('user', text);
        input.value = '';
        input.style.height = 'auto';
        sendBtn.disabled = true;

        const typingEl  = showTyping();
        const botBubble = createBotBubble();

        const url = window.baseUrl('/ai-chat/ask') + '?query=' + encodeURIComponent(text);
        const es  = new EventSource(url, { withCredentials: true });

        es.addEventListener('update', function (e) {
            const raw = e.data || '';

            if (raw === '</stream>') {
                es.close();
                if (typingEl.parentNode) typingEl.replaceWith(botBubble);
                sendBtn.disabled = false;
                input.focus();
                return;
            }

            let data;
            try { data = JSON.parse(raw); } catch (err) { return; }

            if (data.text) {
                if (typingEl.parentNode) typingEl.replaceWith(botBubble);
                botBubble.querySelector('.ai-msg__bubble').textContent += data.text;
                scrollBottom();
            }
        });

        es.addEventListener('error', function () {
            es.close();
            if (typingEl.parentNode) typingEl.remove();
            sendBtn.disabled = false;
            input.focus();
        });
    }

    function scrollBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function escapeHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
</script>

@endif

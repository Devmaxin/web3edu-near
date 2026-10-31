/* ============================================================
   Web3edu — AI Tutor Page Controller
   ============================================================ */
(function () {
    'use strict';

    var cfg = window.__AI_TUTOR_CONFIG__ || { rateLimitAllowed: true, isPremium: false, limit: 1 };

    var messagesEl  = document.getElementById('aiChatMessages');
    var emptyEl     = document.getElementById('aiChatEmpty');
    var input       = document.getElementById('aiTutorInput');
    var sendBtn     = document.getElementById('aiTutorSend');
    var remainingEl = document.getElementById('aiRemainingBadge');
    var composer    = document.getElementById('aiChatComposer');

    var historyListDesktop = document.getElementById('aiHistoryListDesktop');
    var historyListMobile  = document.getElementById('aiHistoryListMobile');
    var newChatBtns = [document.getElementById('aiNewChatBtnDesktop'), document.getElementById('aiNewChatBtnMobile')];

    var historyToggle = document.getElementById('aiHistoryToggle');
    var drawer         = document.getElementById('aiHistoryDrawer');
    var drawerOverlay  = document.getElementById('aiDrawerOverlay');
    var drawerClose     = document.getElementById('aiDrawerClose');

    var conversationId = null;
    var sending = false;

    // ── Mobile drawer ──────────────────────────────────────────
    function openDrawer() { drawer.classList.add('open'); drawerOverlay.classList.add('open'); }
    function closeDrawer() { drawer.classList.remove('open'); drawerOverlay.classList.remove('open'); }
    if (historyToggle) historyToggle.addEventListener('click', openDrawer);
    if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
    if (drawerOverlay) drawerOverlay.addEventListener('click', closeDrawer);

    // ── Message rendering ──────────────────────────────────────
    function clearEmpty() { if (emptyEl) { emptyEl.remove(); emptyEl = null; } }

    function appendMessage(role, text) {
        clearEmpty();
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + role;
        div.textContent = text;
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return div;
    }

    function appendTyping() {
        clearEmpty();
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-assistant';
        div.innerHTML = '<span class="ai-msg-typing"><span></span><span></span><span></span></span>';
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return div;
    }

    function renderUpgradeBanner(limit) {
        composer.innerHTML =
            '<div class="ai-upgrade-banner">' +
            '<h4>⭐ Daily free question used</h4>' +
            '<p>Free members get ' + limit + ' AI Tutor question per day. Upgrade to Premium for unlimited conversations and history.</p>' +
            '<a href="/upgrade.php" class="btn-upgrade btn-sm">Upgrade Now</a>' +
            '</div>';
    }

    function autoGrow() {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }
    if (input) input.addEventListener('input', autoGrow);

    function setActiveHistoryItem(id) {
        document.querySelectorAll('.ai-history-item').forEach(function (el) {
            el.classList.toggle('active', String(id) === el.getAttribute('data-conversation-id'));
        });
    }

    function loadConversation(id) {
        conversationId = id;
        setActiveHistoryItem(id);
        messagesEl.innerHTML = '<div class="ai-chat-empty">Loading…</div>';
        fetch('/api/ai_conversations.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                messagesEl.innerHTML = '';
                if (!data.success) {
                    appendMessage('assistant', data.error || 'Could not load this conversation.');
                    return;
                }
                data.messages.forEach(function (m) { appendMessage(m.role, m.content); });
                if (data.messages.length === 0) {
                    messagesEl.innerHTML = '<div class="ai-chat-empty">🧠 Ask a question above to get started.</div>';
                }
            })
            .catch(function () { appendMessage('assistant', 'Network error loading conversation.'); });
        closeDrawer();
    }

    function startNewConversation() {
        conversationId = null;
        setActiveHistoryItem(null);
        messagesEl.innerHTML = '<div class="ai-chat-empty">🧠 Ask a question above or pick a suggestion to get started.</div>';
        closeDrawer();
        if (input) input.focus();
    }

    newChatBtns.forEach(function (btn) { if (btn) btn.addEventListener('click', startNewConversation); });

    function prependHistoryItem(id, title) {
        [historyListDesktop, historyListMobile].forEach(function (list) {
            if (!list) return;
            var placeholder = list.querySelector('div:not(.ai-history-item)');
            if (placeholder) placeholder.remove();
            var item = document.createElement('div');
            item.className = 'ai-history-item active';
            item.setAttribute('data-conversation-id', id);
            item.textContent = title;
            list.insertBefore(item, list.firstChild);
        });
    }

    document.addEventListener('click', function (e) {
        var item = e.target.closest('.ai-history-item');
        if (item) {
            var id = item.getAttribute('data-conversation-id');
            if (id) loadConversation(id);
        }
    });

    // ── Suggested questions ────────────────────────────────────
    var suggestionsEl = document.getElementById('aiSuggestions');
    if (suggestionsEl) {
        suggestionsEl.addEventListener('click', function (e) {
            var chip = e.target.closest('.ai-suggestion-chip');
            if (!chip) return;
            sendMessage(chip.textContent.trim());
        });
    }

    // ── Sending ─────────────────────────────────────────────────
    function sendMessage(forcedText) {
        if (sending || !cfg.rateLimitAllowed) return;
        var text = (forcedText !== undefined ? forcedText : (input ? input.value.trim() : ''));
        if (!text) return;

        sending = true;
        if (sendBtn) sendBtn.disabled = true;

        appendMessage('user', text);
        if (input) { input.value = ''; autoGrow(); }
        var typingEl = appendTyping();

        var formData = new FormData();
        formData.append('message', text);
        formData.append('source', 'page');
        if (conversationId) formData.append('conversation_id', conversationId);
        var lessonCtx = window.__WEB3EDU_LESSON_CONTEXT__;
        if (lessonCtx && lessonCtx.lesson_id) {
            formData.append('lesson_id', lessonCtx.lesson_id);
            formData.append('lesson_type', lessonCtx.lesson_type || '');
        }

        fetch('/api/ai_chat.php', { method: 'POST', body: formData, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                typingEl.remove();
                if (data.success) {
                    var isNewConversation = !conversationId;
                    conversationId = data.conversation_id;
                    appendMessage('assistant', data.reply);
                    if (isNewConversation) prependHistoryItem(conversationId, text.slice(0, 60));

                    if (!cfg.isPremium) {
                        if (typeof data.remaining === 'number') {
                            if (remainingEl) remainingEl.textContent = data.remaining + ' free question' + (data.remaining === 1 ? '' : 's') + ' left today';
                        }
                        if (data.limit_reached) {
                            cfg.rateLimitAllowed = false;
                            renderUpgradeBanner(cfg.limit || 1);
                        }
                    }
                } else if (data.limit_reached) {
                    cfg.rateLimitAllowed = false;
                    renderUpgradeBanner(cfg.limit || 1);
                } else {
                    appendMessage('assistant', data.error || 'Something went wrong. Please try again.');
                }
            })
            .catch(function () {
                typingEl.remove();
                appendMessage('assistant', 'Network error — please try again.');
            })
            .finally(function () {
                sending = false;
                if (sendBtn) sendBtn.disabled = false;
            });
    }

    if (sendBtn) sendBtn.addEventListener('click', function () { sendMessage(); });
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }
})();

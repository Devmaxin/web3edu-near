/* ============================================================
   Web3edu — Floating AI Widget
   Powers the site-wide FAB + quick chat popup. Talks to the SAME
   backend endpoint (api/ai_chat.php) used by the dedicated AI Tutor
   page, so conversation history and rate limits stay consistent.
   ============================================================ */
(function () {
    'use strict';

    var fab        = document.getElementById('aiFabBtn');
    var popup      = document.getElementById('aiPopup');
    var closeBtn   = document.getElementById('aiPopupClose');
    var body       = document.getElementById('aiPopupBody');
    var input      = document.getElementById('aiPopupInput');
    var sendBtn    = document.getElementById('aiPopupSend');

    if (!fab || !popup) return;

    var conversationId = null;
    var sending = false;

    function openPopup() {
        popup.classList.add('open');
        fab.setAttribute('aria-expanded', 'true');
        if (input && !input.disabled) input.focus();
    }
    function closePopup() {
        popup.classList.remove('open');
        fab.setAttribute('aria-expanded', 'false');
    }
    function togglePopup() {
        popup.classList.contains('open') ? closePopup() : openPopup();
    }

    fab.addEventListener('click', togglePopup);
    if (closeBtn) closeBtn.addEventListener('click', closePopup);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && popup.classList.contains('open')) closePopup();
    });
    document.addEventListener('click', function (e) {
        if (!popup.classList.contains('open')) return;
        if (popup.contains(e.target) || fab.contains(e.target)) return;
        closePopup();
    });

    function appendMessage(role, text) {
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-' + role;
        div.textContent = text;
        body.appendChild(div);
        body.scrollTop = body.scrollHeight;
        return div;
    }

    function appendTyping() {
        var div = document.createElement('div');
        div.className = 'ai-msg ai-msg-assistant ai-msg-typing-wrap';
        div.innerHTML = '<span class="ai-msg-typing"><span></span><span></span><span></span></span>';
        body.appendChild(div);
        body.scrollTop = body.scrollHeight;
        return div;
    }

    function autoGrow() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 90) + 'px';
    }
    if (input) input.addEventListener('input', autoGrow);

    function sendMessage() {
        if (!input || sending) return;
        var text = input.value.trim();
        if (!text) return;

        sending = true;
        if (sendBtn) sendBtn.disabled = true;
        appendMessage('user', text);
        input.value = '';
        autoGrow();
        var typingEl = appendTyping();

        var formData = new FormData();
        formData.append('message', text);
        formData.append('source', 'widget');
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
                    conversationId = data.conversation_id;
                    appendMessage('assistant', data.reply);
                    if (data.limit_reached) {
                        var upsell = document.createElement('div');
                        upsell.className = 'ai-popup-welcome';
                        upsell.innerHTML = "You've used today's free question. <a href='/upgrade.php' style='color:var(--primary-bright);font-weight:700'>Upgrade to Premium</a> for unlimited AI Tutor access — or continue with Study Cards, which stay free.";
                        body.appendChild(upsell);
                        body.scrollTop = body.scrollHeight;
                        if (input) input.disabled = true;
                        if (sendBtn) sendBtn.disabled = true;
                    }
                } else if (data.limit_reached) {
                    appendMessage('assistant', data.error || "You've reached today's free limit. Upgrade to Premium for unlimited questions.");
                    if (input) input.disabled = true;
                    if (sendBtn) sendBtn.disabled = true;
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
                if (sendBtn && !sendBtn.disabled) sendBtn.disabled = false;
                if (sendBtn) sendBtn.disabled = (input && input.disabled) ? true : false;
            });
    }

    if (sendBtn) sendBtn.addEventListener('click', sendMessage);
    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }
})();

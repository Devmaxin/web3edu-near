(function () {
  const cfg = window.__LESSON_ENGAGEMENT__;
  if (!cfg) return;
  const siteUrl = window.SITE_URL || '';

  // ── Private notes — debounced autosave ────────────────────────────────────
  const noteInput = document.getElementById('lessonNoteInput');
  const status = document.getElementById('noteSaveStatus');
  if (noteInput) {
    let saveTimer = null;
    noteInput.addEventListener('input', () => {
      status.textContent = 'Saving…';
      clearTimeout(saveTimer);
      saveTimer = setTimeout(() => {
        fetch(siteUrl + '/api/lesson-note-save.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'lesson_id=' + cfg.lessonId + '&content=' + encodeURIComponent(noteInput.value),
        }).then(() => { status.textContent = 'Saved ✓'; setTimeout(() => status.textContent = '', 2000); });
      }, 800);
    });
  }

  // ── Text highlights ────────────────────────────────────────────────────────
  const content = document.getElementById('lesson-content');
  const toolbar = document.getElementById('highlightToolbar');
  if (!content || !toolbar) return;

  const colors = { yellow: '#facc15', green: '#4ade80', blue: '#60a5fa', pink: '#f472b6' };

  document.addEventListener('mouseup', () => {
    const sel = window.getSelection();
    const text = sel.toString().trim();
    if (!text || !content.contains(sel.anchorNode)) { toolbar.style.display = 'none'; return; }
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    toolbar.style.left = (rect.left + window.scrollX) + 'px';
    toolbar.style.top = (rect.top + window.scrollY - 42) + 'px';
    toolbar.style.display = 'flex';
    toolbar.dataset.pendingText = text;
  });

  function wrapHighlight(text, color) {
    const walker = document.createTreeWalker(content, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      const idx = node.textContent.indexOf(text);
      if (idx === -1) continue;
      const range = document.createRange();
      range.setStart(node, idx);
      range.setEnd(node, idx + text.length);
      const mark = document.createElement('mark');
      mark.className = 'lesson-highlight';
      mark.style.background = colors[color] + '55';
      range.surroundContents(mark);
      break;
    }
  }

  window.LessonEngagement = {
    applyHighlight(color) {
      const text = toolbar.dataset.pendingText;
      if (!text) return;
      fetch(siteUrl + '/api/highlight.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add&lesson_id=' + cfg.lessonId + '&text=' + encodeURIComponent(text) + '&color=' + color,
      }).then(() => {
        toolbar.style.display = 'none';
        wrapHighlight(text, color);
        window.getSelection().removeAllRanges();
      });
    },
  };

  (cfg.savedHighlights || []).forEach(h => wrapHighlight(h.text, h.color));
})();

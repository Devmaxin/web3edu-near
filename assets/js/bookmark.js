document.querySelectorAll('.bookmark-btn').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    const type = btn.dataset.type;
    const id = btn.dataset.id;
    btn.disabled = true;
    try {
      const base = window.SITE_URL || '';
      const res = await fetch(base + '/api/toggle_bookmark.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`
      });
      const data = await res.json();
      if (data.success) {
        btn.classList.toggle('active', data.bookmarked);
        btn.setAttribute('aria-pressed', data.bookmarked ? 'true' : 'false');
        btn.title = data.bookmarked ? 'Remove bookmark' : 'Save for later';
      } else if (data.error) {
        showToast ? showToast(data.error, 'error') : alert(data.error);
      }
    } catch (e) { /* network issue — silently ignore, button state unchanged */ }
    btn.disabled = false;
  });
});

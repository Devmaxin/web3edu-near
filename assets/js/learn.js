(function () {
  const toggle = document.getElementById('learnToggle');
  if (!toggle) return;
  const buttons = toggle.querySelectorAll('button');
  const panels = document.querySelectorAll('.learn-panel');

  buttons.forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.dataset.panel;
      buttons.forEach(b => b.classList.toggle('active', b === btn));
      panels.forEach(p => p.classList.toggle('active', p.id === target));
      history.replaceState(null, '', '?view=' + target.replace('panel-', ''));
    });
  });
})();

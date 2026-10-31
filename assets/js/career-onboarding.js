(function () {
  const overlay = document.getElementById('cpModalOverlay');
  if (!overlay) return;

  const stepChoose   = overlay.querySelector('[data-step="choose"]');
  const stepBuilding  = overlay.querySelector('[data-step="building"]');
  const stepReady     = overlay.querySelector('[data-step="ready"]');
  const buildingItems = overlay.querySelectorAll('.cp-building-list li');
  const skipBtn       = document.getElementById('cpSkipForNow');
  const closeBtn      = document.getElementById('cpModalClose') || document.getElementById('cpModalSkip');
  const startBtn      = document.getElementById('cpStartLearning');

  let selectedId = null;

  function openModal() {
    overlay.hidden = false;
    requestAnimationFrame(() => overlay.classList.add('is-visible'));
  }
  function closeModal() {
    overlay.classList.remove('is-visible');
    setTimeout(() => { overlay.hidden = true; }, 200);
  }
  window.cpOpenModal = openModal;
  window.cpCloseModal = closeModal;

  overlay.querySelectorAll('.cp-card').forEach(card => {
    card.addEventListener('click', () => {
      overlay.querySelectorAll('.cp-card').forEach(c => c.classList.remove('is-selected'));
      card.classList.add('is-selected');
      selectedId = card.dataset.id;
      runSelection(selectedId);
    });
  });

  if (skipBtn) {
    skipBtn.addEventListener('click', () => {
      fetch(window.SITE_URL + '/api/career-path-select.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=skip',
      }).finally(closeModal);
    });
  }
  if (closeBtn) closeBtn.addEventListener('click', closeModal);

  function runSelection(careerPathId) {
    stepChoose.hidden = true;
    stepBuilding.hidden = false;
    stepReady.hidden = true;
    buildingItems.forEach(li => li.classList.remove('is-active', 'is-done'));

    const stepDelay = 550;
    buildingItems.forEach((li, i) => {
      setTimeout(() => {
        buildingItems.forEach(l => l.classList.remove('is-active'));
        li.classList.add('is-active');
        if (i > 0) buildingItems[i - 1].classList.remove('is-active');
        if (i > 0) buildingItems[i - 1].classList.add('is-done');
        if (i === buildingItems.length - 1) {
          setTimeout(() => li.classList.add('is-done'), stepDelay - 100);
        }
      }, i * stepDelay);
    });

    const totalDelay = buildingItems.length * stepDelay + 300;

    const requestPromise = fetch(window.SITE_URL + '/api/career-path-select.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'action=select&career_path_id=' + encodeURIComponent(careerPathId),
    }).then(r => r.json());

    Promise.all([
      requestPromise,
      new Promise(resolve => setTimeout(resolve, totalDelay)),
    ]).then(([data]) => {
      if (!data || !data.success) {
        alert((data && data.error) || 'Something went wrong. Please try again.');
        stepChoose.hidden = false;
        stepBuilding.hidden = true;
        return;
      }
      stepBuilding.hidden = true;
      stepReady.hidden = false;
    });
  }

  if (startBtn) {
    startBtn.addEventListener('click', () => {
      window.location.reload();
    });
  }

  // Auto-open on first Learn visit
  if (overlay.dataset.autoOpen === '1') {
    openModal();
  }

  // Any element with class="cp-trigger-open" reopens the modal at step 1
  // (Change Path button, Choose Your Path empty-state button, etc.)
  document.querySelectorAll('.cp-trigger-open').forEach(btn => {
    btn.addEventListener('click', () => {
      stepChoose.hidden = false;
      stepBuilding.hidden = true;
      stepReady.hidden = true;
      overlay.querySelectorAll('.cp-card').forEach(c => c.classList.remove('is-selected'));
      openModal();
    });
  });
})();

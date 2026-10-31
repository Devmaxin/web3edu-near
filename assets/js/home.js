// Rotating hero headline — smooth cross-fade between admin-managed phrases
(function () {
  const items = document.querySelectorAll('.hero-rotator-item');
  if (items.length < 2) return;
  let i = 0;
  setInterval(() => {
    items[i].classList.remove('active');
    i = (i + 1) % items.length;
    items[i].classList.add('active');
  }, 3200);
})();

// Scroll reveal is handled sitewide by assets/js/main.js — not duplicated here.

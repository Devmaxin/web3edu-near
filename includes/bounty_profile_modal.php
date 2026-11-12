<?php
/**
 * Include on every logged-in bounty-section page (bounty.php, bounty-detail.php,
 * bounty-submit.php, bounty-host.php, bounty-payment.php, bounty-host-dashboard.php).
 * Requires includes/bounties.php and includes/countries.php already loaded.
 *
 * REBUILT FROM SCRATCH — true single-step-at-a-time onboarding wizard.
 * Each step is its own container. Only the current step's container exists
 * in a visible state at any time — the other two are set to display:none
 * both inline (on page load) and via JS (on navigation), so there is no
 * dependency on an external stylesheet loading/caching correctly.
 *
 * All CSS for this component is scoped under the .bpw- prefix and lives in
 * the <style> block below, so it can never collide with (or be shadowed by)
 * any other modal/wizard styling on the site.
 *
 * Backend contract preserved exactly as-is:
 *   - endpoint: api/save_bounty_profile.php (POST, FormData)
 *   - field names: display_name, bio, country, twitter, discord, telegram,
 *     github, portfolio_url, solana_wallet, ethereum_wallet, ton_wallet,
 *     bank_name, account_number
 *   - server-side required fields: display_name, country, twitter
 */
$needsBountyProfile = isLoggedIn() && !hasBountyProfile((int)$_SESSION['user_id']);
if ($needsBountyProfile):
?>
<style>
.bpw-overlay {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(10,7,20,0.7); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  padding: var(--space-4, 16px);
}
.bpw-box {
  background: var(--bg-elevated, #16121f);
  border: 1px solid var(--border, #2a2438);
  border-radius: var(--radius-lg, 16px);
  box-shadow: var(--shadow-raised, 0 20px 60px rgba(0,0,0,0.5));
  width: 100%; max-width: 520px;
  max-height: 90vh;
  display: flex; flex-direction: column;
  overflow: hidden;
}
#bpwForm { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
.bpw-head { padding: var(--space-6, 24px) var(--space-6, 24px) 0; flex-shrink: 0; }
.bpw-head h2 { margin: 0 0 4px; font-size: var(--text-lg, 1.15rem); color: var(--text-primary, #fff); }
.bpw-sub { margin: 0 0 var(--space-4, 16px); font-size: var(--text-sm, 0.85rem); color: var(--text-secondary, #a6a0b8); line-height: 1.4; }

/* ---- Progress bar ---- */
.bpw-progress { padding: 0 var(--space-6, 24px); flex-shrink: 0; }
.bpw-track { display: flex; align-items: center; }
.bpw-dot {
  width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0;
  background: var(--surface-raised, #241e33); border: 2px solid var(--border, #3a3350);
  display: flex; align-items: center; justify-content: center;
  transition: all .25s ease;
  position: relative;
}
.bpw-dot::after {
  content: ''; position: absolute; inset: -6px; border-radius: 50%;
  border: 2px solid var(--accent-bright, #0B7958); opacity: 0; transition: opacity .25s ease;
}
.bpw-dot.is-current { background: var(--accent-gradient, linear-gradient(135deg,#0B7958,#075B43)); border-color: transparent; }
.bpw-dot.is-current::after { opacity: 1; }
.bpw-dot.is-done { background: var(--success, #0B7958); border-color: transparent; }
.bpw-dot svg { width: 8px; height: 8px; color: #fff; }
.bpw-line { flex: 1; height: 2px; background: var(--border, #3a3350); margin: 0 6px; transition: background .25s ease; }
.bpw-line.is-done { background: var(--success, #0B7958); }
.bpw-progress-label {
  margin: 10px 0 var(--space-5, 20px); text-align: center;
  font-size: var(--text-xs, 0.75rem); font-weight: 700; letter-spacing: .02em;
  color: var(--text-primary, #fff); text-transform: uppercase;
}

.bpw-error {
  margin: 0 var(--space-6, 24px) var(--space-4, 16px);
  padding: 10px 14px; border-radius: var(--radius-md, 10px);
  border: 1px solid var(--danger, #f43f5e); background: var(--danger-soft, rgba(244,63,94,.1));
  color: var(--danger, #f43f5e); font-size: var(--text-sm, 0.85rem);
  display: none; flex-shrink: 0;
}

/* ---- Scrollable body: exactly one step container visible at a time ---- */
.bpw-body { padding: 0 var(--space-6, 24px); overflow-y: auto; flex: 1 1 auto; min-height: 0; }
.bpw-step { }
.bpw-field { margin-bottom: 16px; }
.bpw-field label {
  display: block; margin-bottom: 6px; font-size: 0.82rem; font-weight: 600; color: var(--text-secondary, #a6a0b8);
}
.bpw-field label .bpw-opt { font-weight: 400; color: var(--text-muted, #6b6480); }
.bpw-field input, .bpw-field select, .bpw-field textarea {
  width: 100%; box-sizing: border-box;
  background: var(--surface-raised, #1e1929); border: 1px solid var(--border, #3a3350);
  border-radius: var(--radius-md, 10px); padding: 10px 12px; font-size: 0.9rem;
  color: var(--text-primary, #fff); font-family: inherit; transition: border-color .15s ease;
}
.bpw-field select { appearance: none; cursor: pointer; }
.bpw-field textarea { resize: none; }
.bpw-field input:focus, .bpw-field select:focus, .bpw-field textarea:focus {
  outline: none; border-color: var(--accent-bright, #0B7958);
}
.bpw-field input.bpw-invalid, .bpw-field select.bpw-invalid { border-color: var(--danger, #f43f5e); }
.bpw-field-err { display: none; color: var(--danger, #f43f5e); font-size: 0.75rem; margin-top: 4px; }
.bpw-field-err.is-shown { display: block; }

.bpw-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.bpw-section-title { font-size: 0.85rem; font-weight: 700; color: var(--text-primary, #fff); margin-bottom: 2px; }
.bpw-section-hint { font-size: 0.75rem; color: var(--text-muted, #6b6480); margin: 0 0 12px; }
.bpw-divider { height: 1px; background: var(--border, #3a3350); margin: 18px 0; }

/* ---- Footer / nav ---- */
.bpw-foot { display: flex; gap: 10px; padding: var(--space-5, 20px) var(--space-6, 24px); flex-shrink: 0; }
.bpw-btn {
  flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 11px 16px; border-radius: var(--radius-md, 10px); font-size: 0.88rem; font-weight: 700;
  cursor: pointer; border: 1px solid transparent; transition: filter .15s ease, transform .1s ease;
}
.bpw-btn:active { transform: scale(0.98); }
.bpw-btn-primary { background: var(--accent-gradient, linear-gradient(135deg,#0B7958,#075B43)); color: #fff; }
.bpw-btn-primary:hover { filter: brightness(1.08); }
.bpw-btn-primary:disabled { opacity: .6; cursor: not-allowed; }
.bpw-btn-secondary { background: transparent; border-color: var(--border, #3a3350); color: var(--text-secondary, #a6a0b8); }
.bpw-btn-secondary:hover { border-color: var(--border-strong, #4a4260); color: var(--text-primary, #fff); }
.bpw-btn-hidden { visibility: hidden; }

@media (max-width: 480px) {
  .bpw-box { max-height: 92vh; }
  .bpw-head, .bpw-progress, .bpw-error, .bpw-body, .bpw-foot { padding-left: var(--space-4, 16px); padding-right: var(--space-4, 16px); }
  .bpw-grid2 { grid-template-columns: 1fr; }
  .bpw-progress-label { font-size: 0.7rem; }
}
</style>

<div class="bpw-overlay" id="bpwOverlay">
  <div class="bpw-box" role="dialog" aria-modal="true" aria-labelledby="bpwTitle">

    <div class="bpw-head">
      <h2 id="bpwTitle">Complete your Bounty Profile</h2>
      <p class="bpw-sub">A few quick steps before you dive into bounties — this is separate from your regular Web3Edu profile and is what hosts see on your submissions and winnings.</p>
    </div>

    <div class="bpw-progress">
      <div class="bpw-track" id="bpwTrack">
        <div class="bpw-dot is-current" data-dot="1"></div>
        <div class="bpw-line" data-line="1"></div>
        <div class="bpw-dot" data-dot="2"></div>
        <div class="bpw-line" data-line="2"></div>
        <div class="bpw-dot" data-dot="3"></div>
      </div>
      <p class="bpw-progress-label" id="bpwProgressLabel">Basic Info</p>
    </div>

    <div class="bpw-error" id="bpwError"></div>

    <form id="bpwForm" novalidate>
      <?= csrfField() ?>
      <div class="bpw-body">

        <!-- STEP 1 : Basic Info — its own container, nothing else exists here -->
        <div class="bpw-step" data-step="1" style="display:block">
          <div class="bpw-field">
            <label for="bpwDisplayName">Display Name *</label>
            <input type="text" id="bpwDisplayName" name="display_name" maxlength="100" autocomplete="off">
            <div class="bpw-field-err" data-err-for="display_name">Please enter a display name.</div>
          </div>
          <div class="bpw-field">
            <label for="bpwBio">Bio <span class="bpw-opt">(optional)</span></label>
            <textarea id="bpwBio" name="bio" rows="2" maxlength="500"></textarea>
          </div>
          <div class="bpw-field">
            <label for="bpwCountry">Country *</label>
            <select id="bpwCountry" name="country">
              <option value="">— select —</option>
              <?php foreach (bountyCountryList() as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="bpw-field-err" data-err-for="country">Please select your country.</div>
          </div>
        </div>

        <!-- STEP 2 : Social Profiles — separate container, does not exist alongside step 1 or 3 -->
        <div class="bpw-step" data-step="2" style="display:none">
          <div class="bpw-field">
            <label for="bpwTwitter">Twitter / X *</label>
            <input type="text" id="bpwTwitter" name="twitter" placeholder="@yourhandle" autocomplete="off">
            <div class="bpw-field-err" data-err-for="twitter">Please add your Twitter / X handle.</div>
          </div>
          <div class="bpw-grid2">
            <div class="bpw-field">
              <label for="bpwDiscord">Discord <span class="bpw-opt">(optional)</span></label>
              <input type="text" id="bpwDiscord" name="discord">
            </div>
            <div class="bpw-field">
              <label for="bpwTelegram">Telegram <span class="bpw-opt">(optional)</span></label>
              <input type="text" id="bpwTelegram" name="telegram">
            </div>
            <div class="bpw-field">
              <label for="bpwGithub">GitHub <span class="bpw-opt">(optional)</span></label>
              <input type="text" id="bpwGithub" name="github">
            </div>
            <div class="bpw-field">
              <label for="bpwPortfolio">Portfolio Website <span class="bpw-opt">(optional)</span></label>
              <input type="text" id="bpwPortfolio" name="portfolio_url" placeholder="https://">
            </div>
          </div>
        </div>

        <!-- STEP 3 : Wallets & Payment — separate container, does not exist alongside step 1 or 2 -->
        <div class="bpw-step" data-step="3" style="display:none">
          <div class="bpw-section-title">Wallets</div>
          <p class="bpw-section-hint">All optional — only needed if you win a crypto bounty.</p>
          <div class="bpw-grid2">
            <div class="bpw-field"><label for="bpwSolana">Solana Wallet</label><input type="text" id="bpwSolana" name="solana_wallet"></div>
            <div class="bpw-field"><label for="bpwEthereum">Ethereum Wallet</label><input type="text" id="bpwEthereum" name="ethereum_wallet"></div>
            <div class="bpw-field"><label for="bpwTon">TON Wallet</label><input type="text" id="bpwTon" name="ton_wallet"></div>
          </div>

          <!-- Nigeria-only fields: rendered here but hidden unless country === Nigeria -->
          <div id="bpwNigeriaFields" style="display:none">
            <div class="bpw-divider"></div>
            <div class="bpw-section-title">Nigerian Users <span class="bpw-opt">(Optional)</span></div>
            <p class="bpw-section-hint">Only needed for Naira payouts.</p>
            <div class="bpw-grid2">
              <div class="bpw-field"><label for="bpwBank">Nigerian Bank</label><input type="text" id="bpwBank" name="bank_name"></div>
              <div class="bpw-field"><label for="bpwAccount">Account Number</label><input type="text" id="bpwAccount" name="account_number"></div>
            </div>
          </div>
        </div>

      </div>

      <div class="bpw-foot">
        <button type="button" class="bpw-btn bpw-btn-secondary bpw-btn-hidden" id="bpwBack"><?= lucide('chevron-left', ['size' => 14]) ?> Back</button>
        <button type="button" class="bpw-btn bpw-btn-primary" id="bpwNext">Next <?= lucide('chevron-right', ['size' => 14]) ?></button>
        <button type="submit" class="bpw-btn bpw-btn-primary" id="bpwSubmit" style="display:none"><?= lucide('check', ['size' => 14]) ?> Submit Profile</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var CHECK_SVG = '<?= addslashes(lucide('check', ['size' => 8])) ?>';
  var STEP_LABELS = { 1: 'Basic Info', 2: 'Social Profiles', 3: 'Wallets' };

  // Fields required to leave a given step (server also enforces display_name/country/twitter).
  var REQUIRED_BY_STEP = {
    1: ['display_name', 'country'],
    2: ['twitter'],
    3: []
  };

  var current = 1;
  var TOTAL = 3;

  var form      = document.getElementById('bpwForm');
  var steps     = form.querySelectorAll('.bpw-step');
  var dots      = document.querySelectorAll('.bpw-dot');
  var lines     = document.querySelectorAll('.bpw-line');
  var label     = document.getElementById('bpwProgressLabel');
  var backBtn   = document.getElementById('bpwBack');
  var nextBtn   = document.getElementById('bpwNext');
  var submitBtn = document.getElementById('bpwSubmit');
  var errBox    = document.getElementById('bpwError');
  var countryEl = document.getElementById('bpwCountry');
  var nigeriaBox = document.getElementById('bpwNigeriaFields');

  function fieldEl(name) { return form.querySelector('[name="' + name + '"]'); }

  function showStep(n) {
    // Only the target step container is ever visible — the rest are display:none.
    steps.forEach(function (el) {
      el.style.display = (parseInt(el.dataset.step, 10) === n) ? 'block' : 'none';
    });

    dots.forEach(function (d) {
      var i = parseInt(d.dataset.dot, 10);
      d.classList.remove('is-current', 'is-done');
      if (i < n) { d.classList.add('is-done'); d.innerHTML = CHECK_SVG; }
      else if (i === n) { d.classList.add('is-current'); d.innerHTML = ''; }
      else { d.innerHTML = ''; }
    });
    lines.forEach(function (l) {
      var i = parseInt(l.dataset.line, 10);
      l.classList.toggle('is-done', i < n);
    });
    label.textContent = STEP_LABELS[n];

    backBtn.classList.toggle('bpw-btn-hidden', n === 1);
    nextBtn.style.display   = (n === TOTAL) ? 'none' : 'flex';
    submitBtn.style.display = (n === TOTAL) ? 'flex' : 'none';

    if (n === 3) syncNigeriaFields();
    errBox.style.display = 'none';
    current = n;
  }

  function syncNigeriaFields() {
    var isNigeria = countryEl && countryEl.value === 'Nigeria';
    nigeriaBox.style.display = isNigeria ? 'block' : 'none';
  }
  if (countryEl) countryEl.addEventListener('change', syncNigeriaFields);

  function validateStep(n) {
    var required = REQUIRED_BY_STEP[n] || [];
    var valid = true;
    required.forEach(function (name) {
      var el = fieldEl(name);
      var errEl = form.querySelector('[data-err-for="' + name + '"]');
      var empty = !el || !el.value.trim();
      if (el) el.classList.toggle('bpw-invalid', empty);
      if (errEl) errEl.classList.toggle('is-shown', empty);
      if (empty) valid = false;
    });
    return valid;
  }

  nextBtn.addEventListener('click', function () {
    if (!validateStep(current)) return;
    if (current < TOTAL) showStep(current + 1);
  });

  backBtn.addEventListener('click', function () {
    if (current > 1) showStep(current - 1);
  });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    // Re-check every step's required fields before sending, regardless of how the
    // user navigated here.
    if (!validateStep(1)) { showStep(1); return; }
    if (!validateStep(2)) { showStep(2); return; }
    if (!validateStep(3)) { showStep(3); return; }

    errBox.style.display = 'none';
    var original = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';

    try {
      var res = await fetch('<?= SITE_URL ?>/api/save_bounty_profile.php', {
        method: 'POST',
        body: new FormData(form)
      });
      var data = await res.json();
      if (data.success) {
        document.getElementById('bpwOverlay').remove();
      } else {
        errBox.textContent = data.error || 'Please fill in the required fields.';
        errBox.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = original;
      }
    } catch (err) {
      errBox.textContent = 'Something went wrong. Please try again.';
      errBox.style.display = 'block';
      submitBtn.disabled = false;
      submitBtn.innerHTML = original;
    }
  });

  showStep(1);
})();
</script>
<?php endif; ?>

<?php
// ============================================================
// admin/maintenance.php  —  Admin Maintenance Mode Control
// ============================================================
$adminTitle = 'Maintenance Mode';

// Auth + DB must be available before we do any POST handling, since the
// toggle action needs to redirect (header()) — and that must happen
// BEFORE admin/header.php prints any HTML, or PHP will throw
// "Cannot modify header information - headers already sent".
require_once __DIR__ . '/auth.php';
requireAdmin();

$db     = getDB();
$errors = [];
$notice = null;

// ── CSRF token ────────────────────────────────────────────────────
// Simple per-session token to protect all POST actions on this page.
if (empty($_SESSION['_maint_csrf'])) {
    $_SESSION['_maint_csrf'] = bin2hex(random_bytes(24));
}
$csrf = $_SESSION['_maint_csrf'];

function verifyCsrf(string $token): void {
    if (!hash_equals($_SESSION['_maint_csrf'] ?? '', $token)) {
        http_response_code(403);
        die('CSRF token mismatch. Go back and try again.');
    }
}

// ── HELPER: upsert a site_settings key ───────────────────────────
function upsertSetting(PDO $db, string $key, string $val): void {
    $stmt = $db->prepare("
        INSERT INTO site_settings (setting_key, setting_val)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_at = NOW()
    ");
    $stmt->execute([$key, $val]);
}

// ═══════════════════════════════════════════════════════════════════
// POST HANDLERS (must run before header.php prints any output)
// ═══════════════════════════════════════════════════════════════════

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Toggle maintenance mode ──────────────────────────────────
    if ($action === 'toggle_maintenance') {
        verifyCsrf($_POST['csrf'] ?? '');
        $newVal = ($_POST['maintenance_mode'] ?? '0') === '1' ? '1' : '0';
        upsertSetting($db, 'maintenance_mode', $newVal);
        // PRG: redirect after POST so the page reloads cleanly (avoids static getSetting() cache
        // showing the old value, and prevents form resubmission on browser refresh)
        $msg = urlencode($newVal === '1'
            ? 'Maintenance mode ENABLED — regular users will see the maintenance page.'
            : 'Maintenance mode DISABLED — site is live for all users.');
        header('Location: ' . SITE_URL . '/admin/maintenance.php?toggled=1&state=' . $newVal . '&msg=' . $msg);
        exit;
    }

    // ── Add exception user ────────────────────────────────────────
    elseif ($action === 'add_exception') {
        verifyCsrf($_POST['csrf'] ?? '');
        $input = trim($_POST['user_lookup'] ?? '');

        if ($input === '') {
            $errors[] = 'Please enter a User ID or email address.';
        } else {
            // Resolve to user_id — accept numeric ID or email
            if (ctype_digit($input)) {
                $stmt = $db->prepare("SELECT id, email, username FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$input]);
            } else {
                $stmt = $db->prepare("SELECT id, email, username FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$input]);
            }
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                $errors[] = 'No user found with that ID or email address.';
            } else {
                // Check not already in exceptions
                $chk = $db->prepare("SELECT COUNT(*) FROM maintenance_exceptions WHERE user_id = ?");
                $chk->execute([$targetUser['id']]);
                if ((int)$chk->fetchColumn() > 0) {
                    $errors[] = 'User <strong>' . htmlspecialchars($targetUser['email'], ENT_QUOTES) . '</strong> is already an exception.';
                } else {
                    $note = trim($_POST['note'] ?? '');
                    $ins  = $db->prepare("
                        INSERT INTO maintenance_exceptions (user_id, added_by, note)
                        VALUES (?, ?, ?)
                    ");
                    $ins->execute([
                        $targetUser['id'],
                        $_SESSION['admin_id'],
                        $note !== '' ? $note : null,
                    ]);
                    $notice = [
                        'type' => 'success',
                        'msg'  => '✅ User <strong>' . htmlspecialchars($targetUser['email'], ENT_QUOTES) . '</strong> has been added as a maintenance exception.'
                    ];
                }
            }
        }
    }

    // ── Remove exception user ────────────────────────────────────
    elseif ($action === 'remove_exception') {
        verifyCsrf($_POST['csrf'] ?? '');
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $del = $db->prepare("DELETE FROM maintenance_exceptions WHERE user_id = ?");
            $del->execute([$userId]);
            $notice = ['type' => 'success', 'msg' => '🗑️ Exception user removed.'];
        }
    }
}

// Now that any redirect has already happened (and exited), it's safe to
// bring in the shared admin chrome (prints <!DOCTYPE html> etc.).
require_once __DIR__ . '/header.php';

// ═══════════════════════════════════════════════════════════════════
// READ CURRENT STATE
// ═══════════════════════════════════════════════════════════════════

$maintenanceOn = getSetting('maintenance_mode', '0') === '1';

// Pick up success message from PRG redirect after toggle
if (isset($_GET['toggled']) && isset($_GET['msg'])) {
    $notice = [
        'type' => 'success',
        'msg'  => htmlspecialchars(urldecode($_GET['msg']), ENT_QUOTES, 'UTF-8'),
    ];
}

// Fetch exception users with their user info
try {
    $exceptionUsers = $db->query("
        SELECT me.id, me.user_id, me.note, me.created_at,
               u.email, u.username, u.membership_type, u.avatar
        FROM maintenance_exceptions me
        LEFT JOIN users u ON u.id = me.user_id
        ORDER BY me.created_at DESC
    ")->fetchAll();
} catch (\Throwable $e) {
    $exceptionUsers = [];
    $errors[] = 'Could not load exception users. Ensure migration_maintenance.sql has been run.';
}

$exceptionCount = count($exceptionUsers);
?>

<!-- ══════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:28px;flex-wrap:wrap;gap:16px">
    <div>
        <h1 class="admin-page-title" style="margin:0 0 6px">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 style="display:inline-block;vertical-align:middle;margin-right:8px;flex-shrink:0">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
            Maintenance Mode
        </h1>
        <p style="color:var(--text-muted);font-size:0.85rem;margin:0">
            Control site availability and manage tester / beta user exceptions.
        </p>
    </div>

    <!-- Live status pill -->
    <div style="flex-shrink:0;display:flex;align-items:center;gap:8px;
                padding:8px 18px;border-radius:20px;font-size:0.78rem;font-weight:700;
                <?= $maintenanceOn
                    ? 'background:rgba(239,68,68,0.12);border:1.5px solid rgba(239,68,68,0.3);color:#fca5a5'
                    : 'background:rgba(34,197,94,0.1);border:1.5px solid rgba(34,197,94,0.3);color:#86efac' ?>">
        <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;
                     animation:<?= $maintenanceOn ? 'blink' : 'none' ?> 1.4s step-start infinite;
                     background:<?= $maintenanceOn ? '#f87171' : '#4ade80' ?>"></span>
        <?= $maintenanceOn ? 'MAINTENANCE ON' : 'SITE LIVE' ?>
    </div>
</div>

<style>
@keyframes blink{0%,100%{opacity:1}50%{opacity:0.2}}
</style>

<!-- ── Alerts ─────────────────────────────────────────────────── -->
<?php if ($notice): ?>
<div style="background:<?= $notice['type']==='success'?'rgba(34,197,94,0.1)':'rgba(239,68,68,0.1)' ?>;
     border:1px solid <?= $notice['type']==='success'?'rgba(34,197,94,0.3)':'rgba(239,68,68,0.3)' ?>;
     color:<?= $notice['type']==='success'?'#86efac':'#fca5a5' ?>;
     border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:0.9rem">
    <?= $notice['msg'] ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;
     border-radius:var(--radius);padding:14px 18px;margin-bottom:24px;font-size:0.9rem">
    <?php foreach ($errors as $e): ?>
    <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:3px">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= $e ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- ══════════════════════════════════════════════════════════════
     SECTION A — MAINTENANCE TOGGLE
══════════════════════════════════════════════════════════════ -->
<div style="display:grid;gap:24px;max-width:900px">

<div style="background:var(--bg-card);border:1.5px solid <?= $maintenanceOn?'rgba(239,68,68,0.3)':'var(--border)' ?>;
            border-radius:var(--radius-lg);overflow:hidden">

    <!-- Card header -->
    <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                background:linear-gradient(90deg,rgba(<?= $maintenanceOn?'239,68,68':'139,92,246' ?>,0.08),transparent);
                display:flex;align-items:center;gap:14px">
        <div style="width:40px;height:40px;border-radius:11px;flex-shrink:0;
                    background:linear-gradient(135deg,<?= $maintenanceOn?'#ef4444,#dc2626':'var(--primary),var(--pink)' ?>);
                    display:flex;align-items:center;justify-content:center">
            <?php if ($maintenanceOn): ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect width="11" height="11" x="1" y="1" rx="2" ry="2"/>
                <path d="M12 1h6a2 2 0 0 1 2 2v6"/>
                <path d="M1 12v6a2 2 0 0 0 2 2h6"/>
                <path d="M8 21h8"/>
                <path d="M12 21v-8"/>
            </svg>
            <?php else: ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
            <?php endif; ?>
        </div>
        <div>
            <div style="font-weight:700;font-size:1rem">Maintenance Toggle</div>
            <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                <?= $maintenanceOn
                    ? 'Site is <strong style="color:#f87171">under maintenance</strong>. Only admins and exception users can access it.'
                    : 'Site is <strong style="color:#4ade80">live</strong>. All users can access it normally.' ?>
            </div>
        </div>
    </div>

    <!-- Card body -->
    <div style="padding:28px 24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap">

        <!-- Visual toggle switch (purely decorative — form buttons do the action) -->
        <div style="display:flex;align-items:center;gap:16px">
            <div style="position:relative;width:64px;height:34px">
                <div style="position:absolute;inset:0;border-radius:99px;
                            background:<?= $maintenanceOn?'linear-gradient(135deg,#ef4444,#dc2626)':'rgba(255,255,255,0.08)' ?>;
                            border:1.5px solid <?= $maintenanceOn?'rgba(239,68,68,0.4)':'var(--border)' ?>;
                            transition:all 0.3s">
                </div>
                <div style="position:absolute;top:4px;
                            left:<?= $maintenanceOn?'34px':'4px' ?>;
                            width:22px;height:22px;border-radius:50%;
                            background:#fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);
                            transition:left 0.3s">
                </div>
            </div>
            <div>
                <div style="font-weight:700;font-size:0.95rem">
                    <?= $maintenanceOn ? 'ON' : 'OFF' ?>
                </div>
                <div style="font-size:0.75rem;color:var(--text-muted)">Current state</div>
            </div>
        </div>

        <div style="flex:1;min-width:0"></div>

        <!-- Toggle action buttons -->
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <?php if (!$maintenanceOn): ?>
            <form method="POST" onsubmit="return confirm('Enable maintenance mode? Regular users will be blocked from the site.')">
                <input type="hidden" name="action" value="toggle_maintenance">
                <input type="hidden" name="maintenance_mode" value="1">
                <input type="hidden" name="csrf" value="<?= sanitize($csrf) ?>">
                <button type="submit" style="
                    padding:10px 22px;border-radius:10px;font-size:0.88rem;font-weight:700;
                    cursor:pointer;border:1.5px solid rgba(239,68,68,0.4);
                    background:rgba(239,68,68,0.12);color:#fca5a5;
                    transition:all 0.2s;display:flex;align-items:center;gap:8px"
                    onmouseover="this.style.background='rgba(239,68,68,0.25)';this.style.borderColor='rgba(239,68,68,0.6)'"
                    onmouseout="this.style.background='rgba(239,68,68,0.12)';this.style.borderColor='rgba(239,68,68,0.4)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18.36 6.64a9 9 0 1 1-12.73 0"/>
                        <line x1="12" y1="2" x2="12" y2="12"/>
                    </svg>
                    Enable Maintenance
                </button>
            </form>
            <?php else: ?>
            <form method="POST" onsubmit="return confirm('Disable maintenance mode? The site will become accessible to everyone.')">
                <input type="hidden" name="action" value="toggle_maintenance">
                <input type="hidden" name="maintenance_mode" value="0">
                <input type="hidden" name="csrf" value="<?= sanitize($csrf) ?>">
                <button type="submit" style="
                    padding:10px 22px;border-radius:10px;font-size:0.88rem;font-weight:700;
                    cursor:pointer;border:1.5px solid rgba(34,197,94,0.4);
                    background:rgba(34,197,94,0.1);color:#86efac;
                    transition:all 0.2s;display:flex;align-items:center;gap:8px"
                    onmouseover="this.style.background='rgba(34,197,94,0.22)';this.style.borderColor='rgba(34,197,94,0.6)'"
                    onmouseout="this.style.background='rgba(34,197,94,0.1)';this.style.borderColor='rgba(34,197,94,0.4)'">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Disable Maintenance
                </button>
            </form>

            <!-- Preview maintenance page button -->
            <a href="<?= SITE_URL ?>/maintenance.php" target="_blank"
               style="padding:10px 18px;border-radius:10px;font-size:0.82rem;font-weight:600;
                      border:1.5px solid var(--border);background:transparent;color:var(--text-muted);
                      text-decoration:none;display:flex;align-items:center;gap:7px;transition:all 0.2s"
               onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary-bright)'"
               onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-muted)'">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                Preview Page ↗
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($maintenanceOn && $exceptionCount > 0): ?>
    <div style="padding:12px 24px;border-top:1px solid rgba(239,68,68,0.15);
                background:rgba(239,68,68,0.04);font-size:0.78rem;color:var(--text-muted)">
        ℹ️ <strong style="color:var(--text)"><?= $exceptionCount ?> user<?= $exceptionCount===1?'':'s' ?></strong>
        can still access the site as exception<?= $exceptionCount===1?'':'s' ?>.
        Admins always bypass maintenance automatically.
    </div>
    <?php endif; ?>
</div>


<!-- ══════════════════════════════════════════════════════════════
     SECTION B — ADD EXCEPTION USER
══════════════════════════════════════════════════════════════ -->
<div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">

    <div style="padding:20px 24px;border-bottom:1px solid var(--border);
                background:linear-gradient(90deg,rgba(6,182,212,0.07),transparent);
                display:flex;align-items:center;gap:14px">
        <div style="width:40px;height:40px;border-radius:11px;flex-shrink:0;
                    background:linear-gradient(135deg,#0891b2,#0e7490);
                    display:flex;align-items:center;justify-content:center">
            <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24"
                 fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <line x1="19" y1="8" x2="19" y2="14"/>
                <line x1="22" y1="11" x2="16" y2="11"/>
            </svg>
        </div>
        <div>
            <div style="font-weight:700;font-size:1rem">Add Exception User</div>
            <div style="color:var(--text-muted);font-size:0.8rem;margin-top:2px">
                Grant a tester or beta user access during maintenance. Enter their User ID or email.
            </div>
        </div>
    </div>

    <div style="padding:24px">
        <form method="POST">
            <input type="hidden" name="action" value="add_exception">
            <input type="hidden" name="csrf"   value="<?= sanitize($csrf) ?>">

            <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;margin-bottom:12px">
                <div>
                    <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:7px;color:var(--text)">
                        User ID or Email Address
                    </label>
                    <input
                        type="text"
                        name="user_lookup"
                        placeholder="e.g. 42  or  tester@example.com"
                        autocomplete="off"
                        required
                        style="width:100%;padding:11px 16px;background:var(--bg-input);
                               border:1.5px solid var(--border);border-radius:var(--radius);
                               color:var(--text);font-size:0.92rem;outline:none;
                               transition:border-color 0.2s"
                        onfocus="this.style.borderColor='var(--primary)'"
                        onblur="this.style.borderColor='var(--border)'"
                    >
                </div>
                <button type="submit" class="btn-upgrade" style="padding:11px 20px;white-space:nowrap;height:44px;display:flex;align-items:center;gap:7px">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Add Exception
                </button>
            </div>

            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:7px;color:var(--text)">
                    Note <span style="font-weight:400;color:var(--text-muted)">(optional — e.g. "Beta tester", "QA team")</span>
                </label>
                <input
                    type="text"
                    name="note"
                    placeholder="Internal note about this exception..."
                    maxlength="255"
                    style="width:100%;padding:10px 16px;background:var(--bg-input);
                           border:1.5px solid var(--border);border-radius:var(--radius);
                           color:var(--text);font-size:0.9rem;outline:none;
                           transition:border-color 0.2s"
                    onfocus="this.style.borderColor='var(--primary)'"
                    onblur="this.style.borderColor='var(--border)'"
                >
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════
     SECTION C — EXCEPTION USERS LIST
══════════════════════════════════════════════════════════════ -->
<div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">

    <div style="padding:18px 24px;border-bottom:1px solid var(--border);
                display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="font-weight:700;font-size:1rem;display:flex;align-items:center;gap:10px">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Exception Users
        </div>
        <span style="padding:3px 12px;border-radius:20px;font-size:0.72rem;font-weight:700;
                     background:rgba(139,92,246,0.12);border:1px solid rgba(139,92,246,0.25);color:var(--primary-bright)">
            <?= $exceptionCount ?> user<?= $exceptionCount === 1 ? '' : 's' ?>
        </span>
    </div>

    <?php if ($exceptionCount === 0): ?>
    <div style="padding:48px 24px;text-align:center;color:var(--text-muted)">
        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
             style="margin-bottom:12px;opacity:0.3">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <line x1="19" y1="8" x2="19" y2="14"/>
            <line x1="22" y1="11" x2="16" y2="11"/>
        </svg>
        <div style="font-size:0.9rem">No exception users yet.</div>
        <div style="font-size:0.78rem;margin-top:4px;opacity:0.7">
            Add testers or beta users above to grant them access during maintenance.
        </div>
    </div>

    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Plan</th>
                <th>Note</th>
                <th>Added</th>
                <th style="text-align:right">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($exceptionUsers as $ex): ?>
        <tr>
            <!-- User info -->
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <img src="<?= avatarUrl($ex['avatar'] ?? 'cyber_01.svg') ?>"
                         style="width:34px;height:34px;border-radius:50%;object-fit:cover;
                                border:1.5px solid var(--border);flex-shrink:0"
                         onerror="this.style.display='none'">
                    <div>
                        <div style="font-size:0.88rem;font-weight:500"><?= sanitize($ex['email'] ?? '—') ?></div>
                        <div style="font-size:0.72rem;color:var(--text-muted)">
                            @<?= sanitize($ex['username'] ?? '') ?>
                            &nbsp;·&nbsp; ID&nbsp;<?= (int)$ex['user_id'] ?>
                        </div>
                    </div>
                </div>
            </td>

            <!-- Membership badge -->
            <td>
                <span style="padding:3px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;
                    <?= ($ex['membership_type'] ?? '') === 'premium'
                        ? 'background:rgba(168,85,247,0.15);color:#d8b4fe'
                        : 'background:rgba(255,255,255,0.05);color:var(--text-muted)' ?>">
                    <?= ucfirst($ex['membership_type'] ?? 'free') ?>
                </span>
            </td>

            <!-- Note -->
            <td style="color:var(--text-muted);font-size:0.82rem;max-width:200px">
                <?= $ex['note'] ? sanitize($ex['note']) : '<em style="opacity:0.45">no note</em>' ?>
            </td>

            <!-- Date -->
            <td style="color:var(--text-muted);font-size:0.78rem;white-space:nowrap">
                <?= date('M j, Y', strtotime($ex['created_at'])) ?>
            </td>

            <!-- Remove button -->
            <td style="text-align:right">
                <form method="POST" onsubmit="return confirm('Remove this user from exceptions?')">
                    <input type="hidden" name="action"  value="remove_exception">
                    <input type="hidden" name="user_id" value="<?= (int)$ex['user_id'] ?>">
                    <input type="hidden" name="csrf"    value="<?= sanitize($csrf) ?>">
                    <button type="submit"
                            style="padding:5px 12px;border-radius:8px;font-size:0.75rem;font-weight:600;
                                   cursor:pointer;border:1.5px solid rgba(239,68,68,0.3);
                                   background:rgba(239,68,68,0.08);color:#fca5a5;
                                   transition:all 0.2s;display:inline-flex;align-items:center;gap:5px"
                            onmouseover="this.style.background='rgba(239,68,68,0.2)';this.style.borderColor='rgba(239,68,68,0.5)'"
                            onmouseout="this.style.background='rgba(239,68,68,0.08)';this.style.borderColor='rgba(239,68,68,0.3)'">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/>
                            <path d="M10 11v6"/><path d="M14 11v6"/>
                            <path d="M9 6V4h6v2"/>
                        </svg>
                        Remove
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════
     SECTION D — HOW IT WORKS (info box)
══════════════════════════════════════════════════════════════ -->
<div style="background:rgba(139,92,246,0.05);border:1.5px solid rgba(139,92,246,0.18);
            border-radius:var(--radius-lg);padding:22px 24px">
    <div style="font-weight:700;font-size:0.9rem;color:var(--primary-bright);
                margin-bottom:14px;display:flex;align-items:center;gap:8px">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        How the System Works
    </div>
    <div style="display:grid;gap:10px;font-size:0.83rem;color:var(--text-muted);line-height:1.65">
        <div style="display:flex;gap:10px">
            <span style="color:#4ade80;flex-shrink:0;font-weight:700">✓</span>
            <span><strong style="color:var(--text)">Maintenance OFF</strong> — all users access the site normally.</span>
        </div>
        <div style="display:flex;gap:10px">
            <span style="color:#f87171;flex-shrink:0;font-weight:700">⚙</span>
            <span><strong style="color:var(--text)">Maintenance ON + Admin session</strong> — admin always bypasses. No redirect.</span>
        </div>
        <div style="display:flex;gap:10px">
            <span style="color:#60a5fa;flex-shrink:0;font-weight:700">🔑</span>
            <span><strong style="color:var(--text)">Maintenance ON + Exception user</strong> — testers/beta users in the table above bypass the maintenance wall.</span>
        </div>
        <div style="display:flex;gap:10px">
            <span style="color:#f87171;flex-shrink:0;font-weight:700">🚫</span>
            <span><strong style="color:var(--text)">Maintenance ON + everyone else</strong> — immediately redirected to the maintenance page.</span>
        </div>
        <div style="display:flex;gap:10px">
            <span style="color:#fbbf24;flex-shrink:0;font-weight:700">📁</span>
            <span>The gate runs from <code style="background:rgba(255,255,255,0.07);padding:1px 6px;border-radius:4px">includes/check_maintenance.php</code>, included automatically via <code style="background:rgba(255,255,255,0.07);padding:1px 6px;border-radius:4px">includes/header.php</code>.</span>
        </div>
    </div>
</div>

</div><!-- /grid -->

<?php require_once __DIR__ . '/footer.php'; ?>

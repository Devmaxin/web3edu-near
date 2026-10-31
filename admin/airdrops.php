<?php
$adminTitle = 'Manage Airdrops';
require_once __DIR__ . '/header.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$errors  = [];
$success = '';

// ── Detect which columns exist (graceful if migration not yet run) ─────────────
function columnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
$hasSlug       = columnExists($db, 'airdrops', 'slug');
$hasProjectUrl = columnExists($db, 'airdrops', 'project_url');

// ── Auto-run migration if columns are missing ─────────────────────────────────
if (!$hasSlug || !$hasProjectUrl) {
    try {
        if (!$hasSlug) {
            $db->exec("ALTER TABLE airdrops ADD COLUMN slug VARCHAR(255) DEFAULT NULL AFTER title");
            $db->exec("UPDATE airdrops SET slug = LOWER(REPLACE(REPLACE(TRIM(title),' ','-'),'--','-')) WHERE slug IS NULL OR slug = ''");
            $hasSlug = true;
        }
        if (!$hasProjectUrl) {
            $db->exec("ALTER TABLE airdrops ADD COLUMN project_url TEXT DEFAULT NULL");
            $hasProjectUrl = true;
        }
    } catch (Throwable $e) {
        // Log silently — columns may already exist in a race condition
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $db->prepare("DELETE FROM airdrops WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/airdrops.php?notice=deleted'); exit;
}

// ── Save (POST) — PRG pattern: always redirect after save ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title'] ?? '');
    $slug_input   = trim($_POST['slug']  ?? '');
    $description  = trim($_POST['description'] ?? '');
    $full_guide   = trim($_POST['full_guide'] ?? '');
    $project_url  = trim($_POST['project_url'] ?? '');
    $reward       = trim($_POST['reward'] ?? '');
    $difficulty   = in_array($_POST['difficulty'] ?? '', ['Easy','Medium','Hard']) ? $_POST['difficulty'] : 'Easy';
    $deadline     = trim($_POST['deadline'] ?? '') ?: null;
    $status       = in_array($_POST['status'] ?? '', ['active','ended','upcoming']) ? $_POST['status'] : 'active';
    $access_level = in_array($_POST['access_level'] ?? '', ['public','premium']) ? $_POST['access_level'] : 'public';
    $is_featured  = isset($_POST['is_featured']) ? 1 : 0;

    if (empty($title))       $errors[] = 'Title is required.';
    if (empty($description)) $errors[] = 'Description is required.';
    if (!empty($project_url) && !filter_var($project_url, FILTER_VALIDATE_URL)) {
        $errors[] = 'Project URL must be a valid URL (include https://).';
    }

    // Generate/clean slug
    $slug = !empty($slug_input) ? generateSlug($slug_input) : generateSlug($title);

    if (empty($errors)) {
        // Unique slug check
        $baseSlug = $slug;
        $i = 2;
        while (true) {
            $sql  = "SELECT COUNT(*) FROM airdrops WHERE slug = ?" . ($id ? " AND id != $id" : "");
            $p    = [$slug];
            $stmt = $db->prepare($sql);
            $stmt->execute($p);
            if ((int)$stmt->fetchColumn() === 0) break;
            $slug = $baseSlug . '-' . $i++;
        }

        try {
            if ($id) {
                $db->prepare("UPDATE airdrops SET title=?,slug=?,description=?,full_guide=?,project_url=?,reward=?,difficulty=?,deadline=?,status=?,access_level=?,is_featured=? WHERE id=?")
                   ->execute([$title,$slug,$description,$full_guide,$project_url ?: null,$reward,$difficulty,$deadline,$status,$access_level,$is_featured,$id]);
                header('Location: ' . SITE_URL . '/admin/airdrops.php?action=edit&id=' . $id . '&notice=updated');
                exit;
            } else {
                $db->prepare("INSERT INTO airdrops (title,slug,description,full_guide,project_url,reward,difficulty,deadline,status,access_level,is_featured) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$title,$slug,$description,$full_guide,$project_url ?: null,$reward,$difficulty,$deadline,$status,$access_level,$is_featured]);
                $newId = $db->lastInsertId();
                header('Location: ' . SITE_URL . '/admin/airdrops.php?action=edit&id=' . $newId . '&notice=created');
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
    // If we get here there were validation errors — fall through to show form again
    // Rebuild $airdrop from POST so the form re-populates
    $airdrop = [
        'id' => $id, 'title' => $title, 'slug' => $slug,
        'description' => $description, 'full_guide' => $full_guide,
        'project_url' => $project_url, 'reward' => $reward,
        'difficulty' => $difficulty, 'deadline' => $deadline,
        'status' => $status, 'access_level' => $access_level,
        'is_featured' => $is_featured,
    ];
    $action = $id ? 'edit' : 'new';
} else {
    // ── Load for edit (GET) ───────────────────────────────────────────────────
    $airdrop = null;
    if (in_array($action, ['edit','new']) && $id) {
        $stmt = $db->prepare("SELECT * FROM airdrops WHERE id = ?");
        $stmt->execute([$id]);
        $airdrop = $stmt->fetch();
        if (!$airdrop) { header('Location: ' . SITE_URL . '/admin/airdrops.php'); exit; }
    }
}

// Pick up PRG notice from redirect
$notice = $_GET['notice'] ?? '';

$airdrops = $db->query("SELECT * FROM airdrops ORDER BY is_featured DESC, created_at DESC")->fetchAll();
?>

<?php if ($notice === 'updated'): ?>
<div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#86efac;font-size:0.875rem;display:flex;align-items:center;gap:8px">
  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  Airdrop updated successfully.
</div>
<?php elseif ($notice === 'created'): ?>
<div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#86efac;font-size:0.875rem;display:flex;align-items:center;gap:8px">
  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  Airdrop created successfully.
</div>
<?php elseif ($notice === 'deleted'): ?>
<div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#86efac;font-size:0.875rem;display:flex;align-items:center;gap:8px">
  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
  Airdrop deleted.
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius);padding:14px;margin-bottom:20px">
  <?php foreach ($errors as $e): ?>
  <div style="color:#fca5a5;font-size:0.84rem;display:flex;align-items:center;gap:7px">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= sanitize($e) ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
  <h1 class="admin-page-title" style="margin:0">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;margin-right:8px;vertical-align:middle;color:var(--primary-bright)"><path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h9"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
    Airdrops (<?= count($airdrops) ?>)
  </h1>
  <a href="?action=new" class="btn-primary">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Airdrop
  </a>
</div>

<div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
  <table class="admin-table">
    <thead><tr>
      <th>Title</th><th>Reward</th><th>Difficulty</th><th>Status</th><th>Access</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($airdrops as $a): ?>
    <tr>
      <td>
        <div style="font-weight:600;font-size:0.875rem"><?= sanitize($a['title']) ?></div>
        <?php if (!empty($a['slug'])): ?>
        <div style="font-size:0.72rem;font-family:var(--font-mono);color:var(--text-muted)"><?= sanitize($a['slug']) ?></div>
        <?php endif; ?>
        <?php if ($a['is_featured']): ?><div style="font-size:0.68rem;color:#fbbf24;margin-top:2px">★ Featured</div><?php endif; ?>
      </td>
      <td style="color:var(--primary-bright);font-size:0.82rem"><?= sanitize($a['reward']) ?></td>
      <td><span class="diff-<?= strtolower($a['difficulty']) ?>" style="font-size:0.82rem"><?= $a['difficulty'] ?></span></td>
      <td><span class="airdrop-status status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
      <td style="font-size:0.82rem;color:<?= $a['access_level']==='premium'?'#d8b4fe':'#86efac' ?>"><?= ucfirst($a['access_level']) ?></td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <a href="?action=edit&id=<?= $a['id'] ?>" class="btn-ghost btn-sm">Edit</a>
          <?php if (!empty($a['slug'])): ?>
          <a href="<?= SITE_URL ?>/airdrops/<?= urlencode($a['slug']) ?>" target="_blank" class="btn-secondary btn-sm">View</a>
          <?php endif; ?>
          <a href="?action=delete&id=<?= $a['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this airdrop?')">Del</a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($airdrops)): ?>
    <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--text-muted)">No airdrops yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php else: // ── Edit / New form ───────────────────────────────────────────────────────── ?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
  <a href="?action=list" style="color:var(--text-muted);font-size:0.875rem;display:flex;align-items:center;gap:5px">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Airdrops
  </a>
  <span style="color:var(--border)">/</span>
  <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Airdrop' : 'New Airdrop' ?></h1>
</div>

<form method="POST" action="?action=<?= $id ? 'edit&id='.$id : 'new' ?>" style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:flex-start">

  <!-- ── Main column ── -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:28px">
      <div class="form-group">
        <label class="form-label">Airdrop Title *</label>
        <input type="text" name="title" class="form-input" required
               value="<?= sanitize($airdrop['title'] ?? '') ?>"
               placeholder="e.g. LayerZero Airdrop"
               oninput="autoSlug(this.value)">
      </div>

      <?php if ($hasSlug): ?>
      <div class="form-group">
        <label class="form-label">
          Slug
          <span style="color:var(--text-muted);font-weight:400;font-size:0.75rem"> — auto-generated from title</span>
        </label>
        <input type="text" name="slug" id="slugField" class="form-input"
               value="<?= sanitize($airdrop['slug'] ?? '') ?>"
               placeholder="layerzero-airdrop">
        <div style="font-size:0.74rem;color:var(--text-muted);margin-top:5px">
          URL: <code style="color:var(--primary-bright)"><?= SITE_URL ?>/airdrops/<span id="slugPreview"><?= sanitize($airdrop['slug'] ?? 'your-slug') ?></span></code>
        </div>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Short Description *</label>
        <textarea name="description" class="form-textarea" rows="3" required><?= htmlspecialchars($airdrop['description'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">
          Full Guide Content
          <span style="color:var(--text-muted);font-weight:400;font-size:0.75rem"> — HTML supported</span>
        </label>
        <textarea name="full_guide" class="form-textarea" rows="14"><?= htmlspecialchars($airdrop['full_guide'] ?? '') ?></textarea>
      </div>
    </div>

    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:24px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:14px;display:flex;align-items:center;gap:7px">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
        Official Project Website
      </h3>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">Project URL</label>
        <input type="url" name="project_url" class="form-input"
               value="<?= sanitize($airdrop['project_url'] ?? '') ?>"
               placeholder="https://layerzero.network">
        <div style="font-size:0.74rem;color:var(--text-muted);margin-top:5px">
          Displayed as a "Visit Project ↗" button on the airdrop card and guide page. Must include https://
        </div>
      </div>
    </div>

  </div>

  <!-- ── Sidebar ── -->
  <div style="display:flex;flex-direction:column;gap:16px">
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:16px">Details</h3>
      <div class="form-group">
        <label class="form-label">Reward / Estimate</label>
        <input type="text" name="reward" class="form-input" value="<?= sanitize($airdrop['reward'] ?? '') ?>" placeholder="Est. $500–$5,000">
      </div>
      <div class="form-group">
        <label class="form-label">Difficulty</label>
        <select name="difficulty" class="form-select">
          <?php foreach (['Easy','Medium','Hard'] as $d): ?>
          <option <?= ($airdrop['difficulty'] ?? 'Easy') === $d ? 'selected' : '' ?>><?= $d ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Deadline</label>
        <input type="date" name="deadline" class="form-input" value="<?= $airdrop['deadline'] ?? '' ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach (['active','upcoming','ended'] as $s): ?>
          <option value="<?= $s ?>" <?= ($airdrop['status'] ?? 'active') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Guide Access</label>
        <select name="access_level" class="form-select">
          <option value="public"  <?= ($airdrop['access_level'] ?? 'public') === 'public'  ? 'selected' : '' ?>>🌐 Public (Free)</option>
          <option value="premium" <?= ($airdrop['access_level'] ?? 'public') === 'premium' ? 'selected' : '' ?>>⭐ Premium Only</option>
        </select>
      </div>
      <label style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg-card2);border-radius:var(--radius);cursor:pointer">
        <input type="checkbox" name="is_featured" <?= ($airdrop['is_featured'] ?? 0) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--primary)">
        <span style="font-size:0.875rem">⭐ Featured</span>
      </label>
    </div>

    <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:12px">
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      <?= $id ? 'Update Airdrop' : 'Create Airdrop' ?>
    </button>

    <?php if ($id && !empty($airdrop['slug'])): ?>
    <a href="<?= SITE_URL ?>/airdrops/<?= urlencode($airdrop['slug']) ?>" target="_blank"
       class="btn-secondary" style="width:100%;justify-content:center;padding:11px;display:flex;align-items:center;gap:7px">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      View Guide Page
    </a>
    <?php endif; ?>
  </div>

</form>

<script>
const slugField   = document.getElementById('slugField');
const slugPreview = document.getElementById('slugPreview');
let slugManual = <?= !empty($airdrop['slug']) ? 'true' : 'false' ?>;

if (slugField) {
  slugField.addEventListener('input', () => {
    slugManual = true;
    slugPreview.textContent = slugField.value || 'your-slug';
  });
}

function generateSlug(text) {
  return text.toLowerCase().replace(/[^a-z0-9\s-]/g,'').replace(/[\s]+/g,'-').replace(/-+/g,'-').replace(/^-|-$/g,'');
}
function autoSlug(title) {
  if (!slugManual && slugField) {
    const s = generateSlug(title);
    slugField.value = s;
    if (slugPreview) slugPreview.textContent = s || 'your-slug';
  }
}
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>

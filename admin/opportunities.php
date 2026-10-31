<?php
$adminTitle = 'Opportunities';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/opportunities.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$msg    = '';
$err    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save') {
        $editId = intval($_POST['id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $cat    = array_key_exists($_POST['category'] ?? '', OPPORTUNITY_CATEGORIES) ? $_POST['category'] : null;
        $link   = trim($_POST['official_link'] ?? '');

        if ($title === '' || !$cat || $link === '') {
            $err = 'Title, Category, and Official Link are required.';
            $action = 'edit';
        } else {
            $fields = [
                $title, trim($_POST['organization'] ?? ''), $cat,
                in_array($_POST['difficulty'] ?? '', ['Beginner','Intermediate','Advanced'], true) ? $_POST['difficulty'] : 'Beginner',
                trim($_POST['location'] ?? ''),
                in_array($_POST['work_mode'] ?? '', ['remote','hybrid','onsite'], true) ? $_POST['work_mode'] : 'remote',
                trim($_POST['description'] ?? ''), trim($_POST['requirements'] ?? ''),
                $_POST['deadline'] ?: null, trim($_POST['reward'] ?? ''), $link,
                trim($_POST['tags'] ?? ''), isset($_POST['is_featured']) ? 1 : 0,
                in_array($_POST['status'] ?? '', ['active','archived'], true) ? $_POST['status'] : 'active',
            ];
            if ($editId) {
                $db->prepare("
                    UPDATE opportunities SET title=?, organization=?, category=?, difficulty=?, location=?, work_mode=?,
                        description=?, requirements=?, deadline=?, reward=?, official_link=?, tags=?, is_featured=?, status=?
                    WHERE id=?
                ")->execute([...$fields, $editId]);
                $id = $editId;
                $msg = 'Opportunity updated.';
            } else {
                $db->prepare("
                    INSERT INTO opportunities (title, organization, category, difficulty, location, work_mode,
                        description, requirements, deadline, reward, official_link, tags, is_featured, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute($fields);
                $id = (int)$db->lastInsertId();
                $msg = 'Opportunity created.';
            }
            $action = 'edit';
        }
    }

    if ($postAction === 'toggle_featured') {
        $db->prepare("UPDATE opportunities SET is_featured = 1 - is_featured WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?action=list'); exit;
    }

    if ($postAction === 'archive') {
        $db->prepare("UPDATE opportunities SET status = IF(status='archived','active','archived') WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?action=list'); exit;
    }

    if ($postAction === 'delete') {
        $db->prepare("UPDATE opportunities SET deleted_at = NOW() WHERE id = ?")->execute([intval($_POST['id'] ?? 0)]);
        header('Location: ?action=list&deleted=1'); exit;
    }

    if ($postAction === 'link_career_path') {
        $cpId = intval($_POST['career_path_id'] ?? 0);
        if ($cpId) linkOpportunityToCareerPath($id, $cpId);
        $action = 'edit';
    }

    if ($postAction === 'unlink_career_path') {
        unlinkOpportunityFromCareerPath(intval($_POST['link_id'] ?? 0));
        $action = 'edit';
    }
}

if ($action === 'edit') {
    $opportunity = $id ? getOpportunity($id) : null;
    if ($id) {
        $linkedPaths = getLinkedCareerPaths($id);
        $allCareerPaths = $db->query("SELECT id, name FROM career_paths WHERE is_active=1 ORDER BY name ASC")->fetchAll();
    }
}
if ($action === 'list') {
    $filterCategory = $_GET['category'] ?? '';
    $allOpps = getAllOpportunitiesAdmin();
    if ($filterCategory) $allOpps = array_values(array_filter($allOpps, fn($o) => $o['category'] === $filterCategory));
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Opportunity deleted.</div><?php endif; ?>
<?php if ($msg): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Opportunities <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(<?= count($allOpps) ?>)</span></h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Opportunity</a>
</div>

<div style="display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap">
    <a href="?action=list" class="btn-<?= !$filterCategory?'primary':'ghost' ?> btn-sm">All</a>
    <?php foreach (OPPORTUNITY_CATEGORIES as $k=>$label): ?>
    <a href="?action=list&category=<?= $k ?>" class="btn-<?= $filterCategory===$k?'primary':'ghost' ?> btn-sm"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Title</th><th>Category</th><th>Deadline</th><th>Career Paths</th><th>Featured</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($allOpps as $o): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($o['title']) ?><?php if($o['organization']): ?><div style="font-size:0.72rem;color:var(--text-muted);font-weight:400"><?= sanitize($o['organization']) ?></div><?php endif; ?></td>
    <td><?= sanitize(OPPORTUNITY_CATEGORIES[$o['category']] ?? $o['category']) ?></td>
    <td style="font-size:0.8rem;color:var(--text-muted)"><?= $o['deadline'] ? date('M j, Y', strtotime($o['deadline'])) : '—' ?></td>
    <td style="text-align:center"><?= (int)$o['career_path_count'] ?></td>
    <td style="text-align:center"><?= $o['is_featured'] ? '⭐' : '—' ?></td>
    <td>
        <span style="padding:3px 8px;border-radius:20px;font-size:0.72rem;font-weight:700;<?= $o['status']==='active'?'background:rgba(34,197,94,0.12);color:#86efac':'background:rgba(107,114,128,0.12);color:var(--text-muted)' ?>">
            <?= ucfirst($o['status']) ?>
        </span>
    </td>
    <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?action=edit&id=<?= $o['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="toggle_featured"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn-ghost btn-sm"><?= $o['is_featured']?'Unfeature':'Feature' ?></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn-ghost btn-sm"><?= $o['status']==='archived'?'Reactivate':'Archive' ?></button></form>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $o['id'] ?>"><button class="btn-danger btn-sm" onclick="return confirm('Delete this opportunity?')">Delete</button></form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($allOpps)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px">No opportunities yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php elseif ($action === 'edit'): ?>

<h1 class="admin-page-title"><?= $opportunity ? 'Edit' : 'New' ?> Opportunity</h1>

<form method="POST" style="max-width:720px;margin-bottom:28px">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $opportunity['id'] ?? '' ?>">

    <div class="form-group"><label class="form-label">Title *</label><input type="text" name="title" class="form-input" required value="<?= sanitize($opportunity['title'] ?? '') ?>"></div>
    <div class="form-group"><label class="form-label">Organization</label><input type="text" name="organization" class="form-input" value="<?= sanitize($opportunity['organization'] ?? '') ?>"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Category *</label>
            <select name="category" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach (OPPORTUNITY_CATEGORIES as $k=>$label): ?>
                <option value="<?= $k ?>" <?= ($opportunity['category'] ?? '')===$k?'selected':'' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Difficulty</label>
            <select name="difficulty" class="form-select">
                <?php foreach (['Beginner','Intermediate','Advanced'] as $d): ?>
                <option value="<?= $d ?>" <?= ($opportunity['difficulty'] ?? 'Beginner')===$d?'selected':'' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Work Mode</label>
            <select name="work_mode" class="form-select">
                <option value="remote" <?= ($opportunity['work_mode'] ?? 'remote')==='remote'?'selected':'' ?>>Remote</option>
                <option value="hybrid" <?= ($opportunity['work_mode'] ?? '')==='hybrid'?'selected':'' ?>>Hybrid</option>
                <option value="onsite" <?= ($opportunity['work_mode'] ?? '')==='onsite'?'selected':'' ?>>Onsite</option>
            </select>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-input" placeholder="e.g. Remote / San Francisco" value="<?= sanitize($opportunity['location'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Deadline</label><input type="date" name="deadline" class="form-input" value="<?= sanitize($opportunity['deadline'] ?? '') ?>"></div>
    </div>

    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="4"><?= sanitize($opportunity['description'] ?? '') ?></textarea></div>
    <div class="form-group"><label class="form-label">Requirements</label><textarea name="requirements" class="form-input" rows="3"><?= sanitize($opportunity['requirements'] ?? '') ?></textarea></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group"><label class="form-label">Reward <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label><input type="text" name="reward" class="form-input" placeholder="e.g. $2,000 or Equity" value="<?= sanitize($opportunity['reward'] ?? '') ?>"></div>
        <div class="form-group"><label class="form-label">Tags <span style="font-weight:400;color:var(--text-muted)">(comma-separated)</span></label><input type="text" name="tags" class="form-input" placeholder="Solidity, DeFi, Remote" value="<?= sanitize($opportunity['tags'] ?? '') ?>"></div>
    </div>

    <div class="form-group"><label class="form-label">Official Link *</label><input type="url" name="official_link" class="form-input" required value="<?= sanitize($opportunity['official_link'] ?? '') ?>"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="active" <?= ($opportunity['status'] ?? 'active')==='active'?'selected':'' ?>>Active</option>
                <option value="archived" <?= ($opportunity['status'] ?? '')==='archived'?'selected':'' ?>>Archived</option>
            </select>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:10px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:0.84rem">
                <input type="checkbox" name="is_featured" value="1" <?= !empty($opportunity['is_featured'])?'checked':'' ?>>
                <span>⭐ Featured</span>
            </label>
        </div>
    </div>

    <div style="display:flex;gap:10px"><button class="btn-primary" type="submit">Save</button><a href="?action=list" class="btn-ghost">Cancel</a></div>
</form>

<?php if ($opportunity): ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;max-width:720px">
    <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:12px">Relevant Career Paths</h2>
    <?php if (empty($linkedPaths)): ?>
        <p style="font-size:0.82rem;color:var(--text-muted);margin-bottom:14px">Not linked to any Career Path yet.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
        <?php foreach ($linkedPaths as $lp): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:var(--bg-card2);border-radius:var(--radius);font-size:0.82rem">
                <span><?= sanitize($lp['icon'] ?: '⭐') ?> <?= sanitize($lp['name']) ?></span>
                <form method="POST"><input type="hidden" name="action" value="unlink_career_path"><input type="hidden" name="link_id" value="<?= $lp['id'] ?>">
                    <button class="btn-ghost btn-sm" style="color:#f87171">Unlink</button>
                </form>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <form method="POST" style="display:flex;gap:8px">
        <input type="hidden" name="action" value="link_career_path">
        <select name="career_path_id" class="form-select" style="flex:1">
            <?php foreach ($allCareerPaths as $cp): ?><option value="<?= $cp['id'] ?>"><?= sanitize($cp['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn-primary btn-sm" type="submit">Link</button>
    </form>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

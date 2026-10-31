<?php
/**
 * Admin — Guide Projects
 * Attach/remove/reorder Projects inside one Guide, mirroring the Career Path
 * Builder's guide-attachment pattern exactly (career-path-builder.php).
 */
$adminTitle = 'Guide Projects';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/projects.php';

$db = getDB();
$guideId = intval($_GET['guide_id'] ?? 0);
$gStmt = $db->prepare("SELECT * FROM guide_paths WHERE id = ?");
$gStmt->execute([$guideId]);
$guide = $gStmt->fetch();

if (!$guide) {
    header('Location: ' . SITE_URL . '/admin/guides.php'); exit;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add_project') {
        $projectId = intval($_POST['project_id'] ?? 0);
        if ($projectId) {
            $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0) FROM guide_projects WHERE guide_id=?");
            $stmt->execute([$guideId]);
            $next = (int)$stmt->fetchColumn() + 1;
            $db->prepare("INSERT IGNORE INTO guide_projects (guide_id, project_id, requirement, is_visible, display_order) VALUES (?,?,'required',1,?)")
               ->execute([$guideId, $projectId, $next]);
            $msg = 'Project attached.';
        }
    }

    if ($postAction === 'remove_project') {
        $db->prepare("DELETE FROM guide_projects WHERE id = ? AND guide_id = ?")->execute([intval($_POST['gp_id'] ?? 0), $guideId]);
        $msg = 'Project removed from this guide.';
    }

    if ($postAction === 'save_projects') {
        $order = $_POST['project_order'] ?? [];
        $reqs  = $_POST['requirement'] ?? [];
        $vis   = $_POST['visible'] ?? [];
        foreach ($order as $i => $gpId) {
            $gpId = intval($gpId);
            if (!$gpId) continue;
            $requirement = ($reqs[$gpId] ?? 'required') === 'optional' ? 'optional' : 'required';
            $isVisible = isset($vis[$gpId]) ? 1 : 0;
            $db->prepare("UPDATE guide_projects SET display_order=?, requirement=?, is_visible=? WHERE id=? AND guide_id=?")
               ->execute([$i + 1, $requirement, $isVisible, $gpId, $guideId]);
        }
        $msg = 'Project order and rules saved.';
    }
}

$guideProjects = getGuideProjectsAdmin($guideId);
$attachable = getAttachableProjects($guideId);
?>

<?php if ($msg): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">🛠️ <?= sanitize($guide['title']) ?> — Projects</h1>
    <a href="guides.php?action=edit&id=<?= $guideId ?>" class="btn-ghost btn-sm">← Back to Guide</a>
</div>

<?php if (empty($guide['require_project'])): ?>
<div class="admin-alert" style="background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#fbbf24">
    "Require all attached Projects to be approved" is currently OFF for this guide — attached projects will show to users but won't block guide completion.
    <a href="guides.php?action=edit&id=<?= $guideId ?>" style="color:inherit;text-decoration:underline">Turn it on →</a>
</div>
<?php endif; ?>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;max-width:760px">
    <form method="POST" id="gpForm">
        <input type="hidden" name="action" value="save_projects">
        <div id="gpList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
        <?php foreach ($guideProjects as $gp): ?>
            <div class="gp-row" data-gp-id="<?= $gp['id'] ?>" style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-card2);border:1.5px solid var(--border);border-radius:var(--radius);cursor:grab">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                <div style="flex:1;min-width:0">
                    <div style="font-size:0.84rem;font-weight:600"><?= sanitize($gp['title']) ?></div>
                    <div style="font-size:0.7rem;color:var(--text-muted)"><?= ucfirst($gp['project_type']) ?></div>
                </div>
                <select name="requirement[<?= $gp['id'] ?>]" class="form-select" style="width:100px;flex-shrink:0;font-size:0.76rem;padding:5px 6px">
                    <option value="required" <?= $gp['requirement']==='required'?'selected':'' ?>>Required</option>
                    <option value="optional" <?= $gp['requirement']==='optional'?'selected':'' ?>>Optional</option>
                </select>
                <label style="display:flex;align-items:center;gap:4px;font-size:0.72rem;color:var(--text-muted);flex-shrink:0">
                    <input type="checkbox" name="visible[<?= $gp['id'] ?>]" value="1" <?= $gp['is_visible']?'checked':'' ?>> Visible
                </label>
                <button type="button" onclick="removeGp(<?= $gp['id'] ?>)" style="background:none;border:none;cursor:pointer;color:var(--text-muted);flex-shrink:0">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        <?php endforeach; ?>
        <?php if (empty($guideProjects)): ?>
            <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.84rem;border:2px dashed var(--border);border-radius:var(--radius)">No projects attached yet.</div>
        <?php endif; ?>
        </div>
        <div id="gpHiddenOrder">
        <?php foreach ($guideProjects as $gp): ?>
            <input type="hidden" name="project_order[]" value="<?= $gp['id'] ?>">
        <?php endforeach; ?>
        </div>
        <button class="btn-primary btn-sm" type="submit">Save Order & Rules</button>
    </form>

    <form method="POST" id="removeGpForm" style="display:none">
        <input type="hidden" name="action" value="remove_project">
        <input type="hidden" name="gp_id" id="removeGpId">
    </form>

    <div style="border-top:1px solid var(--border);margin-top:18px;padding-top:16px">
        <label class="form-label" style="margin-bottom:8px;display:block">Attach Existing Project</label>
        <?php if (empty($attachable)): ?>
            <p style="font-size:0.8rem;color:var(--text-muted)">Every active project is already attached, or none exist yet — <a href="projects.php?action=edit" style="color:var(--primary-bright)">create one</a>.</p>
        <?php else: ?>
            <form method="POST" style="display:flex;gap:8px">
                <input type="hidden" name="action" value="add_project">
                <select name="project_id" class="form-select" style="flex:1">
                    <?php foreach ($attachable as $ap): ?>
                    <option value="<?= $ap['id'] ?>"><?= sanitize($ap['title']) ?> (<?= ucfirst($ap['project_type']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-primary btn-sm" type="submit">Attach</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
function removeGp(gpId) {
    if (!confirm('Remove this project from the guide?')) return;
    document.getElementById('removeGpId').value = gpId;
    document.getElementById('removeGpForm').submit();
}
let dragSrc = null;
const list = document.getElementById('gpList');
document.querySelectorAll('.gp-row').forEach(row => {
    row.draggable = true;
    row.addEventListener('dragstart', () => { dragSrc = row; row.classList.add('dragging'); });
    row.addEventListener('dragend', () => { row.classList.remove('dragging'); syncOrder(); });
    row.addEventListener('dragover', e => e.preventDefault());
    row.addEventListener('drop', e => {
        e.preventDefault();
        if (dragSrc && dragSrc !== row) {
            const rows = Array.from(list.children);
            if (rows.indexOf(dragSrc) < rows.indexOf(row)) list.insertBefore(dragSrc, row.nextSibling);
            else list.insertBefore(dragSrc, row);
        }
    });
});
function syncOrder() {
    const c = document.getElementById('gpHiddenOrder');
    c.innerHTML = '';
    document.querySelectorAll('.gp-row').forEach(row => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = 'project_order[]'; input.value = row.dataset.gpId;
        c.appendChild(input);
    });
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

<?php
/**
 * Admin — Career Path Builder
 * Attach/remove/reorder existing Guides into a Career Path, optionally group
 * them under Milestones, and configure per-guide rules (required/optional,
 * visible, order). Never creates or duplicates guide/lesson content — only
 * writes to career_path_guides / career_path_milestones.
 */
$adminTitle = 'Career Path Builder';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/career_paths.php';

$db  = getDB();
$pathId = intval($_GET['id'] ?? 0);
$path = $pathId ? getCareerPath($pathId) : null;

if (!$path) {
    header('Location: ' . SITE_URL . '/admin/career-paths.php'); exit;
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add_milestone') {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0) FROM career_path_milestones WHERE career_path_id=?");
            $stmt->execute([$pathId]);
            $nextOrder = (int)$stmt->fetchColumn() + 1;
            $db->prepare("INSERT INTO career_path_milestones (career_path_id, title, description, display_order) VALUES (?,?,?,?)")
               ->execute([$pathId, $title, trim($_POST['description'] ?? ''), $nextOrder]);
            $msg = 'Milestone added.';
        }
    }

    if ($postAction === 'delete_milestone') {
        $mid = intval($_POST['milestone_id'] ?? 0);
        // FK is ON DELETE SET NULL — guides in this milestone become ungrouped, not removed.
        $db->prepare("DELETE FROM career_path_milestones WHERE id = ? AND career_path_id = ?")->execute([$mid, $pathId]);
        $msg = 'Milestone removed. Its guides are now ungrouped.';
    }

    if ($postAction === 'add_guide') {
        $guideId = intval($_POST['guide_id'] ?? 0);
        if ($guideId) {
            $stmt = $db->prepare("SELECT COALESCE(MAX(display_order),0) FROM career_path_guides WHERE career_path_id=?");
            $stmt->execute([$pathId]);
            $nextOrder = (int)$stmt->fetchColumn() + 1;
            $db->prepare("
                INSERT IGNORE INTO career_path_guides (career_path_id, guide_id, requirement, is_visible, display_order)
                VALUES (?,?,'required',1,?)
            ")->execute([$pathId, $guideId, $nextOrder]);
            $msg = 'Guide attached.';
        }
    }

    if ($postAction === 'remove_guide') {
        $cpgId = intval($_POST['cpg_id'] ?? 0);
        $db->prepare("DELETE FROM career_path_guides WHERE id = ? AND career_path_id = ?")->execute([$cpgId, $pathId]);
        $msg = 'Guide removed from this path.';
    }

    if ($postAction === 'save_guides') {
        // Rebuild order + rules from the submitted form. Never touches
        // guide_paths, guide_path_lessons, or user_lesson_progress.
        $order = $_POST['guide_order'] ?? []; // ordered list of career_path_guides.id
        $reqs  = $_POST['requirement'] ?? [];  // [cpg_id => 'required'|'optional']
        $vis   = $_POST['visible'] ?? [];       // [cpg_id => '1'] (unchecked boxes omitted)
        $mile  = $_POST['milestone'] ?? [];     // [cpg_id => milestone_id or '']

        foreach ($order as $i => $cpgId) {
            $cpgId = intval($cpgId);
            if (!$cpgId) continue;
            $requirement = ($reqs[$cpgId] ?? 'required') === 'optional' ? 'optional' : 'required';
            $isVisible   = isset($vis[$cpgId]) ? 1 : 0;
            $milestoneId = intval($mile[$cpgId] ?? 0) ?: null;
            $db->prepare("
                UPDATE career_path_guides SET display_order=?, requirement=?, is_visible=?, milestone_id=?
                WHERE id=? AND career_path_id=?
            ")->execute([$i + 1, $requirement, $isVisible, $milestoneId, $cpgId, $pathId]);
        }
        $msg = 'Guide order and rules saved.';
    }
}

$milestones = getCareerPathMilestones($pathId);
$pathGuides = getCareerPathGuides($pathId);
$attachable = getAttachableGuides($pathId);
?>

<?php if ($msg): ?><div class="admin-alert admin-alert-success"><?= sanitize($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="admin-alert admin-alert-error"><?= sanitize($err) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0"><?= sanitize($path['icon'] ?: '⭐') ?> <?= sanitize($path['name']) ?> — Builder</h1>
    <div style="display:flex;gap:8px">
        <a href="career-paths.php?action=edit&id=<?= $pathId ?>" class="btn-ghost btn-sm">Edit Details</a>
        <a href="career-paths.php" class="btn-ghost btn-sm">← All Career Paths</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:20px" class="cpb-grid">

    <!-- ── Milestones ── -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;align-self:start">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:6px">Milestones <span style="font-weight:400;color:var(--text-muted)">(optional)</span></h2>
        <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:14px">
            Presentation-only grouping. Skip this entirely for a flat guide list — unlock order and progress are never affected by milestones.
        </p>

        <?php if (empty($milestones)): ?>
            <p style="font-size:0.8rem;color:var(--text-muted);margin-bottom:14px">No milestones yet — guides render as a flat list.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:14px">
            <?php foreach ($milestones as $m): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;background:var(--bg-card2);border-radius:var(--radius);font-size:0.82rem">
                    <span><?= sanitize($m['title']) ?></span>
                    <form method="POST" onsubmit="return confirm('Delete this milestone? Its guides become ungrouped, not removed.')">
                        <input type="hidden" name="action" value="delete_milestone">
                        <input type="hidden" name="milestone_id" value="<?= $m['id'] ?>">
                        <button class="btn-ghost btn-sm" style="color:#f87171;padding:2px 8px">✕</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="display:flex;flex-direction:column;gap:8px;border-top:1px solid var(--border);padding-top:14px">
            <input type="hidden" name="action" value="add_milestone">
            <input type="text" name="title" class="form-input" placeholder="Milestone title (e.g. Web3 Foundations)" required>
            <input type="text" name="description" class="form-input" placeholder="Optional description">
            <button class="btn-primary btn-sm" type="submit">+ Add Milestone</button>
        </form>
    </div>

    <!-- ── Guide order + rules ── -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
            <h2 style="font-size:0.9rem;font-weight:700;margin:0">Guides in this Path</h2>
            <span style="font-size:0.72rem;color:var(--text-muted)">Drag to reorder</span>
        </div>
        <p style="font-size:0.78rem;color:var(--text-muted);margin-bottom:14px">
            Required guides gate progression in order. Optional guides never block. Hidden guides stay attached but don't show to users.
        </p>

        <form method="POST" id="guideRulesForm">
            <input type="hidden" name="action" value="save_guides">

            <div id="cpgList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
            <?php foreach ($pathGuides as $g): ?>
                <div class="cpg-row" data-cpg-id="<?= $g['id'] ?>"
                     style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg-card2);border:1.5px solid var(--border);border-radius:var(--radius);cursor:grab">
                    <svg class="drag-handle" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);flex-shrink:0"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>

                    <div style="flex:1;min-width:0">
                        <div style="font-size:0.84rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= sanitize($g['title']) ?></div>
                        <div style="font-size:0.7rem;color:var(--text-muted)"><?= (int)$g['lesson_count'] ?> lessons · <?= sanitize($g['difficulty'] ?? 'Beginner') ?></div>
                    </div>

                    <select name="milestone[<?= $g['id'] ?>]" class="form-select" style="width:150px;flex-shrink:0;font-size:0.76rem;padding:5px 6px">
                        <option value="">— Ungrouped —</option>
                        <?php foreach ($milestones as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= (int)$g['milestone_id'] === (int)$m['id'] ? 'selected' : '' ?>><?= sanitize($m['title']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="requirement[<?= $g['id'] ?>]" class="form-select" style="width:100px;flex-shrink:0;font-size:0.76rem;padding:5px 6px">
                        <option value="required" <?= $g['requirement'] === 'required' ? 'selected' : '' ?>>Required</option>
                        <option value="optional" <?= $g['requirement'] === 'optional' ? 'selected' : '' ?>>Optional</option>
                    </select>

                    <label style="display:flex;align-items:center;gap:4px;font-size:0.72rem;color:var(--text-muted);flex-shrink:0" title="Visible to users">
                        <input type="checkbox" name="visible[<?= $g['id'] ?>]" value="1" <?= $g['is_visible'] ? 'checked' : '' ?>> Visible
                    </label>

                    <button type="button" onclick="removeCpg(<?= $g['id'] ?>, this)" title="Remove from this path"
                            style="background:none;border:none;cursor:pointer;color:var(--text-muted);flex-shrink:0">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            <?php endforeach; ?>
            <?php if (empty($pathGuides)): ?>
                <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:0.84rem;border:2px dashed var(--border);border-radius:var(--radius)">
                    No guides attached yet. Add one below.
                </div>
            <?php endif; ?>
            </div>

            <div id="cpgHiddenOrder">
                <?php foreach ($pathGuides as $g): ?>
                <input type="hidden" name="guide_order[]" value="<?= $g['id'] ?>">
                <?php endforeach; ?>
            </div>

            <button class="btn-primary btn-sm" type="submit">Save Order & Rules</button>
        </form>

        <!-- Remove-guide mini form (submitted via JS) -->
        <form method="POST" id="removeGuideForm" style="display:none">
            <input type="hidden" name="action" value="remove_guide">
            <input type="hidden" name="cpg_id" id="removeCpgId">
        </form>

        <div style="border-top:1px solid var(--border);margin-top:18px;padding-top:16px">
            <label class="form-label" style="margin-bottom:8px;display:block">Add Existing Guide</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php if (empty($attachable)): ?>
                    <p style="font-size:0.8rem;color:var(--text-muted)">Every guide is already attached to this path.</p>
                <?php else: ?>
                    <form method="POST" style="display:flex;gap:8px;flex:1">
                        <input type="hidden" name="action" value="add_guide">
                        <select name="guide_id" class="form-select" style="flex:1">
                            <?php foreach ($attachable as $ag): ?>
                            <option value="<?= $ag['id'] ?>"><?= sanitize($ag['title']) ?> (<?= (int)$ag['lesson_count'] ?> lessons)</option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-primary btn-sm" type="submit">Add Existing Guide</button>
                    </form>
                <?php endif; ?>
            </div>
            <p style="font-size:0.74rem;color:var(--text-muted);margin-top:8px">
                Need brand-new content? Create it first in the <a href="guides.php?action=edit" style="color:var(--primary-bright)">Guide Builder</a>, then attach it here.
            </p>
        </div>
    </div>
</div>

<style>
.cpg-row.drag-over { border-color: var(--primary) !important; background: var(--glow-soft) !important; }
.cpg-row.dragging  { opacity: 0.4; }
@media (max-width: 900px) { .cpb-grid { grid-template-columns: 1fr !important; } }
</style>

<script>
function removeCpg(cpgId, btn) {
    if (!confirm('Remove this guide from the path? Lesson progress is never affected.')) return;
    document.getElementById('removeCpgId').value = cpgId;
    document.getElementById('removeGuideForm').submit();
}

// ── Drag-and-drop reorder (same pattern as the Guide Builder's lesson list) ──
let dragSrc = null;
const list = document.getElementById('cpgList');

function bindDrag(row) {
    row.draggable = true;
    row.addEventListener('dragstart', e => {
        dragSrc = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    row.addEventListener('dragend', () => {
        row.classList.remove('dragging');
        document.querySelectorAll('.cpg-row').forEach(r => r.classList.remove('drag-over'));
        syncOrderInputs();
    });
    row.addEventListener('dragover', e => {
        e.preventDefault();
        if (row !== dragSrc) {
            document.querySelectorAll('.cpg-row').forEach(r => r.classList.remove('drag-over'));
            row.classList.add('drag-over');
        }
    });
    row.addEventListener('drop', e => {
        e.preventDefault();
        if (dragSrc && dragSrc !== row) {
            const rows = Array.from(list.children);
            const srcIdx = rows.indexOf(dragSrc);
            const tgtIdx = rows.indexOf(row);
            if (srcIdx < tgtIdx) list.insertBefore(dragSrc, row.nextSibling);
            else list.insertBefore(dragSrc, row);
            row.classList.remove('drag-over');
        }
    });
}

function syncOrderInputs() {
    const container = document.getElementById('cpgHiddenOrder');
    container.innerHTML = '';
    document.querySelectorAll('.cpg-row').forEach(row => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'guide_order[]';
        input.value = row.dataset.cpgId;
        container.appendChild(input);
    });
}

document.querySelectorAll('.cpg-row').forEach(bindDrag);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>

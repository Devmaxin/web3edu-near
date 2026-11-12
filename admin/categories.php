<?php
$adminTitle = 'Categories';
require_once __DIR__ . '/header.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

// ── Helper: slugify ──────────────────────────────────────────
function makeSlug(string $str): string {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\s-]/', '', $str);
    $str = preg_replace('/[\s-]+/', '-', $str);
    return trim($str, '-');
}

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    // Unlink lessons first (set to NULL so they aren't orphaned)
    $db->prepare("UPDATE lessons SET video_category_id = NULL WHERE video_category_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/categories.php?deleted=1'); exit;
}

// ── CREATE / UPDATE ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']        ?? '');
    $slug        = trim($_POST['slug']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort_order  = intval($_POST['sort_order'] ?? 0);

    if (empty($name)) $errors[] = 'Category name is required.';
    if (empty($slug)) $slug = makeSlug($name);
    // Basic slug validation
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) $errors[] = 'Slug may only contain lowercase letters, numbers, and hyphens.';

    // Unique slug check (excluding self on edit)
    if (empty($errors)) {
        $checkSql = $id
            ? "SELECT id FROM categories WHERE slug = ? AND id != ?"
            : "SELECT id FROM categories WHERE slug = ?";
        $checkParams = $id ? [$slug, $id] : [$slug];
        $exists = $db->prepare($checkSql);
        $exists->execute($checkParams);
        if ($exists->fetch()) $errors[] = 'That slug is already taken — choose a different name.';
    }

    if (empty($errors)) {
        if ($id) {
            $db->prepare(
                "UPDATE categories SET name=?, slug=?, description=?, sort_order=? WHERE id=?"
            )->execute([$name, $slug, $description, $sort_order, $id]);
            $success = 'Category updated successfully.';
        } else {
            $db->prepare(
                "INSERT INTO categories (name, slug, description, sort_order) VALUES (?,?,?,?)"
            )->execute([$name, $slug, $description, $sort_order]);
            $id = (int)$db->lastInsertId();
            $success = 'Category created successfully.';
            $action  = 'edit';
        }
    }
}

// ── LOAD for edit ─────────────────────────────────────────────
$cat = null;
if (($action === 'edit') && $id) {
    $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if (!$cat) { header('Location: ' . SITE_URL . '/admin/categories.php'); exit; }
}

// ── LESSON ASSIGNMENT ─────────────────────────────────────────
// Handle bulk-assign of lessons to a category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_lessons'])) {
    $targetCatId  = intval($_POST['cat_id'] ?? 0);
    $lessonIds    = array_map('intval', $_POST['lesson_ids'] ?? []);
    if ($targetCatId && !empty($lessonIds)) {
        $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
        $params = array_merge([$targetCatId], $lessonIds);
        $db->prepare("UPDATE lessons SET video_category_id = ? WHERE id IN ($placeholders)")->execute($params);
        $success = count($lessonIds) . ' lesson(s) assigned to category.';
    }
}

// ── LIST DATA ────────────────────────────────────────────────
$categories = $db->query(
    "SELECT vc.*, COUNT(l.id) AS lesson_count
     FROM   categories vc
     LEFT JOIN lessons l ON l.video_category_id = vc.id
     GROUP BY vc.id
     ORDER BY vc.sort_order ASC, vc.name ASC"
)->fetchAll();

// Lessons for assignment panel
$allLessons = $db->query(
    "SELECT l.id, l.title, l.video_id, l.access_level, l.video_category_id,
            vc.name AS cat_name
     FROM   lessons l
     LEFT JOIN categories vc ON vc.id = l.video_category_id
     ORDER BY l.title ASC"
)->fetchAll();
?>

<!-- Flash messages -->
<?php if (isset($_GET['deleted'])): ?>
<div class="admin-alert admin-alert-success">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
    Category deleted. Affected lessons have been unlinked.
</div>
<?php endif; ?>
<?php if (!empty($success)): ?>
<div class="admin-alert admin-alert-success">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>
    <?= sanitize($success) ?>
</div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="admin-alert admin-alert-error">
    <?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
<!-- ═══════════════════════════════════════════════════════════
     LIST VIEW
══════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">
        Categories
        <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);margin-left:8px">(<?= count($categories) ?>)</span>
    </h1>
    <a href="?action=edit" class="btn-primary btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Category
    </a>
</div>

<!-- Info banner -->
<div style="background:rgba(139,92,246,0.07);border:1px solid rgba(139,92,246,0.2);border-radius:var(--radius);padding:12px 16px;margin-bottom:22px;font-size:0.84rem;color:var(--text-muted)">
    <strong style="color:var(--primary-bright)">How it works:</strong>
    Categories organise video lessons on the public <a href="<?= SITE_URL ?>/videos.php" target="_blank" style="color:var(--primary-bright)">Videos page</a>.
    Each lesson can belong to one category. Use the <strong>Assign Lessons</strong> tab in each category to move lessons around.
</div>

<?php if (empty($categories)): ?>
<div style="text-align:center;padding:60px;color:var(--text-muted)">
    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 14px;display:block;color:var(--border)"><polygon points="5 3 19 12 5 21 5 3"/></svg>
    <p>No categories yet. <a href="?action=edit" style="color:var(--primary-bright)">Create your first one.</a></p>
</div>
<?php else: ?>
<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr>
    <th>Order</th>
    <th>Name</th>
    <th>Slug</th>
    <th>Description</th>
    <th>Videos</th>
    <th>Actions</th>
</tr></thead>
<tbody>
<?php foreach ($categories as $c): ?>
<tr>
    <td style="color:var(--text-muted);font-size:0.82rem;text-align:center"><?= (int)$c['sort_order'] ?></td>
    <td style="font-weight:600"><?= sanitize($c['name']) ?></td>
    <td style="font-family:var(--font-mono);font-size:0.76rem;color:var(--text-muted)"><?= sanitize($c['slug']) ?></td>
    <td style="font-size:0.82rem;color:var(--text-muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= $c['description'] ? sanitize($c['description']) : '<em style="opacity:0.5">—</em>' ?>
    </td>
    <td style="text-align:center">
        <span style="padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;background:rgba(139,92,246,0.12);color:var(--primary-bright)">
            <?= (int)$c['lesson_count'] ?>
        </span>
    </td>
    <td>
        <div style="display:flex;gap:6px">
            <a href="?action=edit&id=<?= $c['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="?action=assign&id=<?= $c['id'] ?>" class="btn-ghost btn-sm" style="color:var(--cyan)">Assign</a>
            <a href="?action=delete&id=<?= $c['id'] ?>"
               class="btn-danger btn-sm"
               onclick="return confirm('Delete \'<?= sanitize(addslashes($c['name'])) ?>\' and unlink all its lessons?')">
               Delete
            </a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php elseif ($action === 'edit'): ?>
<!-- ═══════════════════════════════════════════════════════════
     EDIT / CREATE FORM
══════════════════════════════════════════════════════════════ -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Category' : 'New Category' ?></h1>
    <a href="<?= SITE_URL ?>/admin/categories.php" class="btn-ghost btn-sm">← Back</a>
</div>

<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:28px;max-width:600px">
<form method="POST">
    <div class="form-group">
        <label class="form-label">Category Name <span style="color:var(--danger)">*</span></label>
        <input type="text" name="name" class="form-input"
               value="<?= sanitize($cat['name'] ?? '') ?>"
               placeholder="e.g. DeFi"
               required
               oninput="autoSlug(this)">
        <div class="form-hint">Shown on the Videos page sidebar and category pills.</div>
    </div>

    <div class="form-group">
        <label class="form-label">Slug <span style="color:var(--danger)">*</span></label>
        <input type="text" name="slug" id="slugField" class="form-input"
               value="<?= sanitize($cat['slug'] ?? '') ?>"
               placeholder="e.g. defi"
               pattern="[a-z0-9-]+"
               title="Lowercase letters, numbers, and hyphens only"
               required>
        <div class="form-hint">Used in URLs. Auto-generated from name. Lowercase, hyphens only.</div>
    </div>

    <div class="form-group">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-input" rows="3"
                  placeholder="Short description shown in the category banner…"><?= sanitize($cat['description'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
        <label class="form-label">Sort Order</label>
        <input type="number" name="sort_order" class="form-input"
               value="<?= intval($cat['sort_order'] ?? 0) ?>"
               min="0" max="999" style="width:120px">
        <div class="form-hint">Lower numbers appear first in the sidebar (0 = top).</div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
        <button type="submit" class="btn-primary">
            <?= $id ? 'Save Changes' : 'Create Category' ?>
        </button>
        <a href="<?= SITE_URL ?>/admin/categories.php" class="btn-ghost">Cancel</a>
    </div>
</form>
</div>

<script>
let userEditedSlug = <?= $id ? 'true' : 'false' ?>; // on create, auto-generate; on edit, don't overwrite
function slugify(s) {
    return s.toLowerCase().trim()
        .replace(/[^a-z0-9\s-]/g,'')
        .replace(/[\s-]+/g,'-')
        .replace(/^-+|-+$/g,'');
}
function autoSlug(nameInput) {
    if (!userEditedSlug) {
        document.getElementById('slugField').value = slugify(nameInput.value);
    }
}
document.getElementById('slugField').addEventListener('input', function() {
    userEditedSlug = true;
    this.value = slugify(this.value);
});
</script>

<?php elseif ($action === 'assign'): ?>
<!-- ═══════════════════════════════════════════════════════════
     ASSIGN LESSONS TO CATEGORY
══════════════════════════════════════════════════════════════ -->
<?php
$assignCat = null;
if ($id) {
    $s = $db->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([$id]); $assignCat = $s->fetch();
}
if (!$assignCat) { header('Location: ' . SITE_URL . '/admin/categories.php'); exit; }

// Lessons currently in this category
$currentLessons = $db->prepare(
    "SELECT id, title, video_id, access_level FROM lessons WHERE video_category_id = ? ORDER BY title"
);
$currentLessons->execute([$id]);
$currentLessons = $currentLessons->fetchAll();

// All other lessons with videos
$otherLessons = $db->prepare(
    "SELECT l.id, l.title, l.video_id, l.access_level,
            vc.name AS cat_name
     FROM   lessons l
     LEFT JOIN categories vc ON vc.id = l.video_category_id
     WHERE  l.video_category_id != ? OR l.video_category_id IS NULL
     ORDER BY l.title ASC"
);
$otherLessons->execute([$id]);
$otherLessons = $otherLessons->fetchAll();
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <div>
        <h1 class="admin-page-title" style="margin:0">Assign Lessons — <?= sanitize($assignCat['name']) ?></h1>
        <p style="color:var(--text-muted);font-size:0.84rem;margin:4px 0 0">Move lessons into or out of this category.</p>
    </div>
    <a href="<?= SITE_URL ?>/admin/categories.php" class="btn-ghost btn-sm">← Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px" class="assign-grid">

    <!-- Currently assigned -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <div style="font-weight:700;font-size:0.9rem">In this category
                <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);margin-left:6px">(<?= count($currentLessons) ?>)</span>
            </div>
        </div>
        <div style="max-height:420px;overflow-y:auto;padding:8px">
        <?php if (empty($currentLessons)): ?>
            <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.84rem">No lessons assigned yet.</div>
        <?php else: ?>
        <?php foreach ($currentLessons as $l): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:var(--radius);margin-bottom:4px;background:rgba(11,121,88,0.05);border:1px solid rgba(11,121,88,0.12)">
            <div style="min-width:0">
                <div style="font-size:0.84rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($l['title']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px">
                    <?= $l['video_id'] ? '▶ Has video' : '⚠ No video' ?>
                    · <span style="text-transform:capitalize"><?= $l['access_level'] ?></span>
                </div>
            </div>
            <form method="POST" style="flex-shrink:0;margin-left:8px">
                <input type="hidden" name="assign_lessons" value="1">
                <input type="hidden" name="cat_id" value="0"> <!-- 0 = unassign -->
                <input type="hidden" name="lesson_ids[]" value="<?= $l['id'] ?>">
                <button type="submit" class="btn-danger btn-sm"
                        style="padding:3px 10px;font-size:0.72rem"
                        onclick="return confirm('Remove from this category?')" title="Remove">
                    Remove
                </button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

    <!-- Other lessons -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
        <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <div style="font-weight:700;font-size:0.9rem">Other lessons
                <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted);margin-left:6px">(<?= count($otherLessons) ?>)</span>
            </div>
        </div>
        <div style="max-height:420px;overflow-y:auto;padding:8px">
        <?php if (empty($otherLessons)): ?>
            <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:0.84rem">All lessons are in this category.</div>
        <?php else: ?>
        <?php foreach ($otherLessons as $l): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:var(--radius);margin-bottom:4px;background:var(--bg-mid,rgba(255,255,255,0.02));border:1px solid var(--border)">
            <div style="min-width:0">
                <div style="font-size:0.84rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= sanitize($l['title']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px">
                    <?= $l['video_id'] ? '▶ Has video' : '⚠ No video' ?>
                    · <?= $l['cat_name'] ? sanitize($l['cat_name']) : 'Uncategorised' ?>
                    · <span style="text-transform:capitalize"><?= $l['access_level'] ?></span>
                </div>
            </div>
            <form method="POST" style="flex-shrink:0;margin-left:8px">
                <input type="hidden" name="assign_lessons" value="1">
                <input type="hidden" name="cat_id" value="<?= $id ?>">
                <input type="hidden" name="lesson_ids[]" value="<?= $l['id'] ?>">
                <button type="submit" class="btn-primary btn-sm" style="padding:3px 10px;font-size:0.72rem">
                    Add →
                </button>
            </form>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

</div><!-- /assign-grid -->

<style>
@media (max-width: 700px) {
    .assign-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php endif; // end action switch ?>

<?php require_once __DIR__ . '/footer.php'; ?>

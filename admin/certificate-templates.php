<?php
$adminTitle = 'Certificate Templates';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/certificates.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = [];

// ── Live preview (opens in a new tab; renders whatever is in the form right now,
//    even if it hasn't been saved yet) ─────────────────────────────────────────
if ($action === 'preview_render' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tpl = [
        'name'                     => $_POST['name'] ?? 'Certificate of Completion',
        'render_mode'              => $_POST['render_mode'] ?? 'builder',
        'html_template'            => $_POST['html_template'] ?? '',
        'subtitle'                 => $_POST['subtitle'] ?? '',
        'body_text'                => $_POST['body_text'] ?? '',
        'footer_text'              => $_POST['footer_text'] ?? '',
        'signature1_name'          => $_POST['signature1_name'] ?? '',
        'signature1_title'         => $_POST['signature1_title'] ?? '',
        'signature2_name'          => $_POST['signature2_name'] ?? '',
        'signature2_title'         => $_POST['signature2_title'] ?? '',
        'issue_date_format'        => $_POST['issue_date_format'] ?? 'F j, Y',
        'show_qr'                  => isset($_POST['show_qr']) ? 1 : 0,
        'verification_url_override'=> $_POST['verification_url_override'] ?? '',
        'site_logo_override'       => null,
    ];
    $sampleCert = ['certificate_code' => 'WEB3-' . date('Y') . '-000001', 'issued_at' => date('Y-m-d H:i:s'), 'username' => 'jane_doe'];
    $vars = buildCertificatePlaceholders($sampleCert, $tpl, ['certificate_title' => 'Web3 Fundamentals', 'full_name' => 'jane_doe']);
    $bgId = intval($_POST['background_id'] ?? 0);
    $bgStyle = '';
    if ($bgId && $bg = getCertificateBackground($bgId)) {
        $bgStyle = "background-image:url(" . htmlspecialchars(imageUrl($bg['path'])) . ");background-size:cover;background-position:center;";
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview</title><style>' . certificateBuilderCss() . 'body{margin:0;background:#f3effc}</style></head><body>';
    echo '<div style="' . $bgStyle . '">' . renderCertificateBody($tpl, $vars) . '</div>';
    echo '</body></html>';
    exit;
}

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE certificate_templates SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/certificate-templates.php'); exit;
}
if ($action === 'make_default' && $id) {
    $tpl = getCertificateTemplate($id);
    if ($tpl) {
        // Default is scoped per certificate type — unset only siblings of the same type.
        $db->prepare("UPDATE certificate_templates SET is_default = 0 WHERE certificate_type_id <=> ?")->execute([$tpl['certificate_type_id']]);
        $db->prepare("UPDATE certificate_templates SET is_default = 1, is_active = 1 WHERE id = ?")->execute([$id]);
    }
    header('Location: ' . SITE_URL . '/admin/certificate-templates.php'); exit;
}
if ($action === 'duplicate' && $id) {
    $src = getCertificateTemplate($id);
    if ($src) {
        unset($src['id']);
        $src['name'] = $src['name'] . ' (Copy)';
        $src['is_default'] = 0;
        $cols = array_keys($src);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $db->prepare("INSERT INTO certificate_templates ($colList) VALUES ($placeholders)")->execute(array_values($src));
        header('Location: ' . SITE_URL . '/admin/certificate-templates.php?duplicated=1'); exit;
    }
}
if ($action === 'delete' && $id) {
    $inUse = $db->prepare("SELECT COUNT(*) FROM certificates WHERE template_id = ?"); $inUse->execute([$id]);
    if ((int)$inUse->fetchColumn() > 0) {
        header('Location: ' . SITE_URL . '/admin/certificate-templates.php?error=in_use'); exit;
    }
    $db->prepare("UPDATE certificate_templates SET deleted_at=NOW(), deleted_by=? WHERE id=?")->execute([$_SESSION['admin_id'] ?? null, $id]);
    header('Location: ' . SITE_URL . '/admin/certificate-templates.php?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit') {
    $name       = trim($_POST['name'] ?? '');
    $typeId     = intval($_POST['certificate_type_id'] ?? 0) ?: null;
    $renderMode = ($_POST['render_mode'] ?? 'builder') === 'custom_html' ? 'custom_html' : 'builder';
    $html       = trim($_POST['html_template'] ?? '');
    $isActive   = isset($_POST['is_active']) ? 1 : 0;
    $bgChoice   = trim($_POST['background_choice'] ?? 'keep'); // keep | none | existing | upload
    $bgId       = intval($_POST['background_id'] ?? 0) ?: null;

    if (empty($name)) $errors[] = 'Template name is required.';
    if ($renderMode === 'custom_html' && empty($html)) $errors[] = 'HTML template is required in Custom HTML mode.';
    if (!$typeId) $errors[] = 'Certificate type is required.';

    // Background: either pick an existing library asset, upload a new one, or clear it.
    $newBgId = null;
    if ($bgChoice === 'upload') {
        $newBgId = handleCertificateBackgroundUpload('background_upload', $errors, $_SESSION['admin_id'] ?? null);
        if (!$newBgId && empty($errors)) $errors[] = 'Please choose a background file to upload.';
    } elseif ($bgChoice === 'existing') {
        $newBgId = $bgId;
    } elseif ($bgChoice === 'none') {
        $newBgId = 0; // explicit clear, handled below
    }

    if (empty($errors)) {
        $fields = [
            'name' => $name, 'certificate_type_id' => $typeId, 'render_mode' => $renderMode,
            'html_template' => $renderMode === 'custom_html' ? $html : null,
            'subtitle' => trim($_POST['subtitle'] ?? ''), 'body_text' => trim($_POST['body_text'] ?? ''),
            'footer_text' => trim($_POST['footer_text'] ?? ''),
            'signature1_name' => trim($_POST['signature1_name'] ?? ''), 'signature1_title' => trim($_POST['signature1_title'] ?? ''),
            'signature2_name' => trim($_POST['signature2_name'] ?? ''), 'signature2_title' => trim($_POST['signature2_title'] ?? ''),
            'issue_date_format' => trim($_POST['issue_date_format'] ?? 'F j, Y') ?: 'F j, Y',
            'show_qr' => isset($_POST['show_qr']) ? 1 : 0,
            'verification_url_override' => trim($_POST['verification_url_override'] ?? ''),
            'orientation' => in_array($_POST['orientation'] ?? '', ['landscape','portrait']) ? $_POST['orientation'] : 'landscape',
            'is_active' => $isActive,
        ];

        if ($id) {
            $set = implode(', ', array_map(fn($c) => "`$c`=?", array_keys($fields)));
            $params = array_values($fields);
            if ($newBgId !== null) { $set .= ", `background_id`=?"; $params[] = $newBgId ?: null; }
            $params[] = $id;
            $db->prepare("UPDATE certificate_templates SET $set WHERE id=?")->execute($params);
            $success = 'Template updated.';
        } else {
            $fields['background_id'] = $newBgId ?: null;
            $cols = array_keys($fields);
            $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
            $placeholders = implode(',', array_fill(0, count($cols), '?'));
            $db->prepare("INSERT INTO certificate_templates ($colList) VALUES ($placeholders)")->execute(array_values($fields));
            $id = (int)$db->lastInsertId();
            $success = 'Template created.';
        }
        $action = 'list'; $id = 0;
    }
}

$types = getCertificateTypes(false);
$backgrounds = getCertificateBackgrounds();
$templates = $db->query("
    SELECT ct.*, cty.label AS type_label,
           (SELECT COUNT(*) FROM certificates WHERE template_id=ct.id) AS issued_count
    FROM certificate_templates ct
    LEFT JOIN certificate_types cty ON cty.id = ct.certificate_type_id
    WHERE ct.deleted_at IS NULL
    ORDER BY ct.is_default DESC, ct.created_at DESC
")->fetchAll();

$editing = null;
if ($action === 'edit' && $id) {
    $editing = getCertificateTemplate($id);
}
?>

<?php if (isset($_GET['deleted'])): ?><div class="admin-alert admin-alert-success">Template deleted.</div><?php endif; ?>
<?php if (isset($_GET['duplicated'])): ?><div class="admin-alert admin-alert-success">Template duplicated.</div><?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'in_use'): ?><div class="admin-alert admin-alert-error">Can't delete — certificates have already been issued with this template. Deactivate it instead.</div><?php endif; ?>
<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <h1 class="admin-page-title" style="margin:0">Certificate Templates</h1>
    <a href="?action=edit" class="btn-primary btn-sm">+ New Template</a>
</div>

<div style="display:flex;gap:10px;margin-bottom:22px;flex-wrap:wrap">
    <a href="<?= SITE_URL ?>/admin/certificate-types.php" class="btn-ghost btn-sm">Manage Types</a>
    <a href="<?= SITE_URL ?>/admin/certificate-rules.php" class="btn-ghost btn-sm">Automatic Rules</a>
    <a href="<?= SITE_URL ?>/admin/certificates-issued.php" class="btn-ghost btn-sm">Issued Certificates</a>
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Name</th><th>Type</th><th>Mode</th><th>Default</th><th>Issued</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($templates as $t): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($t['name']) ?></td>
    <td style="font-size:0.8rem;color:var(--text-muted)"><?= sanitize($t['type_label'] ?? '—') ?></td>
    <td><span style="font-size:0.72rem;padding:2px 8px;border-radius:20px;background:rgba(139,92,246,0.1);color:var(--primary-bright)"><?= $t['render_mode'] === 'custom_html' ? 'Custom HTML' : 'Builder' ?></span></td>
    <td style="text-align:center"><?= $t['is_default'] ? '★' : '<a href="?action=make_default&id='.$t['id'].'" class="btn-ghost btn-sm">Set default</a>' ?></td>
    <td style="text-align:center;font-family:var(--font-mono)"><?= (int)$t['issued_count'] ?></td>
    <td style="text-align:center">
        <a href="?action=toggle&id=<?= $t['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $t['is_active'] ? 'background:rgba(34,197,94,0.12);color:#22c55e' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
            <?= $t['is_active'] ? 'Active' : 'Disabled' ?>
        </a>
    </td>
    <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            <a href="?action=edit&id=<?= $t['id'] ?>" class="btn-ghost btn-sm">Edit</a>
            <a href="?action=duplicate&id=<?= $t['id'] ?>" class="btn-ghost btn-sm">Duplicate</a>
            <a href="?action=delete&id=<?= $t['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this template?')">Delete</a>
        </div>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($templates)): ?><tr><td colspan="7" class="admin-empty">No templates yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php else: ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
    <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Template' : 'New Template' ?></h1>
    <a href="<?= SITE_URL ?>/admin/certificate-templates.php" class="btn-ghost btn-sm">← Back</a>
</div>

<form method="POST" action="?action=edit&id=<?= $id ?>" enctype="multipart/form-data" id="tplForm">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px" class="tpl-edit-grid">

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:16px">Basics</h2>

        <div class="form-group">
            <label class="form-label">Certificate Title</label>
            <input type="text" name="name" class="form-input" value="<?= sanitize($editing['name'] ?? '') ?>" placeholder="e.g. Certificate of Completion" required>
            <div class="form-hint">Rendered as the big headline. Placeholders like <code>{{certificate_title}}</code> work here too.</div>
        </div>

        <div class="form-group">
            <label class="form-label">Certificate Type</label>
            <select name="certificate_type_id" class="form-select" required>
                <option value="">— choose a type —</option>
                <?php foreach ($types as $ty): ?>
                <option value="<?= $ty['id'] ?>" <?= (int)($editing['certificate_type_id'] ?? 0) === (int)$ty['id'] ? 'selected' : '' ?>><?= $ty['icon'] ?> <?= sanitize($ty['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-hint">Determines the certificate ID prefix and which guides/rules can use this template. <a href="<?= SITE_URL ?>/admin/certificate-types.php" style="color:var(--primary-bright)">Manage types →</a></div>
        </div>

        <div class="form-group">
            <label class="form-label">Design Mode</label>
            <div style="display:flex;gap:16px">
                <label style="display:flex;align-items:center;gap:6px;font-size:0.84rem;cursor:pointer">
                    <input type="radio" name="render_mode" value="builder" id="modeBuilder" <?= ($editing['render_mode'] ?? 'builder') === 'builder' ? 'checked' : '' ?>> Builder (structured fields)
                </label>
                <label style="display:flex;align-items:center;gap:6px;font-size:0.84rem;cursor:pointer">
                    <input type="radio" name="render_mode" value="custom_html" id="modeHtml" <?= ($editing['render_mode'] ?? '') === 'custom_html' ? 'checked' : '' ?>> Custom HTML (advanced)
                </label>
            </div>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
                <input type="checkbox" name="is_active" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>> Active
            </label>
        </div>

        <!-- ── Builder fields ── -->
        <div id="builderFields">
            <div class="form-group">
                <label class="form-label">Subtitle</label>
                <input type="text" name="subtitle" class="form-input" value="<?= sanitize($editing['subtitle'] ?? 'This certifies that') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Body Text</label>
                <textarea name="body_text" class="form-input" rows="3" placeholder="has successfully completed {{certificate_title}}"><?= sanitize($editing['body_text'] ?? 'has successfully completed {{certificate_title}}') ?></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Footer Text</label>
                <input type="text" name="footer_text" class="form-input" value="<?= sanitize($editing['footer_text'] ?? '') ?>" placeholder="e.g. Issued by {{site_name}}">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label class="form-label">Signature 1 Name</label>
                    <input type="text" name="signature1_name" class="form-input" value="<?= sanitize($editing['signature1_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Signature 1 Title</label>
                    <input type="text" name="signature1_title" class="form-input" value="<?= sanitize($editing['signature1_title'] ?? '') ?>" placeholder="e.g. Lead Instructor">
                </div>
                <div class="form-group">
                    <label class="form-label">Signature 2 Name</label>
                    <input type="text" name="signature2_name" class="form-input" value="<?= sanitize($editing['signature2_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Signature 2 Title</label>
                    <input type="text" name="signature2_title" class="form-input" value="<?= sanitize($editing['signature2_title'] ?? '') ?>" placeholder="e.g. Founder">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label class="form-label">Issue Date Format</label>
                    <select name="issue_date_format" class="form-select">
                        <?php $fmt = $editing['issue_date_format'] ?? 'F j, Y'; foreach (['F j, Y'=>'July 17, 2026','Y-m-d'=>'2026-07-17','d/m/Y'=>'17/07/2026','M d, Y'=>'Jul 17, 2026'] as $f=>$label): ?>
                        <option value="<?= $f ?>" <?= $fmt===$f?'selected':'' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Orientation</label>
                    <select name="orientation" class="form-select">
                        <option value="landscape" <?= ($editing['orientation'] ?? 'landscape')==='landscape'?'selected':'' ?>>Landscape (A4)</option>
                        <option value="portrait" <?= ($editing['orientation'] ?? '')==='portrait'?'selected':'' ?>>Portrait (A4)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
                    <input type="checkbox" name="show_qr" <?= ($editing['show_qr'] ?? 1) ? 'checked' : '' ?>> Show QR verification code
                </label>
            </div>
            <div class="form-group">
                <label class="form-label">Verification URL Override <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                <input type="text" name="verification_url_override" class="form-input" value="<?= sanitize($editing['verification_url_override'] ?? '') ?>" placeholder="Defaults to {{verification_url}}">
            </div>
        </div>

        <!-- ── Custom HTML field ── -->
        <div id="htmlFields" style="display:none">
            <div class="form-group">
                <label class="form-label">HTML Template</label>
                <textarea name="html_template" class="form-input" rows="12" style="font-family:var(--font-mono);font-size:0.82rem"><?= sanitize($editing['html_template'] ?? '') ?></textarea>
                <div class="form-hint">
                    Placeholders: <code>{{username}}</code>, <code>{{full_name}}</code>, <code>{{certificate_title}}</code>,
                    <code>{{guide_name}}</code>, <code>{{course_name}}</code>, <code>{{learning_path}}</code>,
                    <code>{{issue_date}}</code>, <code>{{completion_date}}</code>, <code>{{certificate_id}}</code>,
                    <code>{{site_name}}</code>, <code>{{site_url}}</code>, <code>{{verification_url}}</code>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:8px">
            <button type="submit" class="btn-primary" style="flex:1"><?= $id ? 'Save Changes' : 'Create Template' ?></button>
            <button type="button" class="btn-ghost" onclick="openPreview()">Preview</button>
        </div>
    </div>

    <!-- ── Right: Background ── -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:24px">
        <h2 style="font-size:0.9rem;font-weight:700;margin-bottom:16px">Background Design</h2>

        <?php if (!empty($editing['background_id']) && $curBg = getCertificateBackground((int)$editing['background_id'])): ?>
        <div style="margin-bottom:14px">
            <div class="form-hint" style="margin-bottom:6px">Current background:</div>
            <img src="<?= sanitize(imageUrl($curBg['path'])) ?>" alt="" style="max-width:100%;border-radius:8px;border:1px solid var(--border)">
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label style="display:block;margin-bottom:6px"><input type="radio" name="background_choice" value="keep" checked> Keep current background</label>
            <label style="display:block;margin-bottom:6px"><input type="radio" name="background_choice" value="none"> No background (plain)</label>
            <label style="display:block;margin-bottom:6px"><input type="radio" name="background_choice" value="existing"> Choose from library</label>
            <label style="display:block"><input type="radio" name="background_choice" value="upload"> Upload new (PNG / JPG / SVG)</label>
        </div>

        <div class="form-group">
            <select name="background_id" class="form-select">
                <option value="">— select from library —</option>
                <?php foreach ($backgrounds as $bg): ?>
                <option value="<?= $bg['id'] ?>"><?= sanitize($bg['name'] ?: $bg['path']) ?> <?= $bg['is_svg'] ? '(SVG)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <input type="file" name="background_upload" class="form-input" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml">
            <div class="form-hint">Becomes the certificate background — content renders on top of it. SVGs are sanitized on upload.</div>
        </div>

        <h2 style="font-size:0.9rem;font-weight:700;margin:24px 0 12px">Placeholder Reference</h2>
        <div style="font-size:0.78rem;color:var(--text-muted);line-height:1.9">
            <code>{{username}}</code> <code>{{full_name}}</code> <code>{{certificate_title}}</code><br>
            <code>{{guide_name}}</code> <code>{{course_name}}</code> <code>{{learning_path}}</code><br>
            <code>{{issue_date}}</code> <code>{{completion_date}}</code> <code>{{certificate_id}}</code><br>
            <code>{{site_name}}</code> <code>{{site_url}}</code> <code>{{verification_url}}</code>
        </div>
    </div>

    </div>
</form>

<style>@media (max-width: 750px) { .tpl-edit-grid { grid-template-columns: 1fr !important; } }</style>

<script>
function toggleMode() {
    const isHtml = document.getElementById('modeHtml').checked;
    document.getElementById('builderFields').style.display = isHtml ? 'none' : 'block';
    document.getElementById('htmlFields').style.display = isHtml ? 'block' : 'none';
}
document.getElementById('modeBuilder').addEventListener('change', toggleMode);
document.getElementById('modeHtml').addEventListener('change', toggleMode);
toggleMode();

function openPreview() {
    const form = document.getElementById('tplForm');
    const win = window.open('', '_blank');
    const data = new FormData(form);
    data.set('action', 'preview_render');
    fetch('?action=preview_render', { method: 'POST', body: data })
        .then(r => r.text())
        .then(html => { win.document.open(); win.document.write(html); win.document.close(); })
        .catch(() => { win.close(); alert('Preview failed.'); });
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

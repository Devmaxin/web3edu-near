<?php
$adminTitle = 'Email Templates';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/email/EmailService.php';

$db = getDB();
$action = $_GET['action'] ?? 'list';
$id = intval($_GET['id'] ?? 0);
$success = ''; $errors = []; $testResult = null;

if ($action === 'toggle' && $id) {
    $db->prepare("UPDATE email_templates SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/email-templates.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'send_test') {
        $testEmail = trim($_POST['test_email'] ?? '');
        $tplKey = trim($_POST['template_key'] ?? '');
        $sampleVars = [
            'username' => 'Maxin', 'plan_name' => 'Yearly', 'renewal_date' => date('F j, Y', strtotime('+1 year')),
            'days_remaining' => 7, 'expiry_date' => date('F j, Y', strtotime('+7 days')), 'amount' => '30,000.00',
            'title' => 'Blockchain Fundamentals', 'date' => date('F j, Y'), 'certificate_code' => 'W3E-SAMPLE01',
            'subject' => 'A note from Web3Edu', 'message' => 'This is a sample notification message.',
        ];
        if ($testEmail && $tplKey) {
            $testResult = sendTestEmail($tplKey, $testEmail, $sampleVars);
            $success = $testResult['success'] ? "Test email sent to {$testEmail}." : '';
            if (!$testResult['success']) $errors[] = 'Send failed: ' . $testResult['error'];
        }
        $action = 'edit'; $id = (int)($_POST['id'] ?? 0);
    } elseif (($_POST['action'] ?? '') === 'save') {
        $subject = trim($_POST['subject'] ?? '');
        $htmlBody = trim($_POST['html_body'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($subject) || empty($htmlBody)) {
            $errors[] = 'Subject and HTML body are required.';
        } else {
            $db->prepare("UPDATE email_templates SET subject=?, html_body=?, is_active=? WHERE id=?")
               ->execute([$subject, $htmlBody, $isActive, $id]);
            $success = 'Template saved.';
        }
        $action = 'edit';
    }
}

$templates = $db->query("SELECT * FROM email_templates ORDER BY template_key ASC")->fetchAll();
$editing = null;
if ($action === 'edit' && $id) {
    $stmt = $db->prepare("SELECT * FROM email_templates WHERE id=?"); $stmt->execute([$id]);
    $editing = $stmt->fetch();
    if (!$editing) { header('Location: ' . SITE_URL . '/admin/email-templates.php'); exit; }
}

$friendlyNames = [
    'welcome' => 'Welcome', 'verification' => 'Email Verification', 'forgot_password' => 'Forgot Password',
    'password_changed' => 'Password Changed', 'premium_purchase' => 'Premium Purchased', 'premium_renewed' => 'Premium Renewed',
    'premium_expiring' => 'Premium Expiring', 'premium_expired' => 'Premium Expired', 'guide_completion' => 'Guide Completed',
    'certificate' => 'Certificate Issued', 'announcement' => 'Announcement', 'general_notification' => 'General Notification',
];

$previewSampleVars = [
    'username' => 'Maxin', 'plan_name' => 'Yearly', 'renewal_date' => date('F j, Y', strtotime('+1 year')),
    'days_remaining' => 7, 'expiry_date' => date('F j, Y', strtotime('+7 days')), 'amount' => '30,000.00',
    'title' => 'Blockchain Fundamentals', 'date' => date('F j, Y'), 'certificate_code' => 'W3E-SAMPLE01',
    'subject' => 'A note from Web3Edu', 'message' => 'This is a sample notification message.',
];
?>

<?php if ($success): ?><div class="admin-alert admin-alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if (!empty($errors)): ?><div class="admin-alert admin-alert-error"><?php foreach ($errors as $e): ?><div><?= sanitize($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<?php if ($action === 'list'): ?>

<h1 class="admin-page-title" style="margin-bottom:24px">Email Templates</h1>

<div style="background:rgba(139,92,246,0.07);border:1px solid rgba(139,92,246,0.2);border-radius:var(--radius);padding:12px 16px;margin-bottom:22px;font-size:0.84rem;color:var(--text-muted)">
    Emails are queued, not sent instantly — a cron worker (<code>cron/email-cron.php</code>) delivers them via Resend. Disabling a template stops that email type from being sent.
</div>

<div class="admin-table-wrap">
<table class="admin-table">
<thead><tr><th>Email</th><th>Key</th><th>Subject</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($templates as $t): ?>
<tr>
    <td style="font-weight:600"><?= sanitize($friendlyNames[$t['template_key']] ?? $t['template_key']) ?></td>
    <td style="font-family:var(--font-mono);font-size:0.78rem;color:var(--text-muted)"><?= sanitize($t['template_key']) ?></td>
    <td style="font-size:0.82rem"><?= sanitize($t['subject']) ?></td>
    <td>
        <a href="?action=toggle&id=<?= $t['id'] ?>" style="padding:3px 10px;border-radius:20px;font-size:0.72rem;font-weight:700;text-decoration:none;<?= $t['is_active'] ? 'background:rgba(11,121,88,0.12);color:#0B7958' : 'background:rgba(148,148,163,0.12);color:var(--text-muted)' ?>">
            <?= $t['is_active'] ? 'Active' : 'Disabled' ?>
        </a>
    </td>
    <td><a href="?action=edit&id=<?= $t['id'] ?>" class="btn-ghost btn-sm">Edit</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php else: ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
    <h1 class="admin-page-title" style="margin:0"><?= sanitize($friendlyNames[$editing['template_key']] ?? $editing['template_key']) ?></h1>
    <a href="<?= SITE_URL ?>/admin/email-templates.php" class="btn-ghost btn-sm">← Back</a>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:start">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:28px">
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label class="form-label">Subject</label>
            <input type="text" name="subject" class="form-input" value="<?= sanitize($editing['subject']) ?>" required>
        </div>
        <div class="form-group">
            <label class="form-label">HTML Body</label>
            <textarea name="html_body" class="form-input" rows="14" style="font-family:var(--font-mono);font-size:0.82rem" required><?= sanitize($editing['html_body']) ?></textarea>
            <div class="form-hint">Available placeholders: <code><?= sanitize($editing['variables_hint'] ?? 'none documented') ?></code></div>
        </div>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
                <input type="checkbox" name="is_active" <?= $editing['is_active'] ? 'checked' : '' ?>> Active
            </label>
        </div>
        <button type="submit" class="btn-primary">Save Template</button>
    </form>
    </div>

    <div>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px">
            <h3 style="font-size:0.9rem;margin-bottom:12px">Send Test Email</h3>
            <form method="POST">
                <input type="hidden" name="action" value="send_test">
                <input type="hidden" name="id" value="<?= $editing['id'] ?>">
                <input type="hidden" name="template_key" value="<?= sanitize($editing['template_key']) ?>">
                <div class="form-group">
                    <input type="email" name="test_email" class="form-input" placeholder="you@example.com" required>
                </div>
                <button type="submit" class="btn-secondary btn-sm" style="width:100%">Send Test</button>
                <div class="form-hint">Sends immediately with sample data, bypassing the queue.</div>
            </form>
        </div>

        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px">
            <h3 style="font-size:0.9rem;margin-bottom:12px">Preview</h3>
            <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden">
                <iframe srcdoc="<?= htmlspecialchars(renderEmailLayout(renderTemplateString($editing['html_body'], $previewSampleVars), renderTemplateString($editing['subject'], $previewSampleVars))) ?>" style="width:100%;height:400px;border:none;background:#fff"></iframe>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

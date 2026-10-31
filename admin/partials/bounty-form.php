<?php
/**
 * Shared Bounty form — used by both admin/bounties.php (Create Official Bounty)
 * and admin/bounty-edit.php (Edit any bounty). Feature 3: "reuse the existing
 * Create Bounty form where possible instead of duplicating code."
 *
 * Expects, set by the includer:
 *   $formMode    'create' | 'edit'
 *   $formAction  form action URL
 *   $bounty      array of existing bounty fields (empty array for create)
 *   $categories  result of getBountyCategories(false)
 */
$formMode   = $formMode ?? 'create';
$bounty     = $bounty ?? [];
$isEdit     = $formMode === 'edit';
$bType      = $bounty['type'] ?? 'official';
$breakdown  = $isEdit ? (json_decode($bounty['reward_breakdown'] ?? '[]', true) ?: []) : [];
?>
<form method="POST" action="<?= htmlspecialchars($formAction) ?>" enctype="multipart/form-data" class="bounty-admin-form">
    <?= csrfField() ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
            <label class="form-label"><?= lucide('tag', ['size' => 13]) ?> Bounty Type</label>
            <select name="<?= $isEdit ? 'type' : 'bounty_type' ?>" id="bountyTypeSelect" class="form-select" <?= $isEdit ? '' : 'onchange="toggleBountyTypeFields(this.value)"' ?>>
                <option value="official" <?= $bType === 'official' ? 'selected' : '' ?>>👑 Official</option>
                <option value="hosted" <?= $bType === 'hosted' ? 'selected' : '' ?>>Hosted</option>
                <option value="external" <?= $bType === 'external' ? 'selected' : '' ?>>🔗 External</option>
            </select>
        </div>
        <?php if ($isEdit): ?>
        <div class="form-group">
            <label class="form-label"><?= lucide('check-circle', ['size' => 13]) ?> Status</label>
            <select name="status" class="form-select">
                <?php foreach (['draft', 'pending_review', 'awaiting_payment', 'published', 'judging', 'winners_announced', 'archived', 'rejected'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($bounty['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$isEdit): ?>
    <div id="hostedFieldsGroup" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:14px">
        <p style="font-size:0.78rem;color:var(--text-muted);margin:0 0 10px">Attaches this bounty to a real Web3Edu account — it'll then behave exactly like a bounty that host created themselves (their own Host Dashboard, Edit/Archive/Winners, etc).</p>
        <div class="form-group"><label class="form-label">Host Account (username or email)</label><input type="text" name="host_account" class="form-input"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label class="form-label">Organization Name</label><input type="text" name="organization_name" class="form-input"></div>
            <div class="form-group"><label class="form-label">Contact Email</label><input type="email" name="contact_email" class="form-input"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="form-group"><label class="form-label">Company Website</label><input type="text" name="host_website" class="form-input" placeholder="https://"></div>
            <div class="form-group"><label class="form-label">Company Logo</label><input type="file" name="host_logo" class="form-input" accept="image/*"></div>
        </div>
    </div>
    <div id="externalFieldsGroupCreate" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:14px;margin-bottom:14px">
        <div class="form-group"><label class="form-label"><?= lucide('external-link', ['size' => 13]) ?> External URL</label><input type="text" name="external_url" class="form-input" placeholder="https://"></div>
        <div class="form-group"><label class="form-label">Hosting Platform</label><input type="text" name="external_platform" class="form-input" placeholder="e.g. Superteam Earn"></div>
    </div>
    <?php endif; ?>

    <div class="form-group"><label class="form-label">Title</label><input type="text" name="title" class="form-input" value="<?= htmlspecialchars($bounty['title'] ?? '') ?>" required></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select">
                <option value="">— none —</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)($bounty['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= $c['icon'] ?> <?= sanitize($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label class="form-label">Project Name</label><input type="text" name="project_name" class="form-input" value="<?= htmlspecialchars($bounty['project_name'] ?? '') ?>"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group">
            <label class="form-label"><?= lucide('image', ['size' => 13]) ?> Project Logo</label>
            <?php if (!empty($bounty['project_logo'])): ?>
                <img src="<?= htmlspecialchars(imageUrl($bounty['project_logo'], 'lesson')) ?>" alt="" style="width:40px;height:40px;border-radius:8px;object-fit:cover;margin-bottom:6px;display:block">
            <?php endif; ?>
            <input type="file" name="project_logo" class="form-input" accept="image/*">
        </div>
        <div class="form-group">
            <label class="form-label"><?= lucide('image', ['size' => 13]) ?> Cover Image</label>
            <?php if (!empty($bounty['cover_image'])): ?>
                <img src="<?= htmlspecialchars(imageUrl($bounty['cover_image'], 'lesson')) ?>" alt="" style="width:100%;max-width:220px;height:60px;border-radius:8px;object-fit:cover;margin-bottom:6px;display:block">
            <?php endif; ?>
            <input type="file" name="cover_image" class="form-input" accept="image/*">
        </div>
    </div>

    <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-input" rows="3" <?= $isEdit ? '' : 'required' ?>><?= htmlspecialchars($bounty['description'] ?? '') ?></textarea></div>
    <div class="form-group"><label class="form-label">Submission Requirements</label><textarea name="requirements" class="form-input" rows="2"><?= htmlspecialchars($bounty['requirements'] ?? '') ?></textarea></div>
    <div class="form-group"><label class="form-label">Skills</label><input type="text" name="skills" class="form-input" placeholder="Comma-separated" value="<?= htmlspecialchars($bounty['skills'] ?? '') ?>"></div>

    <?php if ($isEdit): ?>
    <div class="form-group" id="externalUrlGroup" style="<?= $bType === 'external' ? '' : 'display:none' ?>">
        <label class="form-label"><?= lucide('external-link', ['size' => 13]) ?> External URL</label>
        <input type="text" name="external_url" class="form-input" placeholder="https://" value="<?= htmlspecialchars($bounty['external_url'] ?? '') ?>">
        <label class="form-label" style="margin-top:8px">Hosting Platform</label>
        <input type="text" name="external_platform" class="form-input" placeholder="e.g. Superteam Earn" value="<?= htmlspecialchars($bounty['external_platform'] ?? '') ?>">
    </div>
    <?php endif; ?>

    <div class="form-group"><label class="form-label">Reward Summary</label><input type="text" name="reward_display" class="form-input" placeholder="e.g. 5,000 USDC Total Prizes" value="<?= htmlspecialchars($bounty['reward_display'] ?? '') ?>" <?= $isEdit ? '' : 'required' ?>></div>
    <div class="form-group">
        <label class="form-label">Prize Breakdown</label>
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div style="display:flex;gap:8px;margin-bottom:8px">
            <input type="text" name="prize_place[]" class="form-input" placeholder="<?= ['1st', '2nd', '3rd'][$i] ?>" style="max-width:100px" value="<?= htmlspecialchars($breakdown[$i]['place'] ?? '') ?>">
            <input type="text" name="prize_amount[]" class="form-input" placeholder="Amount" value="<?= htmlspecialchars($breakdown[$i]['amount'] ?? '') ?>">
        </div>
        <?php endfor; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label"><?= lucide('users', ['size' => 13]) ?> Winners</label><input type="number" name="num_winners" class="form-input" value="<?= (int)($bounty['num_winners'] ?? 1) ?>" min="1"></div>
        <div class="form-group">
            <label class="form-label">Difficulty</label>
            <select name="difficulty" class="form-select">
                <?php foreach (['Beginner', 'Intermediate', 'Advanced'] as $d): ?>
                    <option <?= ($bounty['difficulty'] ?? 'Intermediate') === $d ? 'selected' : '' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label"><?= lucide('calendar', ['size' => 13]) ?> Deadline</label><input type="date" name="deadline" class="form-input" value="<?= !empty($bounty['deadline']) ? date('Y-m-d', strtotime($bounty['deadline'])) : '' ?>"></div>
        <div class="form-group"><label class="form-label"><?= lucide('calendar', ['size' => 13]) ?> Winner Announcement</label><input type="date" name="winner_announcement_date" class="form-input" value="<?= !empty($bounty['winner_announcement_date']) ? date('Y-m-d', strtotime($bounty['winner_announcement_date'])) : '' ?>"></div>
    </div>
    <div class="form-group"><label class="form-label">Country (optional)</label><input type="text" name="country" class="form-input" placeholder="Leave blank for Global" value="<?= htmlspecialchars($bounty['country'] ?? '') ?>"></div>

    <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem"><input type="checkbox" name="is_featured" <?= !empty($bounty['is_featured']) ? 'checked' : '' ?>> <?= lucide('star', ['size' => 14]) ?> Feature on homepage</label>
    </div>

    <div class="form-group" id="premiumOnlyGroup" style="<?= $bType === 'external' ? 'display:none' : '' ?>">
        <label style="display:flex;align-items:center;gap:8px;font-size:0.85rem">
            <input type="checkbox" name="is_premium_only" <?= !empty($bounty['is_premium_only']) ? 'checked' : '' ?>>
            <?= lucide('lock', ['size' => 14]) ?> Premium Only <span style="color:var(--text-muted);font-weight:400">— only Premium members can submit entries</span>
        </label>
    </div>

    <button type="submit" class="btn-primary" style="width:100%">
        <?= $isEdit ? 'Save Changes' : 'Publish Bounty' ?>
    </button>
</form>

<?php if (!$isEdit): ?>
<script>
function toggleBountyTypeFields(val) {
    var hosted = document.getElementById('hostedFieldsGroup');
    var external = document.getElementById('externalFieldsGroupCreate');
    var prem = document.getElementById('premiumOnlyGroup');
    if (hosted) hosted.style.display = (val === 'hosted') ? '' : 'none';
    if (external) external.style.display = (val === 'external') ? '' : 'none';
    if (prem) prem.style.display = (val === 'external') ? 'none' : '';
}
</script>
<?php endif; ?>

<?php if ($isEdit): ?>
<script>
(function() {
    const typeSelect = document.getElementById('bountyTypeSelect');
    const extGroup = document.getElementById('externalUrlGroup');
    const premGroup = document.getElementById('premiumOnlyGroup');
    if (!typeSelect) return;
    typeSelect.addEventListener('change', function() {
        const isExternal = this.value === 'external';
        extGroup.style.display = isExternal ? '' : 'none';
        premGroup.style.display = isExternal ? 'none' : '';
    });
})();
</script>
<?php endif; ?>

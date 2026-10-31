<?php
/**
 * Shared "Related Content" section — bottom of every lesson page.
 * Expects in scope: $lesson (array with 'id' and 'category'), and — only
 * meaningful in guide context — $guideId (int|null).
 */
$rcGuideId = isset($guideId) ? (int)$guideId : null;
$rcLessons  = $related ?? getRelatedLessons((int)$lesson['id'], $rcGuideId, 4);
$rcGuides   = $rcGuideId ? getRelatedGuides($rcGuideId, 3) : [];
$rcProjects = getRelatedProjects($rcGuideId, null, 3);
?>
<?php if (!empty($rcLessons) || !empty($rcGuides) || !empty($rcProjects)): ?>
<div class="related-content-section">
    <?php if (!empty($rcLessons)): ?>
    <h3 class="related-content-heading">Related Lessons</h3>
    <div class="related-content-grid">
        <?php foreach ($rcLessons as $rl): ?>
        <a href="<?= $rcGuideId ? guideLessonUrl($rcGuideId, $rl['id']) : SITE_URL . '/learn/lesson/' . $rl['id'] ?>" class="card related-content-card"><?= sanitize($rl['title']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($rcGuides)): ?>
    <h3 class="related-content-heading">Related Guides</h3>
    <div class="related-content-grid">
        <?php foreach ($rcGuides as $rg): ?>
        <a href="<?= SITE_URL ?>/guide.php?guide=<?= (int)$rg['id'] ?>" class="card related-content-card"><?= sanitize($rg['title']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($rcProjects)): ?>
    <h3 class="related-content-heading">Related Projects</h3>
    <div class="related-content-grid">
        <?php foreach ($rcProjects as $rp): ?>
        <a href="<?= SITE_URL ?>/projects/submit.php?project_id=<?= (int)$rp['id'] ?><?= $rcGuideId ? '&guide_id=' . $rcGuideId : '' ?>" class="card related-content-card">🛠️ <?= sanitize($rp['title']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

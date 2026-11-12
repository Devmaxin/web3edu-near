<?php
$adminTitle = 'Manage Blog';
require_once __DIR__ . '/header.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$errors = [];
$success = '';

if ($action === 'delete' && $id) {
    $stmt = $db->prepare("SELECT cover_image FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['cover_image']) deleteUploadedFile($row['cover_image']);
    $db->prepare("DELETE FROM blog_posts WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/blog.php?deleted=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title'] ?? '');
    $excerpt      = trim($_POST['excerpt'] ?? '');
    $content      = trim($_POST['content'] ?? '');
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $is_featured  = isset($_POST['is_featured'])  ? 1 : 0;

    if (empty($title))   $errors[] = 'Title is required.';
    if (empty($content)) $errors[] = 'Content is required.';

    $newCover = handleImageUpload('cover_image', 'blog', $errors);

    if (empty($errors)) {
        $slug = generateSlug($title);
        if ($id) {
            $stmt = $db->prepare("SELECT cover_image FROM blog_posts WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetchColumn();
            if ($newCover) {
                if ($existing) deleteUploadedFile($existing);
                $cover = $newCover;
            } else {
                $cover = $existing;
            }
            $db->prepare("UPDATE blog_posts SET title=?,excerpt=?,content=?,cover_image=?,is_published=?,is_featured=? WHERE id=?")
               ->execute([$title,$excerpt,$content,$cover,$is_published,$is_featured,$id]);
            $success = 'Post updated!';
        } else {
            $slug  = uniqueSlug('blog_posts', $slug);
            $cover = $newCover;
            $db->prepare("INSERT INTO blog_posts (title,slug,excerpt,content,cover_image,is_published,is_featured) VALUES (?,?,?,?,?,?,?)")
               ->execute([$title,$slug,$excerpt,$content,$cover,$is_published,$is_featured]);
            $id = $db->lastInsertId(); $success = 'Post created!'; $action = 'edit';
        }
    }
}

$post = null;
if (($action === 'edit') && $id) {
    $stmt = $db->prepare("SELECT * FROM blog_posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch();
    if (!$post) { header('Location: ' . SITE_URL . '/admin/blog.php'); exit; }
}

$posts = $db->query("SELECT * FROM blog_posts ORDER BY created_at DESC")->fetchAll();
?>

<?php if (isset($_GET['deleted'])): ?>
<div style="background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#0B7958;font-size:0.875rem"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg> Post deleted.</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
  <h1 class="admin-page-title" style="margin:0;display:flex;align-items:center;gap:8px">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary-bright)"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
    Blog Posts (<?= count($posts) ?>)
  </h1>
  <a href="?action=new" class="btn-primary">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Post
  </a>
</div>

<div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
  <table class="admin-table">
    <thead><tr><th>Cover</th><th>Title</th><th>Published</th><th>Featured</th><th>Date</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($posts as $p): ?>
    <tr>
      <td>
        <?php if ($p['cover_image']): ?>
        <img src="<?= imageUrl($p['cover_image'],'blog') ?>" style="width:56px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
        <?php else: ?>
        <div style="width:56px;height:40px;background:var(--bg-card2);border-radius:6px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted)"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
        </div>
        <?php endif; ?>
      </td>
      <td style="font-weight:600;font-size:0.875rem"><?= sanitize($p['title']) ?></td>
      <td style="text-align:center">
        <?php if ($p['is_published']): ?>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#0B7958" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <?php else: ?>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
        <?php endif; ?>
      </td>
      <td style="text-align:center">
        <?php if ($p['is_featured']): ?>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#fbbf24" stroke="#fbbf24" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <?php else: ?>
        <span style="color:var(--text-muted)">—</span>
        <?php endif; ?>
      </td>
      <td style="color:var(--text-muted);font-size:0.78rem"><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
      <td>
        <div style="display:flex;gap:6px">
          <a href="?action=edit&id=<?= $p['id'] ?>" class="btn-ghost btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit
          </a>
          <a href="<?= SITE_URL ?>/blog-post.php?slug=<?= urlencode($p['slug']) ?>" target="_blank" class="btn-secondary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> View
          </a>
          <a href="?action=delete&id=<?= $p['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this post?')">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg> Del
          </a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: // Form ?>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
  <a href="?action=list" style="color:var(--text-muted);font-size:0.875rem;display:flex;align-items:center;gap:5px">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Blog
  </a>
  <span style="color:var(--border)">/</span>
  <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Post' : 'New Post' ?></h1>
</div>

<?php if (!empty($errors)): ?>
<div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:var(--radius);padding:14px;margin-bottom:20px">
  <?php foreach ($errors as $e): ?><div style="color:#fca5a5;font-size:0.84rem"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?= sanitize($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($success): ?>
<div style="background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#0B7958;font-size:0.875rem"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg> <?= sanitize($success) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" style="display:grid;grid-template-columns:1fr 300px;gap:24px;align-items:flex-start">
  <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:28px">
    <div class="form-group"><label class="form-label">Title *</label>
    <input type="text" name="title" class="form-input" required value="<?= sanitize($post['title'] ?? '') ?>" placeholder="Post title..."></div>
    <div class="form-group"><label class="form-label">Excerpt <span style="color:var(--text-muted);font-weight:400;font-size:0.75rem">— short summary shown in lists</span></label>
    <textarea name="excerpt" class="form-textarea" rows="3" placeholder="Short summary..."><?= htmlspecialchars($post['excerpt'] ?? '') ?></textarea></div>
    <div class="form-group" style="margin-bottom:0"><label class="form-label">Content * <span style="color:var(--text-muted);font-weight:400;font-size:0.75rem">— HTML supported</span></label>
    <textarea name="content" class="form-textarea" rows="16"><?= htmlspecialchars($post['content'] ?? '') ?></textarea></div>
  </div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <!-- Cover image -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:14px;display:flex;align-items:center;gap:7px">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
        Cover Image
      </h3>
      <?php if (!empty($post['cover_image'])): ?>
      <img src="<?= imageUrl($post['cover_image'],'blog') ?>" style="width:100%;height:130px;object-fit:cover;border-radius:var(--radius);border:1.5px solid var(--border);margin-bottom:12px">
      <?php endif; ?>
      <label style="display:block;padding:18px;background:var(--bg-card2);border:2px dashed var(--border);border-radius:var(--radius);text-align:center;cursor:pointer;transition:all 0.2s"
             onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);margin-bottom:6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div style="color:var(--text-muted);font-size:0.8rem">Upload cover image</div>
        <div style="color:var(--text-muted);font-size:0.7rem;margin-top:3px">JPG, PNG, WebP · Max 5MB</div>
        <input type="file" name="cover_image" accept="image/*" style="display:none" onchange="previewImage(this,'coverPreview',this.parentElement)">
      </label>
      <img id="coverPreview" src="" alt="" style="display:none;width:100%;height:130px;object-fit:cover;border-radius:var(--radius);border:1.5px solid var(--primary);margin-top:10px">
    </div>

    <!-- Options -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px;display:flex;flex-direction:column;gap:12px">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:0.875rem">
        <input type="checkbox" name="is_published" <?= ($post['is_published']??0)?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--primary)">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#0B7958"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Published (visible to users)
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:0.875rem">
        <input type="checkbox" name="is_featured" <?= ($post['is_featured']??0)?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--primary)">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#fbbf24" stroke="#fbbf24" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        Featured post
      </label>
    </div>

    <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:12px">
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      <?= $id ? 'Update Post' : 'Publish Post' ?>
    </button>
  </div>
</form>

<script>
function previewImage(input, previewId, dropzoneEl) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById(previewId) || input.parentElement.nextElementSibling;
    if (preview && preview.tagName === 'IMG') {
      preview.src = e.target.result;
      preview.style.display = 'block';
    }
    if (dropzoneEl) dropzoneEl.style.borderColor = 'var(--primary)';
  };
  reader.readAsDataURL(input.files[0]);
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>

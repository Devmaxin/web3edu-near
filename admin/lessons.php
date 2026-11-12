<?php
$adminTitle = 'Manage Lessons';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/video_helpers.php';
require_once __DIR__ . '/../includes/html_sanitizer.php';

$db     = getDB();
$action = $_GET['action'] ?? 'list';
$id     = intval($_GET['id'] ?? 0);
$errors = [];
$success = '';

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    $stmt = $db->prepare("SELECT thumbnail FROM lessons WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['thumbnail']) deleteUploadedFile($row['thumbnail']);
    $db->prepare("DELETE FROM lessons WHERE id = ?")->execute([$id]);
    header('Location: ' . SITE_URL . '/admin/lessons.php?deleted=1'); exit;
}

// ── Save (POST) ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim($_POST['title'] ?? '');
    $category        = trim($_POST['category'] ?? 'General');
    $preview_content = trim($_POST['preview_content'] ?? '');
    // full_content now arrives as HTML from CKEditor 5 — always sanitize
    // server-side before it ever touches the database. Never trust the
    // client; CKEditor's own output is authored by an admin but the HTML
    // sanitization here is the actual XSS boundary regardless of who is
    // (supposed to be) sending it.
    $full_content    = sanitize_lesson_html(trim($_POST['full_content'] ?? ''));
    $access_level    = in_array($_POST['access_level'] ?? '', ['free','premium']) ? $_POST['access_level'] : 'free';
    $duration        = trim($_POST['duration'] ?? '10 min');
    $is_featured     = isset($_POST['is_featured']) ? 1 : 0;
    $video_url_raw   = trim($_POST['video_url'] ?? '');
    $sortOrder       = intval($_POST['sort_order'] ?? 0);
    $video_category_id = intval($_POST['video_category_id'] ?? 0) ?: null;

    // Extract video ID from pasted URL
    $video_id = null;
    if (!empty($video_url_raw)) {
        $video_id = validateYouTubeUrl($video_url_raw, $errors);
        // Normalise stored URL to standard watch format
        $video_url_stored = $video_id ? "https://www.youtube.com/watch?v={$video_id}" : null;
    } else {
        $video_url_stored = null;
    }

    if (empty($title)) $errors[] = 'Title is required.';

    // Thumbnail upload (still supported for non-video cards override)
    $newThumb = handleImageUpload('thumbnail', 'lessons', $errors);

    // Audio upload
    $newAudio = handleAudioUpload('audio_file', $errors);

    if (empty($errors)) {
        $slug = generateSlug($title);

        if ($id) {
            // Fetch existing record (must include audio_url to preserve it on update)
            $stmt = $db->prepare("SELECT thumbnail, audio_url FROM lessons WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();

            if ($newThumb) {
                if ($existing['thumbnail']) deleteUploadedFile($existing['thumbnail']);
                $thumb = $newThumb;
            } else {
                $thumb = $existing['thumbnail'];
            }

            // Audio: replace old file if new one uploaded
            $existAudio = $existing['audio_url'] ?? null;
            if ($newAudio) {
                if ($existAudio) deleteUploadedFile($existAudio);
                $audioUrl = $newAudio;
            } else {
                $audioUrl = $existAudio;
            }
            // Allow explicit removal
            if (isset($_POST['remove_audio']) && $_POST['remove_audio'] === '1') {
                if ($existAudio) deleteUploadedFile($existAudio);
                $audioUrl = null;
            }

            $db->prepare("UPDATE lessons
                SET title=?,slug=?,category=?,preview_content=?,full_content=?,
                    video_url=?,video_id=?,access_level=?,duration=?,is_featured=?,
                    thumbnail=?,sort_order=?,audio_url=?,video_category_id=?
                WHERE id=?")
               ->execute([
                   $title,$slug,$category,$preview_content,$full_content,
                   $video_url_stored,$video_id,$access_level,$duration,$is_featured,
                   $thumb,$sortOrder,$audioUrl,$video_category_id,$id
               ]);
            $success = 'Lesson updated!';
        } else {
            $slug  = uniqueSlug('lessons', $slug);

            // Auto-assign sort_order if not set
            if ($sortOrder === 0) {
                $maxPos = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM lessons")->fetchColumn();
                $sortOrder = $maxPos + 1;
            }

            $db->prepare("INSERT INTO lessons
                (title,slug,category,preview_content,full_content,video_url,video_id,
                 access_level,duration,is_featured,thumbnail,sort_order,audio_url,video_category_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $title,$slug,$category,$preview_content,$full_content,
                   $video_url_stored,$video_id,$access_level,$duration,$is_featured,
                   $newThumb,$sortOrder,$newAudio,$video_category_id
               ]);
            $id = $db->lastInsertId();
            $success = 'Lesson created!';
            $action = 'edit';
        }
    }
}

// ── Load for edit ─────────────────────────────────────────────────────────────
$lesson = null;
if (($action === 'edit') && $id) {
    $stmt = $db->prepare("SELECT * FROM lessons WHERE id = ?");
    $stmt->execute([$id]);
    $lesson = $stmt->fetch();
    if (!$lesson) { header('Location: ' . SITE_URL . '/admin/lessons.php'); exit; }
}

$lessons = $db->query("SELECT * FROM lessons ORDER BY sort_order ASC, created_at DESC")->fetchAll();
?>

<?php if (isset($_GET['deleted'])): ?>
<div style="background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#0B7958;font-size:0.875rem;display:flex;align-items:center;gap:8px">
  <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
  Lesson deleted successfully.
</div>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
  <h1 class="admin-page-title" style="margin:0">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;margin-right:8px;vertical-align:middle;color:var(--primary-bright)"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>
    Lessons (<?= count($lessons) ?>)
  </h1>
  <a href="?action=new" class="btn-primary">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    New Lesson
  </a>
</div>

<div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden">
  <table class="admin-table">
    <thead><tr>
      <th style="width:60px">#</th>
      <th>Thumbnail</th><th>Title</th><th>Video</th><th>Category</th><th>Access</th><th>Featured</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lessons as $l): ?>
    <tr>
      <td style="color:var(--text-muted);font-size:0.82rem;font-family:var(--font-mono)"><?= intval($l['sort_order']) ?></td>
      <td>
        <?php if (!empty($l['video_id'])): ?>
          <!-- YouTube thumbnail from video_id — no DB stored image needed -->
          <div style="position:relative;width:56px;height:40px">
            <img
              src="https://img.youtube.com/vi/<?= htmlspecialchars($l['video_id']) ?>/mqdefault.jpg"
              style="width:56px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border)"
              onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"
              alt="">
            <div style="display:none;width:56px;height:40px;background:var(--bg-card2);border-radius:6px;border:1px solid var(--border);align-items:center;justify-content:center">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary-bright)"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </div>
            <div style="position:absolute;inset:0;background:rgba(0,0,0,0.2);display:flex;align-items:center;justify-content:center;border-radius:6px">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </div>
          </div>
        <?php elseif (!empty($l['thumbnail'])): ?>
          <img src="<?= imageUrl($l['thumbnail'],'lesson') ?>" style="width:56px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
        <?php else: ?>
          <div style="width:56px;height:40px;background:var(--bg-card2);border-radius:6px;border:1px solid var(--border);display:flex;align-items:center;justify-content:center">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted)"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
          </div>
        <?php endif; ?>
      </td>
      <td>
        <div style="font-weight:600;font-size:0.875rem"><?= sanitize($l['title']) ?></div>
        <div style="color:var(--text-muted);font-size:0.72rem;font-family:var(--font-mono)"><?= $l['slug'] ?></div>
      </td>
      <td>
        <?php if (!empty($l['video_id'])): ?>
        <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.76rem;color:#f87171;background:rgba(248,113,113,0.1);padding:3px 8px;border-radius:20px;border:1px solid rgba(248,113,113,0.25)">
          <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          YT Video
        </span>
        <?php else: ?>
        <span style="color:var(--text-muted);font-size:0.76rem">Text only</span>
        <?php endif; ?>
      </td>
      <td style="font-size:0.82rem;color:var(--text-dim)"><?= sanitize($l['category']) ?></td>
      <td>
        <span class="lesson-badge <?= $l['access_level']==='premium'?'badge-premium':'badge-free' ?>" style="position:static;display:inline-block">
          <?= ucfirst($l['access_level']) ?>
        </span>
      </td>
      <td style="text-align:center">
        <?php if ($l['is_featured']): ?>
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="#fbbf24" stroke="#fbbf24" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        <?php else: ?>
        <span style="color:var(--text-muted)">—</span>
        <?php endif; ?>
      </td>
      <td>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <a href="?action=edit&id=<?= $l['id'] ?>" class="btn-ghost btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit
          </a>
          <a href="<?= SITE_URL ?>/learn/lesson/<?= $l['id'] ?>" target="_blank" class="btn-secondary btn-sm">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
            View
          </a>
          <a href="?action=delete&id=<?= $l['id'] ?>" class="btn-danger btn-sm" onclick="return confirm('Delete this lesson?')">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            Del
          </a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($lessons)): ?>
    <tr><td colspan="8" style="text-align:center;padding:48px;color:var(--text-muted)">No lessons yet. Create your first one!</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php else: // ── Edit / New form ───────────────────────────────────────────────────────── ?>

<!-- CKEditor 5 (Open Source / GPL) — only loaded on this edit/new view, never
     on the list view, to keep the lesson list page lightweight.
     IMPORTANT: self-hosted from assets/vendor/ckeditor5/, NOT cdn.ckeditor.com.
     CKEditor 5's GPL license check now explicitly blocks the 'cloud'
     distribution channel (cdn.ckeditor.com self-identifies that way) —
     GPL only works for self-hosted/npm-distributed builds. Loading from
     the CDN throws "CKEditorError: license-key-invalid-distribution-channel"
     and the editor becomes fully unusable (toolbar renders, but typing is
     silently blocked). Self-hosting these two files (pulled from the
     `ckeditor5` npm package's dist/browser/ build, not a custom compile)
     is the fix and also matches "Open Source Edition" more literally
     anyway — no external CDN dependency at all now. -->
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/vendor/ckeditor5/ckeditor5.css?v=48.3.1">
<style>
  /* Match the CKEditor chrome to the existing form-input styling */
  #lesson-editor-container .ck.ck-editor__main > .ck-editor__editable {
    min-height: 340px;
    background: var(--bg-card2, #0f0a1c);
    color: var(--text);
    border-color: var(--border) !important;
  }
  #lesson-editor-container .ck.ck-toolbar {
    background: var(--bg-card2, #0f0a1c);
    border-color: var(--border) !important;
  }
  #lesson-editor-container .ck.ck-editor__editable_inline {
    border-bottom-left-radius: var(--radius);
    border-bottom-right-radius: var(--radius);
  }
  #lesson-editor-container .ck.ck-toolbar {
    border-top-left-radius: var(--radius);
    border-top-right-radius: var(--radius);
  }
</style>

<div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
  <a href="?action=list" style="color:var(--text-muted);font-size:0.875rem;display:flex;align-items:center;gap:5px">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
    Lessons
  </a>
  <span style="color:var(--border)">/</span>
  <h1 class="admin-page-title" style="margin:0"><?= $id ? 'Edit Lesson' : 'New Lesson' ?></h1>
</div>

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

<?php if ($success): ?>
<div style="background:rgba(11,121,88,0.1);border:1px solid rgba(11,121,88,0.3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px;color:#0B7958;font-size:0.875rem">
  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle"><polyline points="20 6 9 17 4 12"/></svg>
  <?= sanitize($success) ?>
</div>
<?php endif; ?>

<style>
.lesson-form-grid {
  display: grid;
  grid-template-columns: 1fr 310px;
  gap: 24px;
  align-items: flex-start;
}
@media (max-width: 899px) {
  .lesson-form-grid { grid-template-columns: 1fr; }
}
</style>

<form method="POST" enctype="multipart/form-data" class="lesson-form-grid">
  <!-- ── Main column ── -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Basic info card -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:28px">
      <div class="form-group">
        <label class="form-label">Lesson Title *</label>
        <input type="text" name="title" class="form-input" required value="<?= sanitize($lesson['title'] ?? '') ?>" placeholder="e.g. Introduction to Smart Contracts">
      </div>
      <div class="form-group">
        <label class="form-label">
          Preview / Description
          <span style="color:var(--text-muted);font-weight:400;font-size:0.75rem"> — shown to all users on the Learn page</span>
        </label>
        <textarea name="preview_content" class="form-textarea" rows="4"><?= htmlspecialchars($lesson['preview_content'] ?? '') ?></textarea>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label">
          Full Content
          <span style="color:var(--text-muted);font-weight:400;font-size:0.75rem"> — shown below video · premium users only if premium lesson</span>
        </label>
        <div id="lesson-editor-container">
          <textarea name="full_content" id="lesson-editor"><?= str_ireplace('</textarea', '&lt;/textarea', $lesson['full_content'] ?? '') ?></textarea>
        </div>
        <div class="form-hint" style="margin-top:6px">Use the toolbar to format text, insert images, upload videos, or embed YouTube/Vimeo links. Content is sanitized on save.</div>
      </div>
    </div>

    <!-- Video card -->
    <div style="background:var(--bg-card);border:1.5px solid rgba(248,113,113,0.35);border-radius:var(--radius-lg);padding:28px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:18px;display:flex;align-items:center;gap:8px">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f87171" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        YouTube Video
      </h3>

      <div class="form-group">
        <label class="form-label">YouTube URL</label>
        <input
          type="url"
          name="video_url"
          id="videoUrlInput"
          class="form-input"
          value="<?= sanitize($lesson['video_url'] ?? '') ?>"
          placeholder="https://www.youtube.com/watch?v=dQw4w9WgXcQ  or  https://youtu.be/dQw4w9WgXcQ"
          oninput="previewYouTubeUrl(this.value)"
        >
        <div style="color:var(--text-muted);font-size:0.76rem;margin-top:6px">
          Supports youtube.com/watch?v=, youtu.be/, and youtube.com/embed/ formats.
          Thumbnail is generated automatically — no upload needed.
        </div>
      </div>

      <!-- Live preview when URL is entered -->
      <div id="ytPreviewBox" style="display:<?= !empty($lesson['video_id']) ? 'block' : 'none' ?>;margin-top:4px">
        <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:8px;display:flex;align-items:center;gap:5px">
          <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Thumbnail preview (auto-generated from YouTube):
        </div>
        <div style="position:relative;display:inline-block;border-radius:var(--radius);overflow:hidden;border:1.5px solid rgba(248,113,113,0.3)">
          <img
            id="ytThumbPreview"
            src="<?= !empty($lesson['video_id']) ? "https://img.youtube.com/vi/{$lesson['video_id']}/hqdefault.jpg" : '' ?>"
            alt="Video thumbnail"
            style="width:240px;height:135px;object-fit:cover;display:block"
          >
          <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.2)">
            <div style="background:rgba(248,113,113,0.9);border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center">
              <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="white" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
            </div>
          </div>
        </div>
        <div id="ytVideoIdDisplay" style="font-size:0.72rem;color:var(--text-muted);margin-top:6px;font-family:var(--font-mono)">
          <?= !empty($lesson['video_id']) ? 'Video ID: ' . htmlspecialchars($lesson['video_id']) : '' ?>
        </div>
      </div>
    </div>

    <!-- Audio upload card -->
    <div style="background:var(--bg-card);border:1.5px solid rgba(139,92,246,0.35);border-radius:var(--radius-lg);padding:28px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:18px;display:flex;align-items:center;gap:8px">
        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
        Audio Lesson
        <span style="font-weight:400;font-size:0.7rem;color:var(--text-muted)">(optional · MP3 only)</span>
      </h3>

      <?php if (!empty($lesson['audio_url'])): ?>
      <!-- Existing audio file -->
      <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:rgba(139,92,246,0.08);border:1px solid rgba(139,92,246,0.25);border-radius:var(--radius);margin-bottom:16px">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--primary-bright)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
        <div style="flex:1;min-width:0">
          <div style="font-size:0.8rem;font-weight:600;color:var(--primary-bright)">Audio file uploaded</div>
          <div style="font-size:0.72rem;color:var(--text-muted);font-family:var(--font-mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(basename($lesson['audio_url'])) ?></div>
        </div>
        <label style="display:flex;align-items:center;gap:6px;font-size:0.78rem;color:#f87171;cursor:pointer;white-space:nowrap">
          <input type="checkbox" name="remove_audio" value="1" style="accent-color:#f87171;width:13px;height:13px">
          Remove
        </label>
      </div>
      <?php endif; ?>

      <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:12px">
        Upload an MP3 narration for this lesson. Users will see a custom audio player above the video/content.
        <?php if (!empty($lesson['audio_url'])): ?>Uploading a new file will replace the existing one.<?php endif; ?>
      </div>

      <label style="display:block;padding:20px;background:var(--bg-card2);border:2px dashed var(--border);border-radius:var(--radius);text-align:center;cursor:pointer;transition:all 0.2s" id="audioDropzone"
             onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary-bright);margin-bottom:8px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div style="color:var(--text-dim);font-size:0.82rem;font-weight:600" id="audioDropzoneLabel">
          <?php if (!empty($lesson['audio_url'])): ?>Replace audio file<?php else: ?>Upload audio file<?php endif; ?>
        </div>
        <div style="color:var(--text-muted);font-size:0.72rem;margin-top:4px">MP3 only · Max 50MB</div>
        <input type="file" name="audio_file" id="audioInput" accept=".mp3,audio/mpeg" style="display:none" onchange="handleAudioFileSelect(this)">
      </label>

      <!-- Selected file name feedback -->
      <div id="audioSelectedInfo" style="display:none;margin-top:10px;padding:10px 14px;background:rgba(139,92,246,0.08);border:1px solid rgba(139,92,246,0.25);border-radius:var(--radius);font-size:0.8rem;color:var(--primary-bright);display:none;align-items:center;gap:8px">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <span id="audioSelectedName"></span>
      </div>
    </div>

  </div><!-- /main column -->

  <!-- ── Sidebar column ── -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- Thumbnail upload (optional override) -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:10px;display:flex;align-items:center;gap:7px">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
        Custom Thumbnail
        <span style="font-weight:400;font-size:0.7rem;color:var(--text-muted)">(optional)</span>
      </h3>
      <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:12px">
        Leave blank to auto-use YouTube thumbnail.
      </div>

      <?php if (!empty($lesson['thumbnail'])): ?>
      <div style="margin-bottom:14px;position:relative">
        <img src="<?= imageUrl($lesson['thumbnail'],'lesson') ?>"
             style="width:100%;height:120px;object-fit:cover;border-radius:var(--radius);border:1.5px solid var(--border)" alt="Current thumbnail">
        <div style="position:absolute;top:6px;right:6px;background:rgba(0,0,0,0.6);border-radius:6px;padding:2px 7px;font-size:0.68rem;color:#fff">Current</div>
      </div>
      <?php endif; ?>

      <label style="display:block;padding:16px;background:var(--bg-card2);border:2px dashed var(--border);border-radius:var(--radius);text-align:center;cursor:pointer;transition:all 0.2s" id="thumbDropzone"
             onmouseover="this.style.borderColor='var(--primary)'" onmouseout="this.style.borderColor='var(--border)'">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color:var(--text-muted);margin-bottom:6px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <div style="color:var(--text-muted);font-size:0.8rem">Upload override image</div>
        <div style="color:var(--text-muted);font-size:0.7rem;margin-top:3px">JPG, PNG, WebP · Max 5MB</div>
        <input type="file" name="thumbnail" id="thumbInput" accept="image/*" style="display:none" onchange="previewImage(this,'thumbPreview','thumbDropzone')">
      </label>
      <img id="thumbPreview" src="" alt="" style="display:none;width:100%;height:120px;object-fit:cover;border-radius:var(--radius);border:1.5px solid var(--primary);margin-top:10px">
    </div>

    <!-- Settings -->
    <div style="background:var(--bg-card);border:1.5px solid var(--border);border-radius:var(--radius-lg);padding:22px">
      <h3 style="font-size:0.82rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:7px">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
        Settings
      </h3>
      <div class="form-group">
        <label class="form-label">Access Level</label>
        <select name="access_level" class="form-select">
          <option value="free"    <?= ($lesson['access_level'] ?? '')==='free'    ?'selected':'' ?>>🆓 Free</option>
          <option value="premium" <?= ($lesson['access_level'] ?? '')==='premium' ?'selected':'' ?>>⭐ Premium</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Category <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(text label)</span></label>
        <input type="text" name="category" class="form-input" value="<?= sanitize($lesson['category'] ?? 'Fundamentals') ?>" placeholder="e.g. DeFi, NFTs, Development">
        <div class="form-hint">Legacy text label. Also set a Video Category below for the Videos page.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Video Category <span style="font-size:0.75rem;font-weight:400;color:var(--text-muted)">(Videos page)</span></label>
        <?php
        $vcats = $db->query("SELECT id, name FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
        $selectedVCat = intval($lesson['video_category_id'] ?? 0);
        ?>
        <select name="video_category_id" class="form-select">
          <option value="">— Uncategorised —</option>
          <?php foreach ($vcats as $vc): ?>
          <option value="<?= $vc['id'] ?>" <?= $selectedVCat === (int)$vc['id'] ? 'selected' : '' ?>>
            <?= sanitize($vc['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Determines which category this lesson appears under on the <a href="<?= SITE_URL ?>/videos.php" target="_blank" style="color:var(--primary-bright)">Videos page</a>. <a href="<?= SITE_URL ?>/admin/categories.php" target="_blank" style="color:var(--primary-bright)">Manage categories →</a></div>
      </div>
      <div class="form-group">
        <label class="form-label">Lesson Order / Position</label>
        <input type="number" name="sort_order" class="form-input" min="1" value="<?= intval($lesson['sort_order'] ?? 0) ?: '' ?>" placeholder="Auto-assign if blank">
      </div>
      <div class="form-group">
        <label class="form-label">Duration</label>
        <input type="text" name="duration" class="form-input" value="<?= sanitize($lesson['duration'] ?? '15 min') ?>" placeholder="e.g. 15 min">
      </div>
      <label style="display:flex;align-items:center;gap:10px;padding:10px;background:var(--bg-card2);border-radius:var(--radius);cursor:pointer">
        <input type="checkbox" name="is_featured" <?= ($lesson['is_featured'] ?? 0)?'checked':'' ?> style="width:16px;height:16px;accent-color:var(--primary)">
        <span style="font-size:0.875rem;display:flex;align-items:center;gap:6px">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="#fbbf24" stroke="#fbbf24" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          Featured lesson
        </span>
      </label>
    </div>

    <button type="submit" class="btn-primary" style="width:100%;justify-content:center;padding:12px">
      <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      <?= $id ? 'Update Lesson' : 'Create Lesson' ?>
    </button>
    <?php if ($id && $lesson): ?>
    <a href="<?= SITE_URL ?>/learn/lesson/<?= $lesson['id'] ?>" target="_blank" class="btn-secondary" style="width:100%;justify-content:center;padding:11px;display:flex;align-items:center;gap:7px">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      View on Site
    </a>
    <?php endif; ?>
  </div>

</form>

<!-- CKEditor 5 (Open Source / GPL) bundle + this project's init wrapper.
     Self-hosted (see note above the CSS include) — not cdn.ckeditor.com.
     IMPORTANT: window.SITE_URL / LESSON_MAX_VIDEO_BYTES must be set BEFORE
     admin-lesson-editor.js loads — that file reads them at the top level
     of its IIFE (to build the upload endpoint URLs), not inside a
     deferred/lazy function, so if this order is reversed those URLs get
     built as "undefined/admin/ckeditor-upload.php" and every upload
     silently 404s. -->
<script>
  window.SITE_URL = <?= json_encode(SITE_URL) ?>;
  window.LESSON_MAX_VIDEO_BYTES = <?= (int) MAX_VIDEO_FILE_SIZE ?>;
</script>
<script src="<?= SITE_URL ?>/assets/vendor/ckeditor5/ckeditor5.umd.js?v=48.3.1"></script>
<script src="<?= SITE_URL ?>/assets/js/admin-lesson-editor.js?v=<?= @filemtime(__DIR__ . '/../assets/js/admin-lesson-editor.js') ?: time() ?>"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    initLessonEditor('lesson-editor');
  });
</script>

<script>
// Image upload preview
function previewImage(input, previewId, dropzoneId) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const preview  = document.getElementById(previewId);
    const dropzone = document.getElementById(dropzoneId);
    preview.src = e.target.result;
    preview.style.display = 'block';
    dropzone.style.borderColor = 'var(--primary)';
    dropzone.style.background  = 'rgba(139,92,246,0.06)';
  };
  reader.readAsDataURL(input.files[0]);
}

// Extract YouTube ID from URL in-browser (mirrors PHP logic)
function extractYouTubeId(url) {
  const patterns = [
    /youtu\.be\/([a-zA-Z0-9_-]{11})/,
    /youtube\.com\/watch\?(?:[^&]*&)*v=([a-zA-Z0-9_-]{11})/,
    /youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/,
    /youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/,
    /youtube\.com\/v\/([a-zA-Z0-9_-]{11})/,
  ];
  for (const re of patterns) {
    const m = url.match(re);
    if (m) return m[1];
  }
  if (/^[a-zA-Z0-9_-]{11}$/.test(url.trim())) return url.trim();
  return null;
}

function previewYouTubeUrl(value) {
  const box     = document.getElementById('ytPreviewBox');
  const thumb   = document.getElementById('ytThumbPreview');
  const idLabel = document.getElementById('ytVideoIdDisplay');
  const id      = extractYouTubeId(value);
  if (id) {
    thumb.src   = `https://img.youtube.com/vi/${id}/hqdefault.jpg`;
    idLabel.textContent = `Video ID: ${id}`;
    box.style.display   = 'block';
  } else {
    box.style.display = 'none';
  }
}

function handleAudioFileSelect(input) {
  const info  = document.getElementById('audioSelectedInfo');
  const name  = document.getElementById('audioSelectedName');
  const label = document.getElementById('audioDropzoneLabel');
  const zone  = document.getElementById('audioDropzone');
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  // Client-side MP3 guard
  if (!file.name.toLowerCase().endsWith('.mp3') && file.type !== 'audio/mpeg') {
    alert('Only MP3 files are allowed.');
    input.value = '';
    return;
  }
  name.textContent  = file.name + ' (' + (file.size / (1024*1024)).toFixed(2) + ' MB)';
  info.style.display = 'flex';
  label.textContent  = 'File selected — change selection';
  zone.style.borderColor = 'var(--primary)';
  zone.style.background  = 'rgba(139,92,246,0.06)';
}
</script>

<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>

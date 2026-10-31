/**
 * Web3edu — CKEditor 5 Lesson Content Editor
 * ============================================
 * Initializes the rich-text editor that replaces the old plain-text
 * "Full Content" textarea in admin/lessons.php. Used identically for both
 * Lessons and Guide Lessons — they share the exact same form/field, since
 * Guide Lessons in this codebase are just existing Lessons attached to a
 * guide sequence (guide_path_lessons), not a separate content type.
 *
 * Loaded lazily: admin/lessons.php only includes the self-hosted CKEditor
 * bundle (assets/vendor/ckeditor5/) and this file on the edit/new form
 * view (never the list view), so the ~1.9MB CKEditor bundle never loads
 * on pages that don't need it.
 *
 * Requires this file to run AFTER the CKEditor <script> tag, since it
 * reads the global `CKEDITOR` object that script exposes (see
 * admin/lessons.php's script includes). Self-hosted rather than loaded
 * from cdn.ckeditor.com — CKEditor 5's GPL license check blocks the
 * "cloud" distribution channel that the CDN self-identifies as, which
 * would otherwise make the editor load visually but silently reject all
 * keystrokes (CKEditorError: license-key-invalid-distribution-channel).
 *
 * Custom pieces beyond CKEditor's stock feature set:
 *   1. Two toolbar buttons for video:
 *        - Upload Video    → uploads an MP4 via admin/ckeditor-upload.php
 *                            and inserts a <figure class="media"><video>.
 *        - Embed Video URL → prompts for a YouTube/Vimeo URL and inserts
 *                            a safe, normalized <figure class="media"><iframe>.
 *      Both are raw HTML insertions permitted through GeneralHtmlSupport
 *      (config.htmlSupport.allow below), which is the officially supported
 *      way to let CKEditor 5's model represent, round-trip, and persist
 *      elements — <video>/<source>/<iframe> — that have no dedicated
 *      built-in widget in the open-source plugin set.
 *   2. The built-in image upload adapter (SimpleUploadAdapter) posts to
 *      the same admin endpoint via config.simpleUpload.uploadUrl below,
 *      reusing the existing handleImageUpload() validation pipeline
 *      (MIME sniffing, size cap, unique filenames).
 *
 * Everything CKEditor saves is re-sanitized server-side on submit
 * (includes/html_sanitizer.php) — this file only has to produce HTML the
 * sanitizer's allowlist will accept, it is never the security boundary.
 */

(function () {
  'use strict';

  const UPLOAD_IMAGE_URL = window.SITE_URL + '/admin/ckeditor-upload.php';
  const UPLOAD_VIDEO_URL = window.SITE_URL + '/admin/ckeditor-upload.php?type=video';

  function buildEmbedHtml(url) {
    url = (url || '').trim();

    // YouTube — accept watch/shorts/embed/youtu.be forms, normalize to /embed/ID
    let m = url.match(/(?:youtube\.com\/watch\?(?:.*&)?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/);
    if (m) {
      return `<figure class="media"><iframe src="https://www.youtube.com/embed/${m[1]}?rel=0&amp;modestbranding=1" width="560" height="315" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen="true" loading="lazy" title="YouTube video"></iframe></figure>`;
    }

    // Vimeo
    m = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (m) {
      return `<figure class="media"><iframe src="https://player.vimeo.com/video/${m[1]}" width="560" height="315" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen="true" loading="lazy" title="Vimeo video"></iframe></figure>`;
    }

    return null;
  }

  // Insert raw allowed HTML at the current cursor position through
  // CKEditor's data-processor pipeline (view → model), so GeneralHtmlSupport
  // converts it into a proper model representation rather than an opaque
  // blob — meaning it can be selected, deleted, and re-serialized correctly.
  function insertHtmlAtCursor(editor, html) {
    const viewFragment = editor.data.processor.toView(html);
    const modelFragment = editor.data.toModel(viewFragment);
    editor.model.insertContent(modelFragment, editor.model.document.selection);
  }

  function uploadVideoFile(editor, file) {
    const maxBytes = window.LESSON_MAX_VIDEO_BYTES || 50 * 1024 * 1024;
    if (file.size > maxBytes) {
      alert('Video is too large. Max size: ' + Math.round(maxBytes / (1024 * 1024)) + 'MB.');
      return;
    }

    const data = new FormData();
    data.append('video', file);

    fetch(UPLOAD_VIDEO_URL, { method: 'POST', body: data, credentials: 'same-origin' })
      .then((r) => r.text().then((text) => ({ ok: r.ok, status: r.status, text })))
      .then(({ ok, status, text }) => {
        let res;
        try {
          res = JSON.parse(text);
        } catch (e) {
          console.error('Video upload: non-JSON response (status ' + status + '):', text);
          alert('Video upload failed — server returned an unexpected response. Check the browser console for details.');
          return;
        }
        if (!res.success) {
          alert(res.error || 'Video upload failed.');
          return;
        }
        const html = `<figure class="media"><video src="${res.url}" controls="true" preload="metadata"></video></figure>`;
        insertHtmlAtCursor(editor, html);
      })
      .catch((err) => {
        console.error('Video upload network error:', err);
        alert('Video upload failed — please try again.');
      });
  }

  function promptEmbedUrl(editor) {
    const url = window.prompt('Paste a YouTube or Vimeo URL:');
    if (!url) return;
    const html = buildEmbedHtml(url);
    if (!html) {
      alert('That URL was not recognized as a YouTube or Vimeo link.');
      return;
    }
    insertHtmlAtCursor(editor, html);
  }

  function createUploadVideoButton(editor) {
    const { ButtonView } = CKEDITOR;
    const button = new ButtonView(editor.locale);
    button.set({ label: 'Upload Video', withText: true, tooltip: true });

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'video/mp4,.mp4';
    fileInput.style.display = 'none';
    document.body.appendChild(fileInput);
    fileInput.addEventListener('change', () => {
      const file = fileInput.files && fileInput.files[0];
      if (file) uploadVideoFile(editor, file);
    });

    button.on('execute', () => {
      fileInput.value = '';
      fileInput.click();
    });

    return button;
  }

  function createEmbedVideoButton(editor) {
    const { ButtonView } = CKEDITOR;
    const button = new ButtonView(editor.locale);
    button.set({ label: 'Embed Video URL', withText: true, tooltip: true });

    button.on('execute', () => promptEmbedUrl(editor));

    return button;
  }

  // Registered as real plugins (via extraPlugins below) rather than called
  // after ClassicEditor.create() resolves — componentFactory entries must
  // exist BEFORE the toolbar is built during initialization, or CKEditor
  // silently drops the toolbar item with a console warning ("There is no
  // such UI component as '...'"). Two plain buttons instead of a dropdown
  // so both are always visible in the toolbar with no extra click to open
  // a menu first.
  class LessonVideoPlugin {
    constructor(editor) { this.editor = editor; }
    init() {
      this.editor.ui.componentFactory.add('uploadVideo', () => createUploadVideoButton(this.editor));
      this.editor.ui.componentFactory.add('embedVideo', () => createEmbedVideoButton(this.editor));
    }
  }

  window.initLessonEditor = function initLessonEditor(elementId) {
    const el = document.getElementById(elementId);
    if (!el || !window.CKEDITOR) return;

    const {
      ClassicEditor,
      Essentials,
      Paragraph,
      Heading,
      Bold,
      Italic,
      Underline,
      Strikethrough,
      Alignment,
      List,
      BlockQuote,
      HorizontalLine,
      Link,
      Image,
      ImageUpload,
      ImageToolbar,
      ImageStyle,
      ImageCaption,
      ImageResize,
      SimpleUploadAdapter,
      Table,
      TableToolbar,
      CodeBlock,
      GeneralHtmlSupport,
    } = CKEDITOR;

    ClassicEditor.create(el, {
      licenseKey: 'GPL',
      plugins: [
        Essentials, Paragraph, Heading,
        Bold, Italic, Underline, Strikethrough,
        Alignment,
        List,
        BlockQuote, HorizontalLine,
        Link,
        Image, ImageUpload, ImageToolbar, ImageStyle, ImageCaption, ImageResize,
        SimpleUploadAdapter,
        Table, TableToolbar,
        CodeBlock,
        GeneralHtmlSupport,
      ],
      extraPlugins: [LessonVideoPlugin],
      toolbar: {
        items: [
          'heading', '|',
          'bold', 'italic', 'underline', 'strikethrough', '|',
          'alignment', '|',
          'bulletedList', 'numberedList', '|',
          'blockQuote', 'horizontalLine', '|',
          'link', 'uploadImage', 'uploadVideo', 'embedVideo', 'insertTable', 'codeBlock', '|',
          'undo', 'redo',
        ],
        shouldNotGroupWhenFull: true,
      },
      heading: {
        options: [
          { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
          { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
          { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
          { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' },
          { model: 'heading4', view: 'h4', title: 'Heading 4', class: 'ck-heading_heading4' },
          { model: 'heading5', view: 'h5', title: 'Heading 5', class: 'ck-heading_heading5' },
          { model: 'heading6', view: 'h6', title: 'Heading 6', class: 'ck-heading_heading6' },
        ],
      },
      image: {
        // Always insert as a block (figure-wrapped) image, never inline —
        // without this, CKEditor decides block vs. inline based on cursor
        // context (e.g. clicking upload with the cursor mid-sentence
        // produces a bare inline <img>/<span> with no <figure> wrapper at
        // all), and our lesson-reading.css centering rules only target
        // figure.image, so an inline-inserted image would silently never
        // get centered — exactly the "fine on desktop, off on mobile"
        // symptom, since inline flow just has more visual room on wide
        // screens to look acceptable by accident.
        insert: { type: 'block' },
        toolbar: [
          'imageTextAlternative', 'toggleImageCaption', '|',
          'imageStyle:inline', 'imageStyle:block', 'imageStyle:side', '|',
          'resizeImage',
        ],
      },
      table: {
        contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells'],
      },
      link: {
        addTargetToExternalLinks: true,
        defaultProtocol: 'https://',
      },
      // Required for the SimpleUploadAdapter plugin to function at all —
      // without this config it has no endpoint to POST to and image
      // uploads silently do nothing (this was missing before, which is
      // why clicking/selecting an image never inserted anything).
      simpleUpload: {
        uploadUrl: UPLOAD_IMAGE_URL,
        withCredentials: true,
      },
      // Permit the raw elements our custom video plugin inserts and that
      // legacy/round-tripped content may already contain. Kept in lockstep
      // with includes/html_sanitizer.php's allowlist — anything allowed
      // here that the PHP sanitizer doesn't also allow gets stripped on
      // save anyway, so this list is deliberately conservative.
      htmlSupport: {
        allow: [
          { name: 'figure', classes: ['media'] },
          {
            name: 'video',
            attributes: ['src', 'controls', 'preload', 'width', 'height'],
          },
          { name: 'source', attributes: ['src', 'type'] },
          {
            name: 'iframe',
            attributes: ['src', 'width', 'height', 'allow', 'allowfullscreen', 'loading', 'title', 'frameborder'],
          },
        ],
      },
      // Iframes we insert ourselves are from trusted, hardcoded YouTube/
      // Vimeo embed URLs only — disable the sandbox restriction so the
      // players actually function (fullscreen, autoplay grants, etc.);
      // arbitrary iframe src values are never accepted (buildEmbedHtml
      // only returns a URL it just constructed from a matched ID).
      htmlIframeSandbox: false,
    })
      .then((editor) => {
        el._ckeditorInstance = editor;

        // Keep the underlying textarea in sync so the existing multipart
        // form POST still submits full_content exactly as it always has —
        // no changes needed anywhere else in the form/submit handling.
        const syncToTextarea = () => { el.value = editor.getData(); };
        editor.model.document.on('change:data', syncToTextarea);
        const form = el.closest('form');
        if (form) form.addEventListener('submit', syncToTextarea);
      })
      .catch((err) => {
        console.error('CKEditor failed to initialize:', err);
      });
  };
})();

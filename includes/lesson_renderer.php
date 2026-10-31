<?php
require_once __DIR__ . '/html_sanitizer.php';

/**
 * render_lesson_content($lesson)
 *
 * Shared content renderer used by both Learn and Guide lesson pages.
 *
 * Lesson content now comes from CKEditor 5 and full_content is stored as
 * sanitized HTML (see includes/html_sanitizer.php — sanitization happens
 * at SAVE time in admin/lessons.php, not here, so this function trusts
 * full_content is already safe and echoes it directly when HTML is
 * detected). preview_content is untouched by this change and remains
 * plain text everywhere else it's used (the free-preview blurb) — when
 * full_content is empty and we fall back to it here, it's still plain
 * text, so it's correctly routed through the legacy plain-text renderer
 * below rather than echoed raw.
 *
 * BACKWARD COMPATIBILITY — existing lessons created before this change:
 *   Old lessons stored hand-typed plain text (blank line = new paragraph,
 *   "- " / "1. " prefixes = lists), rendered by a bespoke plain-text-to-HTML
 *   parser. That content contains no HTML tags at all. We auto-detect this
 *   case (no recognizable tag present) and fall back to the original
 *   plain-text renderer below, unchanged, so nothing already published
 *   breaks or needs re-saving. Once an admin opens that lesson in
 *   CKEditor and saves it again, it becomes HTML and is rendered via the
 *   new path from then on.
 *
 * Falls back to preview_content when full_content is empty.
 */
function render_lesson_content(array $lesson): void
{
    $rawContent = trim($lesson['full_content'] ?? '');
    if ($rawContent === '') {
        $rawContent = trim($lesson['preview_content'] ?? '');
    }

    if ($rawContent === '') {
        return;
    }

    if (lesson_content_looks_like_html($rawContent)) {
        // New path: sanitized HTML from CKEditor, stored safe — echo as-is.
        echo $rawContent;
        return;
    }

    render_legacy_plaintext_lesson_content($rawContent);
}

/**
 * Heuristic: does this stored content contain real HTML markup (new
 * CKEditor-authored content) or is it legacy hand-typed plain text?
 * CKEditor always wraps content in at least one block tag (<p>, <h1-6>,
 * <ul>, etc), so the presence of any recognized block/inline tag is a
 * reliable signal. Plain text lessons may still contain a stray "<" or
 * "&" as ordinary punctuation, so we require an actual tag-shaped match
 * rather than just checking for "<".
 */
function lesson_content_looks_like_html(string $content): bool
{
    return (bool) preg_match(
        '#<(p|h[1-6]|ul|ol|li|blockquote|hr|figure|img|table|pre|div|a|strong|em|video|iframe)\b#i',
        $content
    );
}

/**
 * Original plain-text → HTML renderer, preserved verbatim for legacy
 * lessons saved before the CKEditor migration.
 *
 * Rules:
 *   - Blank lines separate blocks (paragraphs / lists).
 *   - Within a block, consecutive lines starting with "N." → <ol><li>
 *   - Within a block, consecutive lines starting with "-" or "•" → <ul><li>
 *   - All other lines render as a <p> with single \n → <br>.
 *   - All text is htmlspecialchars()-escaped; no raw content is ever echoed.
 */
function render_legacy_plaintext_lesson_content(string $rawContent): void
{
    // Normalise line endings
    $rawContent = str_replace(["\r\n", "\r"], "\n", $rawContent);

    // Split into blocks on one or more blank lines
    $blocks = preg_split('/\n{2,}/', $rawContent);

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') continue;

        $lines = explode("\n", $block);

        // Classify each line: 'ol', 'ul', or 'text'
        $types = array_map(function ($line) {
            $line = ltrim($line);
            if (preg_match('/^\d+\.\s/', $line)) return 'ol';
            if (preg_match('/^[-•]\s/', $line))  return 'ul';
            return 'text';
        }, $lines);

        $allSameList = count(array_unique($types)) === 1 && $types[0] !== 'text';

        if ($allSameList) {
            // Pure list block
            $tag = ($types[0] === 'ol') ? 'ol' : 'ul';
            echo "<{$tag}>\n";
            foreach ($lines as $line) {
                $text = preg_replace('/^(\d+\.|[-•])\s+/', '', ltrim($line));
                echo '<li>' . htmlspecialchars($text) . "</li>\n";
            }
            echo "</{$tag}>\n";
        } else {
            // Mixed block or pure text block — render line by line,
            // flushing any in-progress list when the line type changes.
            $currentTag = null;
            $paraLines  = [];

            $flushPara = function () use (&$paraLines) {
                if (empty($paraLines)) return;
                echo '<p>' . implode('<br>', $paraLines) . "</p>\n";
                $paraLines = [];
            };
            $flushList = function () use (&$currentTag) {
                if ($currentTag === null) return;
                echo "</{$currentTag}>\n";
                $currentTag = null;
            };

            foreach ($lines as $i => $line) {
                $type = $types[$i];

                if ($type === 'text') {
                    $flushList();
                    $paraLines[] = htmlspecialchars($line);
                } else {
                    $flushPara();
                    $listTag = ($type === 'ol') ? 'ol' : 'ul';
                    if ($currentTag !== $listTag) {
                        $flushList();
                        echo "<{$listTag}>\n";
                        $currentTag = $listTag;
                    }
                    $text = preg_replace('/^(\d+\.|[-•])\s+/', '', ltrim($line));
                    echo '<li>' . htmlspecialchars($text) . "</li>\n";
                }
            }
            $flushList();
            $flushPara();
        }
    }
}

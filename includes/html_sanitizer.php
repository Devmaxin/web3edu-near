<?php
/**
 * Web3edu — Lesson HTML Sanitizer
 * ============================================
 * Dependency-free allowlist HTML sanitizer for rich lesson content authored
 * with CKEditor 5. No composer / HTMLPurifier available on InfinityFree, so
 * this uses PHP's built-in DOM extension (bundled with virtually every PHP
 * build) to parse, strip, and rebuild the markup safely.
 *
 * Design goals:
 *   - Allowlist only. Anything not explicitly permitted is stripped
 *     (the element is removed but, where safe, its text/children are kept).
 *   - No <script>, <style>, event handler attributes (onclick, onerror, ...),
 *     javascript: / data: URLs (except safe inline image data is NOT allowed
 *     either — all images must be uploaded files or absolute http(s) URLs).
 *   - Supports everything CKEditor 5 in this build can produce: headings
 *     1-6, paragraphs, bold/italic/underline/strike, alignment via style/
 *     class, bulleted/numbered lists, blockquote, hr, links, images
 *     (with figure/figcaption for captions), tables, code blocks, and the
 *     two custom video embeds this project adds (uploaded <video> and
 *     YouTube/Vimeo <iframe> embeds).
 *   - Backward compatible: plain text saved by the old textarea (no HTML
 *     tags at all) parses as a single harmless text node and passes through
 *     unchanged (still displayed correctly, just without the old
 *     newline/list-detection formatting — see migration note in
 *     lesson_renderer.php).
 *
 * Usage:
 *   $clean = sanitize_lesson_html($rawHtmlFromCkeditor);
 *   // store $clean in lessons.full_content
 */

// ============================================
// ALLOWLISTS
// ============================================

/** Tag => list of allowed attribute names (global attrs merged in below). */
const LESSON_HTML_ALLOWED_TAGS = [
    'p'          => [],
    'br'         => [],
    'h1'         => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
    'strong'     => [], 'b' => [],
    'em'         => [], 'i' => [],
    'u'          => [],
    's'          => [], 'strike' => [], 'del' => [],
    'blockquote' => [],
    'hr'         => [],
    'ul'         => [], 'ol' => [], 'li' => [],
    'a'          => ['href', 'target', 'rel', 'title'],
    'figure'     => ['class'],
    'figcaption' => [],
    'img'        => ['src', 'alt', 'width', 'height', 'title'],
    'table'      => [],
    'thead'      => [], 'tbody' => [], 'tfoot' => [],
    'tr'         => [],
    'td'         => ['colspan', 'rowspan'],
    'th'         => ['colspan', 'rowspan', 'scope'],
    'pre'        => ['class'],
    'code'       => ['class'],
    'span'       => ['class'],
    // Uploaded MP4 video
    'video'      => ['src', 'controls', 'width', 'height', 'preload', 'poster', 'class'],
    'source'     => ['src', 'type'],
    // YouTube / Vimeo embeds — sandboxed iframe, src is re-validated below
    'iframe'     => ['src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'class', 'title', 'loading'],
];

/** Global attributes allowed on every allowed tag (style is allowed only
 *  with a tightly filtered property allowlist — see sanitize_style_attr). */
const LESSON_HTML_GLOBAL_ATTRS = ['style', 'class'];

/** CSS properties permitted inside a style="" attribute. Only alignment /
 *  sizing properties CKEditor emits — never anything that can load a
 *  remote resource (e.g. background-image: url(...)) or escape layout. */
const LESSON_HTML_ALLOWED_CSS_PROPS = [
    'text-align', 'width', 'height', 'max-width', 'float',
];

/** Only these URL schemes are allowed in href/src. */
const LESSON_HTML_ALLOWED_SCHEMES = ['http', 'https'];

/** Iframe src must match one of these embed-host patterns exactly. */
const LESSON_HTML_ALLOWED_IFRAME_HOSTS = [
    '#^https://www\.youtube\.com/embed/[a-zA-Z0-9_-]{11}(\?[a-zA-Z0-9_=&%.-]*)?$#',
    '#^https://player\.vimeo\.com/video/[0-9]+(\?[a-zA-Z0-9_=&%.-]*)?$#',
];

// ============================================
// MAIN ENTRY POINT
// ============================================

/**
 * Sanitize raw HTML from CKEditor before it is stored in the database.
 * Returns clean, safe HTML. Never throws — worst case returns escaped
 * plain text of the original input if parsing fails entirely.
 */
function sanitize_lesson_html(string $html): string
{
    $html = trim($html);
    if ($html === '') return '';

    if (!class_exists('DOMDocument')) {
        // Extremely defensive fallback if the DOM extension is somehow
        // unavailable — never store raw untrusted HTML.
        return '<p>' . htmlspecialchars(strip_tags($html)) . '</p>';
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = false;

    $prevLibxml = libxml_use_internal_errors(true);
    // Wrap in a UTF-8 meta + container so DOMDocument doesn't mis-decode
    // entities/encoding, then we only serialize the container's children.
    $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="lesson-root">' . $html . '</div></body></html>';
    $dom->loadHTML($wrapped, LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($prevLibxml);

    $root = $dom->getElementById('lesson-root');
    if (!$root) {
        return '<p>' . htmlspecialchars(strip_tags($html)) . '</p>';
    }

    sanitize_lesson_node_children($dom, $root);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $dom->saveHTML($child);
    }

    return trim($out);
}

/**
 * Recursively walk and sanitize a node's children in place. Disallowed
 * elements are removed but their child content is preserved (unwrapped)
 * unless the tag itself is dangerous (script/style/etc), in which case the
 * entire subtree is dropped.
 */
function sanitize_lesson_node_children(DOMDocument $dom, DOMNode $parent): void
{
    // Snapshot child list first — we mutate the tree as we go.
    $children = iterator_to_array($parent->childNodes);

    foreach ($children as $node) {
        if ($node instanceof DOMText || $node instanceof DOMCdataSection) {
            continue; // text content is always safe
        }
        if ($node instanceof DOMComment) {
            $parent->removeChild($node);
            continue;
        }
        if (!($node instanceof DOMElement)) {
            $parent->removeChild($node);
            continue;
        }

        $tag = strtolower($node->tagName);

        // Fully dangerous tags: drop the whole subtree, no unwrapping.
        if (in_array($tag, ['script', 'style', 'noscript', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'link', 'meta', 'base', 'svg', 'math'], true)) {
            $parent->removeChild($node);
            continue;
        }

        if (!array_key_exists($tag, LESSON_HTML_ALLOWED_TAGS)) {
            // Unknown/disallowed tag: unwrap — keep sanitized children,
            // drop the wrapping element itself.
            sanitize_lesson_node_children($dom, $node);
            while ($node->firstChild) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
            continue;
        }

        sanitize_element_attributes($node, $tag);

        // Extra per-tag validation
        if ($tag === 'a' && !sanitize_validate_url($node->getAttribute('href'))) {
            $node->removeAttribute('href');
        }
        if ($tag === 'img') {
            if (!sanitize_validate_url($node->getAttribute('src'))) {
                // No safe src — drop the image entirely (unwrap to nothing).
                $parent->removeChild($node);
                continue;
            }
        }
        if ($tag === 'source') {
            if (!sanitize_validate_url($node->getAttribute('src'), true)) {
                $parent->removeChild($node);
                continue;
            }
        }
        if ($tag === 'video' && $node->hasAttribute('src')) {
            if (!sanitize_validate_url($node->getAttribute('src'), true)) {
                $node->removeAttribute('src');
            }
        }
        if ($tag === 'iframe') {
            $src = $node->getAttribute('src');
            $ok  = false;
            foreach (LESSON_HTML_ALLOWED_IFRAME_HOSTS as $pattern) {
                if (preg_match($pattern, $src)) { $ok = true; break; }
            }
            if (!$ok) {
                $parent->removeChild($node);
                continue;
            }
            // Force safe sandbox-ish attributes regardless of what came in.
            $node->setAttribute('frameborder', '0');
            $node->setAttribute('loading', 'lazy');
            if (!$node->hasAttribute('allowfullscreen')) {
                $node->setAttribute('allowfullscreen', 'true');
            }
        }
        if ($tag === 'a') {
            // Force safe rel on every link (target may be _blank from editor).
            if ($node->getAttribute('target') === '_blank') {
                $node->setAttribute('rel', 'noopener noreferrer');
            }
        }

        sanitize_lesson_node_children($dom, $node);
    }
}

/**
 * Strip an element's attributes down to the allowlist for its tag, and
 * sanitize style="" separately.
 */
function sanitize_element_attributes(DOMElement $node, string $tag): void
{
    $allowedForTag = LESSON_HTML_ALLOWED_TAGS[$tag] ?? [];
    $allowed       = array_merge($allowedForTag, LESSON_HTML_GLOBAL_ATTRS);

    // Snapshot attribute names first (can't mutate NamedNodeMap while iterating)
    $attrNames = [];
    foreach ($node->attributes as $attr) {
        $attrNames[] = $attr->name;
    }

    foreach ($attrNames as $name) {
        $lname = strtolower($name);

        // Never allow event handlers or javascript: pseudo-attrs, no matter what.
        if (str_starts_with($lname, 'on')) {
            $node->removeAttribute($name);
            continue;
        }
        if (!in_array($lname, $allowed, true)) {
            $node->removeAttribute($name);
            continue;
        }
        if ($lname === 'style') {
            $clean = sanitize_style_attr($node->getAttribute('style'));
            if ($clean === '') {
                $node->removeAttribute('style');
            } else {
                $node->setAttribute('style', $clean);
            }
        }
    }

    // Numeric-only guard for width/height on img/video/iframe to prevent
    // CSS/JS injection via attribute (e.g. width="1;background:url(javascript:...)")
    if (in_array($tag, ['img', 'video', 'iframe'], true)) {
        foreach (['width', 'height'] as $dim) {
            if ($node->hasAttribute($dim) && !preg_match('/^\d{1,5}(px|%)?$/', $node->getAttribute($dim))) {
                $node->removeAttribute($dim);
            }
        }
    }
}

/**
 * Filter a style="" attribute value down to an allowlist of properties,
 * each with a simple value shape check to prevent CSS-based attacks
 * (url(), expression(), etc are always rejected).
 */
function sanitize_style_attr(string $style): string
{
    $out = [];
    $declarations = explode(';', $style);
    foreach ($declarations as $decl) {
        $decl = trim($decl);
        if ($decl === '' || !str_contains($decl, ':')) continue;
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);

        if (!in_array($prop, LESSON_HTML_ALLOWED_CSS_PROPS, true)) continue;

        // Reject anything referencing url(), expression(), or containing
        // suspicious characters — values here should just be keywords,
        // percentages, or px lengths.
        if (preg_match('/url\s*\(|expression\s*\(|[<>"\';{}]/i', $val)) continue;
        if (!preg_match('/^[a-zA-Z0-9%.\s#-]+$/', $val)) continue;

        $out[] = "{$prop}: {$val}";
    }
    return implode('; ', $out);
}

/**
 * Validate a URL is either a safe relative/site-uploaded path or an
 * absolute http(s) URL. Rejects javascript:, data:, vbscript:, etc.
 * $requireExtension - when true (used for <source src>), require the URL
 * end in a plausible media extension as a light sanity check.
 */
function sanitize_validate_url(string $url, bool $requireExtension = false): bool
{
    $url = trim($url);
    if ($url === '') return false;

    // Relative paths (e.g. /uploads/lessons/img_xxx.jpg) are always fine —
    // they can't carry a dangerous scheme.
    if ($url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) {
        return true;
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === null) {
        // No scheme, not absolute-path — treat as relative, allow.
        return !str_contains(strtolower($url), 'javascript:');
    }

    if (!in_array(strtolower($scheme), LESSON_HTML_ALLOWED_SCHEMES, true)) {
        return false;
    }

    if ($requireExtension) {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if (!preg_match('/\.(mp4|webm|ogg)$/i', $path)) return false;
    }

    return true;
}

<?php
// ============================================
// Web3edu — YouTube Video Helper Functions
// ============================================

/**
 * Extract YouTube video ID from various URL formats:
 *   https://www.youtube.com/watch?v=VIDEO_ID
 *   https://youtu.be/VIDEO_ID
 *   https://www.youtube.com/embed/VIDEO_ID
 *   https://www.youtube.com/shorts/VIDEO_ID
 * Returns null if no valid ID found.
 */
function extractYouTubeId(string $url): ?string {
    $url = trim($url);
    if (empty($url)) return null;

    $patterns = [
        // youtu.be/ID
        '#youtu\.be/([a-zA-Z0-9_-]{11})#',
        // youtube.com/watch?v=ID
        '#youtube\.com/watch\?(?:[^&]*&)*v=([a-zA-Z0-9_-]{11})#',
        // youtube.com/embed/ID
        '#youtube\.com/embed/([a-zA-Z0-9_-]{11})#',
        // youtube.com/shorts/ID
        '#youtube\.com/shorts/([a-zA-Z0-9_-]{11})#',
        // youtube.com/v/ID
        '#youtube\.com/v/([a-zA-Z0-9_-]{11})#',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $m)) {
            return $m[1];
        }
    }

    // Last resort: if it's already just an 11-char ID
    if (preg_match('#^[a-zA-Z0-9_-]{11}$#', $url)) {
        return $url;
    }

    return null;
}

/**
 * Build YouTube embed URL from video ID.
 * Options: autoplay, rel (related videos), modestbranding.
 */
function youtubeEmbedUrl(string $videoId, array $options = []): string {
    $params = http_build_query(array_merge([
        'rel'            => 0,
        'modestbranding' => 1,
    ], $options));
    return "https://www.youtube.com/embed/{$videoId}?{$params}";
}

/**
 * Return YouTube thumbnail URL for a given video ID.
 * Quality: maxresdefault → hqdefault → mqdefault (fallback via JS).
 */
function youtubeThumbnailUrl(string $videoId, string $quality = 'maxresdefault'): string {
    return "https://img.youtube.com/vi/{$videoId}/{$quality}.jpg";
}

/**
 * Render a responsive thumbnail <img> with JS fallback chain.
 * Does NOT embed any iframe — just the image.
 */
function renderYoutubeThumbnail(string $videoId, string $alt = '', string $classes = '', string $style = ''): string {
    $maxres  = youtubeThumbnailUrl($videoId, 'maxresdefault');
    $hq      = youtubeThumbnailUrl($videoId, 'hqdefault');
    $mq      = youtubeThumbnailUrl($videoId, 'mqdefault');
    $alt     = htmlspecialchars($alt, ENT_QUOTES, 'UTF-8');
    $classes = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<img
    src="{$maxres}"
    alt="{$alt}"
    class="{$classes}"
    style="{$style}"
    loading="lazy"
    onerror="ytThumbFallback(this,'{$hq}','{$mq}')"
>
HTML;
}

/**
 * Validate a YouTube URL and return the extracted ID or null.
 * Used in admin forms before saving.
 */
function validateYouTubeUrl(string $url, array &$errors): ?string {
    if (empty($url)) return null;

    $id = extractYouTubeId($url);
    if (!$id) {
        $errors[] = 'Invalid YouTube URL. Paste a link like https://www.youtube.com/watch?v=ID or https://youtu.be/ID';
        return null;
    }
    return $id;
}

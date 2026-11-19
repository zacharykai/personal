<?php
/**
 * footer-lib.php
 * The one source of truth for the site footer. The markup lives in
 * templates/footer.html; this file renders it and builds the optional
 * "Page last built from commit N" line. No git calls: publish.php exports
 * SITE_COMMIT_COUNT and SITE_COMMIT_HASH, and absent or invalid values mean
 * the commit line is left out.
 *
 * Used by sitebuild.php, publish.php and footer-sync.php.
 */

const FOOTER_COMMIT_URL = 'https://codeberg.org/zacharykai/personal/commit/';

/** The canonical footer template, exactly as stored (with {{COMMIT_LINK}}). */
function footer_template(): string {
    static $tpl = null;
    if ($tpl === null) {
        $path = __DIR__ . '/templates/footer.html';
        $tpl = is_file($path) ? file_get_contents($path) : false;
        if ($tpl === false || !str_contains($tpl, '{{COMMIT_LINK}}')) {
            fwrite(STDERR, "footer-lib: missing or invalid $path\n");
            exit(1);
        }
        $tpl = rtrim($tpl, "\r\n");
    }
    return $tpl;
}

/** The commit line (one full line, ending in a newline), or '' if the env vars are absent/invalid. */
function commit_footer_html(): string {
    $count = getenv('SITE_COMMIT_COUNT');
    $hash  = getenv('SITE_COMMIT_HASH');
    if (!is_string($count) || !preg_match('/^[0-9]+$/', $count)
        || !is_string($hash) || !preg_match('/^[0-9a-f]{40}$/', $hash)) {
        return '';
    }
    $flags = ENT_QUOTES | ENT_HTML5;
    return '            <p class="essentials">Page last built from commit <a href="'
        . htmlspecialchars(FOOTER_COMMIT_URL . $hash, $flags, 'UTF-8')
        . '" rel="noopener">' . htmlspecialchars(ltrim($count, '0') ?: '0', $flags, 'UTF-8')
        . '</a></p>' . "\n";
}

/**
 * The site <footer>...</footer> element, starting at "<footer>" (no leading
 * indent) and ending at "</footer>" (no trailing newline). Pass the commit line
 * from commit_footer_html(), or '' for none.
 */
function render_footer(string $commitLinkHtml = ''): string {
    // The placeholder owns its whole line, so with no commit line the output matches the static pages exactly.
    return rtrim(str_replace("{{COMMIT_LINK}}\n", $commitLinkHtml, footer_template() . "\n"), "\n");
}

#!/usr/bin/env php
<?php
/**
 * footer-sync.php
 * Rewrites the site footer in static HTML pages with the canonical footer
 * (templates/footer.html, via footer-lib.php).
 *
 * Usage:
 *   php assets/files/scripts/footer-sync.php --all
 *   php assets/files/scripts/footer-sync.php path/to/page.html [more.html ...]
 *
 * Options:
 *   --all       Every .html page under the site (lab/, vendor/, .git/ are skipped).
 *   --dry-run   Report what would change; write nothing.
 *   --force     Also overwrite pages whose footer differs from the canonical one
 *               by more than whitespace, comments, the newsletter link and the
 *               commit line. Without it those pages are left alone and listed.
 *               (Three long-standing odd pages are standardized without it;
 *               see SYNC_KNOWN_ODD.)
 *
 * The commit line ("Page last built from commit N") is stamped from the
 * SITE_COMMIT_COUNT / SITE_COMMIT_HASH environment variables (publish.php sets
 * them); with neither set, the pages get the footer with no commit line.
 *
 * Page templates: assets/files/scripts/templates/ is skipped (hand-maintained,
 * uses placeholders); assets/files/templates/ is synced but never stamped, so
 * new pages are born without a commit line.
 *
 * Only the site footer element is replaced. Everything outside it is preserved
 * byte for byte. A page with no footer, or with no single identifiable site
 * footer, is reported and left alone. Running it twice changes nothing.
 *
 * Exit codes: 0 = fine, 1 = hard error, 2 = some pages refused or ambiguous.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/footer-lib.php';

const SYNC_SKIP_DIRS = ['lab', 'vendor', '.git', 'node_modules'];
const SYNC_SKIP_PREFIXES = ['assets/files/scripts/templates/'];
const SYNC_NO_STAMP_PREFIXES = ['assets/files/templates/'];
// Pages with an older, hand-minified footer that are deliberately overwritten.
const SYNC_KNOWN_ODD = ['credits.html', 'someday.html', 'anythinggrid.html'];

function path_has_prefix(string $rel, array $prefixes): bool {
    foreach ($prefixes as $p) {
        if (str_starts_with($rel, $p)) {
            return true;
        }
    }
    return false;
}

$siteDir = dirname(__DIR__, 3);

/** Top-level <footer> elements of $html as [start, end) byte offsets (nested ones are ignored). Null if unbalanced. */
function top_level_footers(string $html): ?array {
    // Comments, scripts, styles and pre blocks are matched first so a "<footer" inside them is ignored.
    preg_match_all('~<!--.*?-->|<(script|style|pre|textarea)\b.*?</\1\s*>|<footer\b[^>]*>|</footer\s*>~is', $html, $m, PREG_OFFSET_CAPTURE);
    $depth = 0;
    $start = 0;
    $found = [];
    foreach ($m[0] as [$tok, $off]) {
        if (stripos($tok, '<footer') === 0) {
            if ($depth === 0) {
                $start = $off;
            }
            $depth++;
        } elseif (stripos($tok, '</footer') === 0) {
            $depth--;
            if ($depth < 0) {
                return null;
            }
            if ($depth === 0) {
                $found[] = [$start, $off + strlen($tok)];
            }
        }
    }
    return $depth === 0 ? $found : null;
}

/** Footer reduced to what matters: no comments, commit line, or whitespace-only differences; one newsletter URL. */
function footer_signature(string $block): string {
    $s = str_replace("\r\n", "\n", $block);
    $s = preg_replace('~\{\{COMMIT_LINK\}\}~', '', $s);
    $s = preg_replace('~<p class="essentials">(?:<a href="https://codeberg\.org/zacharykai/personal/commit/[^"]*"[^>]*>Built from commit \d+</a>|Page last built from commit <a [^>]*>\d+</a>)</p>~', '', $s);
    $s = preg_replace('~<!--.*?-->~s', '', $s);
    $s = str_replace('https://lunaseeker.com/newsletter/', 'https://zacharykai.net/newsletter/', $s);
    $s = preg_replace('~>\s+<~', '><', $s);
    return trim(preg_replace('~\s+~', ' ', $s));
}

/** One-line description of where two signatures first differ. */
function signature_diff(string $old, string $canon): string {
    $a = preg_split('~(?<=>)|\s+~', $canon, -1, PREG_SPLIT_NO_EMPTY);
    $b = preg_split('~(?<=>)|\s+~', $old, -1, PREG_SPLIT_NO_EMPTY);
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n && $a[$i] === $b[$i]; $i++);
    $ctx = fn(array $t) => mb_substr(implode(' ', array_slice($t, $i, 8)), 0, 90);
    return 'differs near token ' . $i . ': page "' . $ctx($b) . '" vs canonical "' . $ctx($a) . '" (' . strlen($old) . ' vs ' . strlen($canon) . ' normalized chars)';
}

function all_pages(string $siteDir): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($siteDir, FilesystemIterator::SKIP_DOTS),
        fn($f) => !($f->isDir() && in_array($f->getFilename(), SYNC_SKIP_DIRS, true))
    ));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($siteDir) + 1);
        if (strtolower($f->getExtension()) === 'html' && !path_has_prefix($rel, SYNC_SKIP_PREFIXES)) {
            $out[] = $rel;
        }
    }
    sort($out);
    return $out;
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$opts = ['all' => false, 'dry-run' => false, 'force' => false];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $k = substr($a, 2);
        if (!array_key_exists($k, $opts)) {
            fwrite(STDERR, "Unknown option $a\n");
            exit(1);
        }
        $opts[$k] = true;
    } else {
        $args[] = $a;
    }
}
if ($opts['all'] === (bool) $args) {
    fwrite(STDERR, "Usage: php footer-sync.php [--dry-run] [--force] (--all | file.html ...)\n");
    exit(1);
}

$pages = [];
if ($opts['all']) {
    $pages = all_pages($siteDir);
} else {
    $root = realpath($siteDir);
    foreach ($args as $a) {
        $abs = realpath($a) ?: realpath("$siteDir/$a");
        if ($abs === false || !is_file($abs) || !str_starts_with($abs, $root . '/')) {
            fwrite(STDERR, "Not a file inside the site: $a\n");
            exit(1);
        }
        $pages[] = substr($abs, strlen($root) + 1);
    }
}

// ---------------------------------------------------------------------------
// Sync
// ---------------------------------------------------------------------------

$commitHtml = commit_footer_html();
$footer = render_footer($commitHtml);
$footerNoStamp = render_footer('');
$canonSig = footer_signature(render_footer(''));

$changed = $unchanged = $skipped = 0;
$refused = [];
$ambiguous = [];

foreach (array_unique($pages) as $rel) {
    $parts = explode('/', $rel);
    if (!str_ends_with(strtolower($rel), '.html') || array_intersect(array_slice($parts, 0, -1), SYNC_SKIP_DIRS)
        || path_has_prefix($rel, SYNC_SKIP_PREFIXES)) {
        $skipped++;
        continue;
    }
    $path = "$siteDir/$rel";
    $html = file_get_contents($path);
    if ($html === false) {
        fwrite(STDERR, "Can't read $rel\n");
        exit(1);
    }
    $found = top_level_footers($html);
    if ($found === null) {
        $ambiguous[$rel] = 'unbalanced <footer> tags';
        continue;
    }
    if (!$found) {
        $skipped++;
        continue;
    }
    // The site footer is the top-level one carrying the h-card; there must be exactly one.
    $cands = array_values(array_filter($found, fn($r) => str_contains(substr($html, $r[0], $r[1] - $r[0]), 'h-card')));
    if (count($cands) !== 1) {
        $ambiguous[$rel] = count($found) . ' top-level footer(s), ' . count($cands) . ' with an h-card; not guessing';
        continue;
    }
    [$start, $end] = $cands[0];
    $old = substr($html, $start, $end - $start);

    $oldSig = footer_signature($old);
    if ($oldSig !== $canonSig && !$opts['force'] && !in_array($rel, SYNC_KNOWN_ODD, true)) {
        $refused[$rel] = signature_diff($oldSig, $canonSig);
        continue;
    }

    $want = path_has_prefix($rel, SYNC_NO_STAMP_PREFIXES) ? $footerNoStamp : $footer;
    $new = str_contains($old, "\r\n") ? str_replace("\n", "\r\n", $want) : $want;
    if ($new === $old) {
        $unchanged++;
        continue;
    }
    if (!$opts['dry-run'] && file_put_contents($path, substr($html, 0, $start) . $new . substr($html, $end)) === false) {
        fwrite(STDERR, "Can't write $rel\n");
        exit(1);
    }
    $changed++;
}

echo ($opts['dry-run'] ? 'Would change ' : 'Changed ') . "$changed, already canonical $unchanged, no footer $skipped"
    . ($commitHtml === '' ? ' (no commit line)' : ' (commit line stamped)') . PHP_EOL;
foreach ($refused as $rel => $why) {
    echo "REFUSED $rel: $why" . PHP_EOL;
}
foreach ($ambiguous as $rel => $why) {
    echo "AMBIGUOUS $rel: $why" . PHP_EOL;
}
exit($refused || $ambiguous ? 2 : 0);

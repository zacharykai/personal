#!/usr/bin/env php
<?php
/**
 * publish.php
 * Publishes a Markdown draft from the Obsidian vault as a note on zacharykai.net:
 * renders notes/<slug>.html, records its sitemap.xml entry, runs the site build,
 * commits, then ships: pushes to every remote and deploys the changed files
 * over SFTP (see ship.php).
 *
 * Usage:
 *   php assets/files/scripts/publish.php path/to/draft.md
 *
 * Options:
 *   --dry-run      Check the draft and show what would happen; change nothing.
 *   --no-preview   Skip the local preview before the publish prompt.
 *   --no-deploy    Commit and push, but don't upload to the server.
 *   -m "message"   Use this commit message instead of the generated one.
 *
 * Draft frontmatter (vault style; list or plain values both work):
 *   Note Title        required
 *   Note Slug         required, e.g. "tend" -> /notes/tend
 *   Note Description  required, one sentence
 *   Note Section      required, a sitemap section (reflections, trades, ...)
 *   Note Category     required, the site tags (comma-separated is fine)
 *   Note Blurb        optional sitemap line (defaults to the description)
 *   Note Published    optional, to backdate a new note
 * Written back after publishing: Note Status, Note Type, Note Published,
 * Note Updated, Note URL.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SITE_URL = 'https://zacharykai.net';
const WORDS_PER_MINUTE = 238;
const SNAPSHOT_EXTENSIONS = ['html', 'xml'];
const SKIP_DIRS = ['.git', 'vendor', 'node_modules'];

$siteDir = dirname(__DIR__, 3);
$errors = [];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function say(string $msg = ''): void {
    echo $msg . PHP_EOL;
}

function fail(string $msg): never {
    fwrite(STDERR, PHP_EOL . 'Error: ' . $msg . PHP_EOL);
    exit(1);
}

function ask(string $question): bool {
    if (!stream_isatty(STDIN)) {
        return false;
    }
    $answer = readline($question . ' [y/N] ');
    return is_string($answer) && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
}

function run(string $cmd, ?array &$output = null): int {
    $output = [];
    exec($cmd . ' 2>&1', $output, $code);
    return $code;
}

function git(string $args, ?array &$output = null): int {
    global $siteDir;
    return run('git -C ' . escapeshellarg($siteDir) . ' ' . $args, $output);
}

function esc(string $s): string {
    return htmlspecialchars($s, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
}

function esc_attr(string $s): string {
    return htmlspecialchars($s, ENT_COMPAT | ENT_HTML5, 'UTF-8', false);
}

function human_date(string $ymd): string {
    return date('j M Y', strtotime($ymd));
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$opts = ['dry-run' => false, 'no-preview' => false, 'no-deploy' => false, 'message' => null];
$draftArg = null;
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-m') {
        $opts['message'] = $argv[++$i] ?? fail('-m needs a message.');
    } elseif (in_array(ltrim($arg, '-'), ['dry-run', 'no-preview', 'no-deploy'], true) && str_starts_with($arg, '--')) {
        $opts[ltrim($arg, '-')] = true;
    } elseif (str_starts_with($arg, '-')) {
        fail("Unknown option: $arg");
    } else {
        $draftArg = $arg;
    }
}
if ($draftArg === null) {
    fail('No draft given. Usage: publish path/to/draft.md [--dry-run] [--no-preview] [--no-deploy] [-m "message"]');
}

// ---------------------------------------------------------------------------
// Walled-off folders: refuse before reading anything
// ---------------------------------------------------------------------------

$draftPath = realpath($draftArg);
if ($draftPath === false || !is_file($draftPath)) {
    fail("Draft not found: $draftArg");
}
if (preg_match('#/(back-room|side-door)(/|$)#i', $draftPath)) {
    fail('That draft is inside a walled-off vault folder. Publish never reads or publishes from there.');
}
if (strtolower(pathinfo($draftPath, PATHINFO_EXTENSION)) !== 'md') {
    fail('The draft must be a Markdown (.md) file.');
}

function find_vault_root(string $dir): ?string {
    while ($dir !== '/' && $dir !== '' && $dir !== '.') {
        if (is_dir($dir . '/.obsidian')) {
            return $dir;
        }
        $dir = dirname($dir);
    }
    return null;
}

$vaultRoot = find_vault_root(dirname($draftPath));

// ---------------------------------------------------------------------------
// Frontmatter
// ---------------------------------------------------------------------------

/** Splits a draft into [frontmatter lines, body]. */
function split_draft(string $text): array {
    $text = str_replace("\r\n", "\n", $text);
    if (!preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $text, $m)) {
        return [[], $text];
    }
    return [explode("\n", $m[1]), $m[2]];
}

/** Strips one pair of matching YAML quotes, unescaping as YAML does. */
function unquote(string $v): string {
    $v = trim($v);
    if (strlen($v) >= 2 && $v[0] === '"' && str_ends_with($v, '"')) {
        return stripcslashes(substr($v, 1, -1));
    }
    if (strlen($v) >= 2 && $v[0] === "'" && str_ends_with($v, "'")) {
        return str_replace("''", "'", substr($v, 1, -1));
    }
    return $v;
}

/** Parses the simple YAML Obsidian writes: scalars, inline [lists] and "- item" lists. */
function parse_frontmatter(array $lines): array {
    $fields = [];
    $key = null;
    foreach ($lines as $line) {
        if (preg_match('/^\s+-\s*(.*)$/', $line, $m) && $key !== null) {
            $fields[$key][] = unquote($m[1]);
        } elseif (preg_match('/^([^\s:#][^:]*):\s*(.*)$/', $line, $m)) {
            $key = trim($m[1]);
            $value = trim($m[2]);
            if ($value === '') {
                $fields[$key] = [];
            } elseif (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $fields[$key] = array_values(array_filter(array_map(
                    fn($v) => unquote($v), explode(',', substr($value, 1, -1))
                ), 'strlen'));
            } else {
                $fields[$key] = [unquote($value)];
            }
        }
    }
    return $fields;
}

function field(array $fields, string $key): string {
    return trim($fields[$key][0] ?? '');
}

/** Replaces (or appends) a frontmatter key, written in the vault's list style. */
function set_frontmatter(array $lines, string $key, string $value, bool $asList = true): array {
    $new = $asList ? ["$key:", "  - $value"] : ["$key: $value"];
    $out = [];
    $placed = false;
    $skipping = false;
    foreach ($lines as $line) {
        if ($skipping && preg_match('/^\s+-/', $line)) {
            continue;
        }
        $skipping = false;
        if (preg_match('/^' . preg_quote($key, '/') . ':/', $line)) {
            array_push($out, ...$new);
            $placed = true;
            $skipping = true;
            continue;
        }
        $out[] = $line;
    }
    if (!$placed) {
        array_push($out, ...$new);
    }
    return $out;
}

$draftText = file_get_contents($draftPath);
[$fmLines, $body] = split_draft($draftText);
$fm = parse_frontmatter($fmLines);

// A leading "# Title" line is the title, not part of the body.
$h1 = null;
if (preg_match('/\A\s*#\s+(.+?)\s*\n/', $body, $m)) {
    $h1 = $m[1];
    $body = substr($body, strlen($m[0]));
}

$title = field($fm, 'Note Title') ?: ($h1 ?? '');
$slug = strtolower(field($fm, 'Note Slug'));
$description = field($fm, 'Note Description');
$blurb = field($fm, 'Note Blurb') ?: lcfirst($description);
$section = strtolower(field($fm, 'Note Section'));
$tags = [];
foreach ($fm['Note Category'] ?? [] as $item) {
    foreach (explode(',', $item) as $tag) {
        $tag = strtolower(trim($tag));
        if ($tag !== '') {
            $tags[$tag] = true;
        }
    }
}
$tags = array_keys($tags);
sort($tags);

foreach (['Note Title' => $title, 'Note Slug' => $slug, 'Note Description' => $description,
          'Note Section' => $section] as $key => $value) {
    if ($value === '') {
        $errors[] = "Missing frontmatter: $key";
    }
}
if (!$tags) {
    $errors[] = 'Missing frontmatter: Note Category (the site tags)';
}
if ($slug !== '' && !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
    $errors[] = "Note Slug must be lowercase letters, numbers and hyphens: '$slug'";
}

// ---------------------------------------------------------------------------
// Sitemap
// ---------------------------------------------------------------------------

$sitemapPath = $siteDir . '/sitemap.xml';
$sitemap = file_get_contents($sitemapPath);
$noteUrl = SITE_URL . '/notes/' . $slug;

preg_match_all('#<zk:category>notes</zk:category>\s*<zk:subcategory>([^<]+)</zk:subcategory>#', $sitemap, $m);
$knownSections = array_values(array_diff(array_unique($m[1]), ['interviews']));
sort($knownSections);
preg_match_all('#<zk:tags>([^<]*)</zk:tags>#', $sitemap, $m);
$knownTags = array_unique(array_filter(array_map('trim', explode(',', implode(',', $m[1])))));

if ($section !== '' && !in_array($section, $knownSections, true)) {
    $errors[] = "Unknown Note Section '$section'. Pick one of: " . implode(', ', $knownSections);
}
$newTags = array_values(array_diff($tags, $knownTags));

/** Returns [start, length] of the <url> block for $url in the sitemap, or null. */
function find_entry(string $xml, string $url): ?array {
    $loc = strpos($xml, '<loc>' . $url . '</loc>');
    if ($loc === false) {
        return null;
    }
    $start = strrpos(substr($xml, 0, $loc), '    <url>');
    $end = strpos($xml, "    </url>\n", $loc);
    if ($start === false || $end === false) {
        return null;
    }
    return [$start, $end + strlen("    </url>\n") - $start];
}

function sitemap_entry(string $title, string $url, string $lastmod, string $blurb, string $section, array $tags): string {
    $x = fn($s) => htmlspecialchars($s, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
    $comment = str_replace('--', '–', $title);
    return "    <url>\n"
        . "        <!-- $comment -->\n"
        . "        <zk:title>{$x($title)}</zk:title>\n"
        . "        <loc>$url</loc>\n"
        . "        <lastmod>$lastmod</lastmod>\n"
        . "        <zk:content>{$x($blurb)}</zk:content>\n"
        . "        <zk:category>notes</zk:category>\n"
        . "        <zk:subcategory>$section</zk:subcategory>\n"
        . "        <zk:tags>" . implode(',', $tags) . "</zk:tags>\n"
        . "    </url>\n";
}

// ---------------------------------------------------------------------------
// New note or update?
// ---------------------------------------------------------------------------

$notePath = $siteDir . "/notes/$slug.html";
$isUpdate = false;
$today = date('Y-m-d');
$published = $today;

if ($slug !== '' && is_file($notePath)) {
    if (rtrim(field($fm, 'Note URL'), '/') !== $noteUrl) {
        $errors[] = "notes/$slug.html already exists, and this draft isn't linked to it. Pick another Note Slug, "
            . "or, if this draft really is that note, add `Note URL: $noteUrl` to its frontmatter.";
    } else {
        $isUpdate = true;
        if (preg_match('/class="dt-published" datetime="(\d{4}-\d{2}-\d{2})"/', file_get_contents($notePath), $m)) {
            $published = $m[1];
        }
    }
} elseif (field($fm, 'Note Published') !== '') {
    $ts = strtotime(field($fm, 'Note Published'));
    if ($ts === false) {
        $errors[] = "Can't read Note Published as a date: '" . field($fm, 'Note Published') . "'";
    } else {
        $published = date('Y-m-d', $ts);
    }
}
$updated = $isUpdate ? $today : $published;

// ---------------------------------------------------------------------------
// Markdown -> HTML
// ---------------------------------------------------------------------------

$images = []; // destination (site-relative) => source path

function resolve_image(string $name): ?string {
    global $draftPath, $vaultRoot;
    $candidates = [dirname($draftPath) . '/' . $name];
    if ($vaultRoot) {
        $candidates[] = $vaultRoot . '/' . $name;
        $candidates[] = $vaultRoot . '/admin/files/' . $name;
        $candidates[] = $vaultRoot . '/Admin/Files/' . $name;
    }
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return realpath($c);
        }
    }
    if ($vaultRoot) {
        $base = basename($name);
        $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($vaultRoot, FilesystemIterator::SKIP_DOTS),
            fn($f) => !($f->isDir() && in_array($f->getFilename(), ['.obsidian', '.trash', 'back-room', 'side-door'], true))
        ));
        foreach ($it as $f) {
            if ($f->getFilename() === $base) {
                return $f->getPathname();
            }
        }
    }
    return null;
}

function image_html(string $src, string $alt, int $lineNo): string {
    global $images, $slug, $errors;
    if (trim($alt) === '') {
        $errors[] = "Line $lineNo: image '$src' has no alt text. Write it as ![[$src|description]] or ![description]($src).";
    }
    if (!preg_match('#^(https?:)?//|^/#', $src)) {
        $source = resolve_image(rawurldecode($src));
        if ($source === null) {
            $errors[] = "Line $lineNo: can't find image '$src' next to the draft or in the vault.";
        } elseif (preg_match('#/(back-room|side-door)/#i', $source)) {
            $errors[] = "Line $lineNo: image '$src' is inside a walled-off vault folder.";
        } else {
            $file = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($source)));
            $dest = "assets/imgs/notes/$slug/$file";
            $images[$dest] = $source;
            $src = "/$dest";
        }
    }
    return '<img src="' . esc_attr($src) . '" alt="' . esc_attr($alt) . '" loading="lazy">';
}

function link_html(string $url, string $textHtml): string {
    $external = preg_match('#^https?://#', $url) && !preg_match('#^https?://(www\.)?zacharykai\.net#', $url);
    return '<a href="' . esc_attr($url) . '"' . ($external ? ' rel="noopener"' : '') . '>' . $textHtml . '</a>';
}

function inline(string $s, int $lineNo): string {
    global $errors;
    $ph = [];
    $keep = function (string $html) use (&$ph): string {
        $key = "\x01" . count($ph) . "\x02";
        $ph[$key] = $html;
        return $key;
    };

    $s = preg_replace_callback('/(`+)(.+?)\1/', fn($m) => $keep('<code>' . esc(trim($m[2])) . '</code>'), $s);
    $s = preg_replace_callback('/<(https?:\/\/[^>\s]+)>/', fn($m) => $keep(link_html($m[1], esc($m[1]))), $s);
    $s = preg_replace_callback('/<\/?[A-Za-z][A-Za-z0-9-]*(?:\s[^<>]*)?\/?>/', fn($m) => $keep($m[0]), $s);
    $s = preg_replace_callback('/!\[\[([^\]|]+)(?:\|([^\]]*))?\]\]/', function ($m) use ($keep, $lineNo) {
        $alt = isset($m[2]) && !preg_match('/^\d+(x\d+)?$/', $m[2]) ? $m[2] : '';
        return $keep(image_html(trim($m[1]), $alt, $lineNo));
    }, $s);
    $s = preg_replace_callback('/!\[([^\]]*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/',
        fn($m) => $keep(image_html($m[2], $m[1], $lineNo)), $s);
    $s = preg_replace_callback('/\[([^\]]+)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/',
        fn($m) => $keep(link_html($m[2], inline($m[1], $lineNo))), $s);

    if (preg_match('/\[\[([^\]]+)\]\]/', $s, $m)) {
        $errors[] = "Line $lineNo: Obsidian link [[{$m[1]}]] won't work on the site. Use [text](url) instead.";
    }
    if (str_contains($s, '[^')) {
        $errors[] = "Line $lineNo: footnotes aren't supported yet.";
    }

    $s = esc($s);
    $s = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/u', '<em>$1</em>', $s);
    $s = preg_replace('/(?<!\w)_(?=\S)(.+?)(?<=\S)_(?!\w)/u', '<em>$1</em>', $s);
    $s = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $s);
    $s = preg_replace('/==(?=\S)(.+?)(?<=\S)==/u', '<mark>$1</mark>', $s);

    for ($i = 0; $i < 5 && str_contains($s, "\x01"); $i++) {
        $s = strtr($s, $ph);
    }
    return $s;
}

/** Renders list items ([lineNo, indent, ordered, text]) as nested <ul>/<ol> lines. */
function render_list(array $items, int $depth = 0): array {
    $pad = str_repeat('    ', $depth);
    $tag = $items[0][2] ? 'ol' : 'ul';
    $base = $items[0][1];
    $out = ["$pad<$tag>"];
    $count = count($items);
    for ($i = 0; $i < $count; $i++) {
        [$no, , , $text] = $items[$i];
        $children = [];
        while ($i + 1 < $count && $items[$i + 1][1] > $base) {
            $children[] = $items[++$i];
        }
        $li = "$pad    <li>" . inline(preg_replace('/^\[[ xX]\]\s+/', '', $text), $no);
        if ($children) {
            $out[] = $li;
            array_push($out, ...render_list($children, $depth + 2));
            $out[] = "$pad    </li>";
        } else {
            $out[] = $li . '</li>';
        }
    }
    $out[] = "$pad</$tag>";
    return $out;
}

/** Turns Markdown lines into a list of blocks: ['type' => h2|html|pre, 'html' => ..., 'text' => ...]. */
function render_blocks(array $lines): array {
    global $errors;
    $blocks = [];
    $n = count($lines);
    $i = 0;
    $isBlockStart = fn(string $l) => preg_match('/^(#{1,6}\s|```|~~~|>|(\*\*\*|---|___)\s*$|\s*([-*+]|\d+[.)])\s+)/', $l);

    while ($i < $n) {
        [$no, $line] = $lines[$i];

        if (trim($line) === '') {
            $i++;
            continue;
        }

        if (preg_match('/^(```|~~~)/', $line, $fence)) {
            $code = [];
            for ($i++; $i < $n && !str_starts_with($lines[$i][1], $fence[1]); $i++) {
                $code[] = $lines[$i][1];
            }
            $i++;
            $blocks[] = ['type' => 'pre', 'html' => '<pre><code>' . esc(implode("\n", $code)) . '</code></pre>'];
            continue;
        }

        if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
            $level = strlen($m[1]);
            if ($level === 1) {
                $errors[] = "Line $no: use ## for headings. The page title comes from Note Title.";
            }
            $level = max(2, $level);
            $html = inline($m[2], $no);
            $blocks[] = ['type' => $level === 2 ? 'h2' : 'html', 'html' => "<h$level>$html</h$level>",
                         'text' => html_entity_decode(strip_tags($html))];
            $i++;
            continue;
        }

        if (preg_match('/^(\*\*\*|---|___)\s*$/', $line)) {
            $blocks[] = ['type' => 'html', 'html' => '<hr>'];
            $i++;
            continue;
        }

        if (str_starts_with($line, '>')) {
            $inner = [];
            for (; $i < $n && str_starts_with($lines[$i][1], '>'); $i++) {
                $inner[] = [$lines[$i][0], preg_replace('/^>\s?/', '', $lines[$i][1])];
            }
            if (preg_match('/^\[!\w+\]/', $inner[0][1])) {
                $errors[] = "Line $no: Obsidian callouts (> [!note]) won't work on the site.";
                continue;
            }
            $innerHtml = array_map(fn($b) => $b['html'], render_blocks($inner));
            $blocks[] = ['type' => 'html', 'html' => "<blockquote>\n    " . implode("\n    ", $innerHtml) . "\n</blockquote>"];
            continue;
        }

        if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line)) {
            $items = [];
            for (; $i < $n; $i++) {
                [$lno, $l] = $lines[$i];
                if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $l, $m)) {
                    $indent = strlen(str_replace("\t", '    ', $m[1]));
                    $items[] = [$lno, $indent, ctype_digit(rtrim($m[2], '.)')), $m[3]];
                } elseif (trim($l) !== '' && preg_match('/^\s+\S/', $l) && $items) {
                    $items[count($items) - 1][3] .= ' ' . trim($l);
                } else {
                    break;
                }
            }
            $blocks[] = ['type' => 'html', 'html' => implode("\n", render_list($items))];
            continue;
        }

        if (preg_match('/^<\/?[A-Za-z]/', $line)) {
            $raw = [];
            for (; $i < $n && trim($lines[$i][1]) !== ''; $i++) {
                $raw[] = $lines[$i][1];
            }
            $blocks[] = ['type' => 'html', 'html' => implode("\n", $raw)];
            continue;
        }

        // Paragraph: single newlines are line breaks, as in Obsidian.
        $para = [];
        for (; $i < $n && trim($lines[$i][1]) !== '' && !($para && $isBlockStart($lines[$i][1])); $i++) {
            $para[] = inline(rtrim(preg_replace('/(\s{2,}|\\\\)$/', '', $lines[$i][1])), $lines[$i][0]);
        }
        $blocks[] = ['type' => 'p', 'html' => '<p>' . implode('<br>', $para) . '</p>'];
    }
    return $blocks;
}

/** Wraps blocks in the site's section layout: intro, one <section> per ## heading, closing flourish. */
function render_body(string $markdown, int $lineOffset): array {
    // Obsidian %%comments%% are private and never published (newlines kept, so line numbers stay right).
    $markdown = preg_replace_callback('/%%.*?%%/s', fn($m) => str_repeat("\n", substr_count($m[0], "\n")), $markdown);
    $lines = [];
    foreach (explode("\n", rtrim($markdown)) as $k => $l) {
        $lines[] = [$k + 1 + $lineOffset, $l];
    }
    $blocks = render_blocks($lines);

    $sections = [['comment' => 'Introduction', 'blocks' => []]];
    foreach ($blocks as $b) {
        if ($b['type'] === 'h2') {
            $sections[] = ['comment' => $b['text'], 'blocks' => [$b]];
        } else {
            $sections[count($sections) - 1]['blocks'][] = $b;
        }
    }
    if (!$sections[0]['blocks']) {
        array_shift($sections);
    }

    // The first paragraph is the summary and the "Skip/Return To Top" target.
    $topPlaced = false;
    foreach ($sections as &$s) {
        foreach ($s['blocks'] as &$b) {
            if (!$topPlaced && $b['type'] === 'p') {
                $b['html'] = '<p id="top" class="p-summary">' . substr($b['html'], 3);
                $topPlaced = true;
            }
        }
    }
    unset($s, $b);
    if (!$topPlaced && $sections) {
        $first = &$sections[0]['blocks'][0];
        $first['html'] = preg_replace('/^<(\w+)/', '<$1 id="top"', $first['html'], 1);
        unset($first);
    }

    $pad = str_repeat(' ', 20);
    $out = [];
    foreach ($sections as $s) {
        $out[] = str_repeat(' ', 16) . '<section>';
        $out[] = $pad . '<!-- ' . str_replace('--', '–', $s['comment']) . ' -->';
        foreach ($s['blocks'] as $b) {
            $out[] = $b['type'] === 'pre'
                ? $pad . $b['html']
                : $pad . str_replace("\n", "\n" . $pad, $b['html']);
        }
        $out[] = str_repeat(' ', 16) . '</section>';
        $out[] = '';
    }
    $out[] = str_repeat(' ', 16) . '<!-- Closing -->';
    $out[] = str_repeat(' ', 16) . '<p>•--♡--•</p>';
    $html = implode("\n", $out);

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return [$html, preg_match_all("/[\p{L}\p{N}]+(?:['’][\p{L}]+)*/u", $text)];
}

$normalized = str_replace("\r\n", "\n", $draftText);
[$bodyHtml, $wordCount] = render_body($body, substr_count($normalized, "\n") - substr_count($body, "\n"));
$readingMinutes = max(1, (int) ceil($wordCount / WORDS_PER_MINUTE));

if ($errors) {
    fail("The draft needs fixing first:\n  - " . implode("\n  - ", array_unique($errors)));
}

$tagLinks = implode(' · ', array_map(fn($t) => '<a href="/tags/' . $t . '">' . $t . '</a>', $tags));
$noteHtml = strtr(file_get_contents(__DIR__ . '/templates/note.html'), [
    '{{SLUG}}' => $slug,
    '{{TITLE}}' => esc($title),
    '{{DESCRIPTION}}' => esc_attr($description),
    '{{PUBLISHED}}' => $published,
    '{{UPDATED}}' => $updated,
    '{{PUBLISHED_HUMAN}}' => human_date($published),
    '{{UPDATED_HUMAN}}' => human_date($updated),
    '{{READING_MINUTES}}' => (string) $readingMinutes,
    '{{WORD_COUNT}}' => (string) $wordCount,
    '{{TAG_LINKS}}' => $tagLinks,
    '{{BODY}}' => $bodyHtml,
]);

$entry = sitemap_entry($title, $noteUrl, $updated, $blurb, $section, $tags);

$plainTitle = strtolower($title);
$message = $opts['message'] ?? ($isUpdate
    ? "edit: revised '$title'."
    : 'post: ' . $plainTitle . (preg_match('/[.!?]$/', $plainTitle) ? '' : '.'));

say(($isUpdate ? 'Updating' : 'Publishing') . ": $title");
say("  URL:       $noteUrl");
say("  Section:   $section");
say('  Tags:      ' . implode(', ', $tags) . ($newTags ? '  (new: ' . implode(', ', $newTags) . ')' : ''));
say("  Published: " . human_date($published) . ($isUpdate ? '  |  Updated: ' . human_date($updated) : ''));
say("  Words:     $wordCount (~$readingMinutes min)");
if ($images) {
    say('  Images:    ' . implode(', ', array_keys($images)));
}
say("  Commit:    $message");

// ---------------------------------------------------------------------------
// Dry run stops here
// ---------------------------------------------------------------------------

if ($opts['dry-run']) {
    $preview = sys_get_temp_dir() . "/publish-preview-$slug.html";
    file_put_contents($preview, $noteHtml);
    say();
    say('Sitemap entry:');
    echo $entry;
    say();
    say("Dry run: nothing changed. The rendered note is at $preview");
    exit(0);
}

foreach ($newTags as $tag) {
    if (!ask("New tag '$tag' — create it?")) {
        fail("Stopped: '$tag' isn't an existing tag. Fix Note Category, or say yes to create it.");
    }
}

// ---------------------------------------------------------------------------
// Git preflight
// ---------------------------------------------------------------------------

git('rev-parse --abbrev-ref HEAD', $out);
if (trim($out[0] ?? '') !== 'main') {
    fail('The site repo is not on main. Switch to main before publishing.');
}
git('status --porcelain', $dirtyBefore);

// ---------------------------------------------------------------------------
// Snapshot, so a "no" (or a failed build) puts every file back
// ---------------------------------------------------------------------------

function snapshot(string $root): array {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !($f->isDir() && in_array($f->getFilename(), SKIP_DIRS, true))
    ));
    foreach ($it as $f) {
        if (in_array(strtolower($f->getExtension()), SNAPSHOT_EXTENSIONS, true)) {
            $files[substr($f->getPathname(), strlen($root) + 1)] = file_get_contents($f->getPathname());
        }
    }
    return $files;
}

$before = snapshot($siteDir);
$copiedImages = []; // site-relative path => previous contents (null if new)

function rollback(): void {
    global $siteDir, $before, $copiedImages;
    foreach (snapshot($siteDir) as $rel => $content) {
        if (!array_key_exists($rel, $before)) {
            unlink("$siteDir/$rel");
        } elseif ($before[$rel] !== $content) {
            file_put_contents("$siteDir/$rel", $before[$rel]);
        }
    }
    foreach ($copiedImages as $rel => $old) {
        $old === null ? @unlink("$siteDir/$rel") : file_put_contents("$siteDir/$rel", $old);
        @rmdir(dirname("$siteDir/$rel"));
    }
    say('Rolled back: the site is exactly as it was.');
}

// ---------------------------------------------------------------------------
// Write the note, its images and its sitemap entry, then build
// ---------------------------------------------------------------------------

foreach ($images as $dest => $source) {
    $abs = "$siteDir/$dest";
    $copiedImages[$dest] = is_file($abs) ? file_get_contents($abs) : null;
    @mkdir(dirname($abs), 0755, true);
    copy($source, $abs);
}

file_put_contents($notePath, $noteHtml);

$found = find_entry($sitemap, $noteUrl);
if ($found) {
    $sitemap = substr_replace($sitemap, $entry, $found[0], $found[1]);
} else {
    $marker = "    <!-- OFFERINGS -->";
    if (!str_contains($sitemap, $marker)) {
        rollback();
        fail("Couldn't find where notes end in sitemap.xml (looked for '<!-- OFFERINGS -->').");
    }
    $sitemap = str_replace($marker, $entry . "\n" . $marker, $sitemap);
}
file_put_contents($sitemapPath, $sitemap);

say();
say('Building the site...');
$code = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/sitebuild.php') . ' --offline', $buildOutput);
$buildFailed = $code !== 0 || preg_grep('/\bFAIL\b|Fatal error|Uncaught/', $buildOutput);
if ($buildFailed) {
    say(implode(PHP_EOL, $buildOutput));
    rollback();
    fail('The site build failed (output above).');
}

$after = snapshot($siteDir);
$changed = array_keys(array_filter($after, fn($c, $rel) => ($before[$rel] ?? null) !== $c, ARRAY_FILTER_USE_BOTH));
$changed = array_merge($changed, array_keys($images));
sort($changed);

say();
say('Changed files (' . count($changed) . '):');
foreach ($changed as $rel) {
    say('  ' . (array_key_exists($rel, $before) || isset($copiedImages[$rel]) ? 'M ' : 'A ') . $rel);
}

// ---------------------------------------------------------------------------
// Preview, then confirm
// ---------------------------------------------------------------------------

$server = null;
if (!$opts['no-preview']) {
    for ($port = 8000; $port < 8020; $port++) {
        $sock = @fsockopen('127.0.0.1', $port);
        if (!$sock) {
            break;
        }
        fclose($sock);
    }
    $server = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $siteDir, __DIR__ . '/preview-router.php'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes
    );
    usleep(300000);
    $previewUrl = "http://127.0.0.1:$port/notes/$slug";
    say();
    say("Preview: $previewUrl");
    run('xdg-open ' . escapeshellarg($previewUrl) . ' >/dev/null 2>&1 &');
}

// Files that already had uncommitted edits: any the build also touched get
// committed and deployed with this note, so say so before asking.
$swept = [];
$untouched = [];
foreach ($dirtyBefore as $line) {
    $path = trim(substr($line, 3), '"');
    if (in_array($path, $changed, true)) {
        $swept[] = $path;
    } else {
        $untouched[] = $path;
    }
}
if ($untouched) {
    say();
    say('Left alone (uncommitted before publishing, not part of this publish):');
    foreach ($untouched as $p) {
        say("  $p");
    }
}
if ($swept) {
    say();
    say('WARNING: these files already had your uncommitted edits, and this publish changed them too.');
    say('Publishing commits and deploys those edits along with the note:');
    foreach ($swept as $p) {
        say("  $p");
    }
}

say();
$go = ask('Publish?');
if ($server) {
    proc_terminate($server);
    proc_close($server);
}
if (!$go) {
    rollback();
    exit(1);
}

// ---------------------------------------------------------------------------
// Commit only what this publish wrote
// ---------------------------------------------------------------------------

$tracked = array_values(array_filter($changed, fn($rel) => git('check-ignore -q ' . escapeshellarg($rel)) !== 0));
git('add -- ' . implode(' ', array_map('escapeshellarg', $tracked)));
if (git('commit -m ' . escapeshellarg($message), $out) !== 0) {
    say(implode(PHP_EOL, $out));
    fail('git commit failed (output above). The files are written but not committed.');
}
say();
say("Committed: $message");

// ---------------------------------------------------------------------------
// Record the publish in the draft
// ---------------------------------------------------------------------------

$fmLines = set_frontmatter($fmLines, 'Note Status', 'Published');
if (!isset($fm['Note Type'])) {
    $fmLines = set_frontmatter($fmLines, 'Note Type', 'Note');
}
$fmLines = set_frontmatter($fmLines, 'Note Published', $published, false);
if ($isUpdate) {
    $fmLines = set_frontmatter($fmLines, 'Note Updated', $updated, false);
}
$fmLines = set_frontmatter($fmLines, 'Note URL', $noteUrl, false);
[, $originalBody] = split_draft($draftText);
file_put_contents($draftPath, "---\n" . implode("\n", $fmLines) . "\n---\n" . $originalBody);
say('Draft updated with Note Status, Note Published' . ($isUpdate ? ', Note Updated' : '') . ' and Note URL.');

// ---------------------------------------------------------------------------
// Ship: push to every remote, then deploy
// ---------------------------------------------------------------------------

// Ship finds the tracked files from git; the gitignored ones (feeds, images)
// it can't see, so name them.
$ignored = array_values(array_diff($changed, $tracked));
say();
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/ship.php')
    . ($opts['no-deploy'] ? ' --no-deploy' : '')
    . ($ignored ? ' ' . implode(' ', array_map('escapeshellarg', $ignored)) : ''), $shipCode);

say();
if ($shipCode !== 1 && !$opts['no-deploy']) {
    say("Live at: $noteUrl");
} else {
    say("Not live yet: $noteUrl goes live once a ship deploys it.");
}
exit($shipCode);

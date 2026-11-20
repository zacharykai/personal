#!/usr/bin/env php
<?php
/**
 * issue-backfill.php
 * One-off: gives every existing Site Note an Issue number. Notes are numbered by
 * published date (the page's dt-published date), oldest first, with ties broken
 * by filename A-Z. For each note it adds <zk:issue> to the sitemap.xml entry and
 * the kicker, <meta name="issue"> and metadata line to the page, then sets the
 * counter file next-issue.txt to the last number + 1.
 *
 * Usage:
 *   php assets/files/scripts/issue-backfill.php --dry-run   # print the numbered list; change nothing
 *   php assets/files/scripts/issue-backfill.php             # do it
 *
 * Only notes/<slug>.html pages with a notes entry in sitemap.xml are numbered.
 * Lists (list-*), newsletter archives (newsletter-*), the notes index, the
 * festival notes moving off the site and anything in a notes/ subfolder get no
 * Issue. A note that already has an Issue is skipped, so running this again
 * only numbers notes that were added or fixed since, continuing from the counter.
 * Run the site build afterwards to refresh the notes index and homepage.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/issue-lib.php';

$siteDir = dirname(__DIR__, 3);

function say(string $msg = ''): void {
    echo $msg . PHP_EOL;
}

function fail(string $msg): never {
    fwrite(STDERR, PHP_EOL . 'Error: ' . $msg . PHP_EOL);
    exit(1);
}

$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        fail("Unknown option: $arg. Usage: issue-backfill.php [--dry-run]");
    }
}

// ---------------------------------------------------------------------------
// Read the sitemap
// ---------------------------------------------------------------------------

$sitemapPath = $siteDir . '/sitemap.xml';
$sitemapRaw = file_get_contents($sitemapPath);
if ($sitemapRaw === false) {
    fail("Can't read sitemap.xml.");
}
$sitemapCrlf = str_contains($sitemapRaw, "\r\n");
$sitemap = str_replace("\r\n", "\n", $sitemapRaw);

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

// ---------------------------------------------------------------------------
// Work out which notes need a number
// ---------------------------------------------------------------------------

$problems = [];
$skipped = [];   // slug => why
$already = [];   // slug => issue
$todo = [];      // slug => ['date' => ..., 'page' => path, 'html' => LF-normalized page, 'crlf' => bool]

$files = glob($siteDir . '/notes/*.html') ?: [];
sort($files);
foreach ($files as $file) {
    $slug = pathinfo($file, PATHINFO_FILENAME);
    if (!issue_eligible($slug) || is_dir($siteDir . '/notes/' . $slug)) {
        continue;
    }
    $url = 'https://zacharykai.net/notes/' . $slug;
    $found = find_entry($sitemap, $url);
    if (!$found) {
        $skipped[$slug] = 'no sitemap entry';
        continue;
    }
    $entry = substr($sitemap, $found[0], $found[1]);
    if (!str_contains($entry, '<zk:category>notes</zk:category>') || str_contains($entry, '<zk:subcategory>interviews</zk:subcategory>')) {
        $skipped[$slug] = 'sitemap entry is not a note';
        continue;
    }
    if (($existing = issue_from_entry($entry)) !== null) {
        $already[$slug] = $existing;
        continue;
    }
    $raw = file_get_contents($file);
    $html = str_replace("\r\n", "\n", (string) $raw);
    if (!preg_match('/<time\s+class="dt-published"\s+datetime="(\d{4}-\d{2}-\d{2})"/', $html, $m)) {
        $skipped[$slug] = 'no published date on the page';
        continue;
    }
    if (!preg_match('#<zk:title>[^\n]*</zk:title>\n#', $entry)) {
        $problems[] = "$slug: the sitemap entry has no <zk:title> line to put the issue after";
    }
    if (!preg_match('/^[ \t]*<meta name="description"[^\n]*\n/m', $html)) {
        $problems[] = "$slug: no meta description line to put the issue meta tag after";
    }
    if (preg_match_all('/<h1 class="p-name">/', $html) !== 1) {
        $problems[] = "$slug: expected exactly one <h1 class=\"p-name\"> for the kicker";
    }
    if (!preg_match('#<details class="essentials">\s*<summary>[^<]*Metadata</summary>\s*<ul>\n[ \t]*<li>#', $html)) {
        $problems[] = "$slug: no metadata <details> block with a list to add the issue line to";
    }
    if (str_contains($html, 'class="issue"') || str_contains($html, '<meta name="issue"')) {
        $problems[] = "$slug: the page already shows an issue, but its sitemap entry has none";
    }
    $todo[$slug] = ['date' => $m[1], 'page' => $file, 'html' => $html, 'crlf' => str_contains((string) $raw, "\r\n")];
}

uksort($todo, function (string $a, string $b) use ($todo): int {
    return [$todo[$a]['date'], $a] <=> [$todo[$b]['date'], $b];
});

// ---------------------------------------------------------------------------
// Numbering
// ---------------------------------------------------------------------------

$counterFile = ISSUE_COUNTER_FILE;
$counter = issue_counter_read();
if (is_file($counterFile) && $counter === null) {
    fail(basename($counterFile) . " exists but isn't a whole number. Fix or remove it.");
}
$highest = $already ? max($already) : 0;
if ($counter === null) {
    if ($already) {
        fail('Some notes already have an Issue, but there is no ' . basename($counterFile)
            . ' to continue from. Create it with the next number to hand out.');
    }
    $counter = 1;
} elseif ($counter <= $highest) {
    fail(basename($counterFile) . " says $counter, but Issue No. $highest is already in use. Fix the counter first.");
}

$numbers = [];
$next = $counter;
foreach (array_keys($todo) as $slug) {
    $numbers[$slug] = $next++;
}

say(($dryRun ? 'Dry run: ' : '') . count($todo) . ' notes to number, oldest first (ties by filename):');
foreach ($numbers as $slug => $n) {
    say(sprintf('  No. %-3d %s  %s', $n, $todo[$slug]['date'], $slug));
}
if ($already) {
    say();
    say(count($already) . ' notes already have an Issue and are left alone: ' . implode(', ', array_keys($already)));
}
if ($skipped) {
    say();
    say('Skipped (' . count($skipped) . '), so they get no Issue:');
    foreach ($skipped as $slug => $why) {
        say("  $slug ($why)");
    }
}
say();
say('Counter ' . basename($counterFile) . ' would be set to ' . $next . '.');

if ($problems) {
    say();
    say('Problems (fix these first; nothing is changed while any remain):');
    foreach ($problems as $p) {
        say("  - $p");
    }
    if (!$dryRun) {
        exit(1);
    }
}

if ($dryRun) {
    say();
    say('Dry run: nothing changed.');
    exit($problems ? 1 : 0);
}
if (!$todo) {
    say();
    say('Nothing to do.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Build every new file in memory, then write
// ---------------------------------------------------------------------------

$pages = []; // path => new contents
foreach ($todo as $slug => $note) {
    $n = $numbers[$slug];
    $html = $note['html'];

    $html = preg_replace_callback('/^([ \t]*)(<meta name="description"[^\n]*\n)/m',
        fn($m) => $m[1] . $m[2] . $m[1] . issue_meta_tag($n) . "\n", $html, 1);
    $html = preg_replace_callback('/^([ \t]*)(<!-- Page Title -->\n[ \t]*)?<h1 class="p-name">/m',
        fn($m) => $m[1] . '<!-- Issue -->' . "\n" . $m[1] . issue_kicker_tag($n) . "\n" . $m[0], $html, 1);
    $html = preg_replace_callback('#(<details class="essentials">\s*<summary>[^<]*Metadata</summary>\s*<ul>\n)([ \t]*)(<li>)#',
        fn($m) => $m[1] . $m[2] . issue_detail_item($n) . "\n" . $m[2] . $m[3], $html, 1);

    $pages[$note['page']] = $note['crlf'] ? str_replace("\n", "\r\n", $html) : $html;

    $found = find_entry($sitemap, 'https://zacharykai.net/notes/' . $slug);
    $entry = substr($sitemap, $found[0], $found[1]);
    $entry = preg_replace_callback('#(<zk:title>[^\n]*</zk:title>\n)#',
        fn($m) => $m[1] . '        <zk:issue>' . $n . "</zk:issue>\n", $entry, 1);
    $sitemap = substr_replace($sitemap, $entry, $found[0], $found[1]);
}

foreach ($pages as $path => $contents) {
    if (file_put_contents($path, $contents) === false) {
        fail("Couldn't write $path. Pages written before it already have their numbers; sitemap.xml and the counter are unchanged.");
    }
}
if (file_put_contents($sitemapPath, $sitemapCrlf ? str_replace("\n", "\r\n", $sitemap) : $sitemap) === false) {
    fail("Couldn't write sitemap.xml.");
}
if (!issue_counter_write($next)) {
    fail("Couldn't write " . basename($counterFile) . ". Set it to $next by hand.");
}

say();
say('Numbered ' . count($todo) . ' notes. The counter is now ' . $next . '. Run sitebuild.php to refresh the listings.');

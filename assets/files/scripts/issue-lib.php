<?php
/**
 * issue-lib.php
 * Issue numbers for Site Notes ("Issue No. 12"). The number is stored once, as
 * <zk:issue> on the note's sitemap.xml entry, and everything else (the page's
 * kicker, meta tag and metadata line, the listings) is rendered from it.
 * New numbers come only from the one-line counter file next-issue.txt, which
 * holds the next number to hand out. Numbers are never reused, so a deleted
 * note leaves a gap.
 *
 * Lists (list-*), newsletter archives (newsletter-*), the notes index and the
 * festival notes that are moving off the site get no Issue.
 *
 * Used by publish.php, issue-backfill.php and sitebuild.php.
 */

const ISSUE_COUNTER_FILE = __DIR__ . '/next-issue.txt';
const ISSUE_EXCLUDED_SLUGS = ['index', 'ewf25', 'lf24', 'mwf24'];
const ISSUE_EXCLUDED_PREFIXES = ['list-', 'newsletter-'];

/** True if a Site Note with this slug gets an Issue. */
function issue_eligible(string $slug): bool {
    if (in_array($slug, ISSUE_EXCLUDED_SLUGS, true)) {
        return false;
    }
    foreach (ISSUE_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($slug, $prefix)) {
            return false;
        }
    }
    return true;
}

/** The next number to hand out, or null if the counter file is missing or isn't a positive whole number. */
function issue_counter_read(): ?int {
    if (!is_file(ISSUE_COUNTER_FILE)) {
        return null;
    }
    $value = trim((string) file_get_contents(ISSUE_COUNTER_FILE));
    return preg_match('/^[1-9][0-9]*$/', $value) ? (int) $value : null;
}

function issue_counter_write(int $next): bool {
    return file_put_contents(ISSUE_COUNTER_FILE, $next . "\n") !== false;
}

/** The Issue stored on one sitemap <url> block, or null. */
function issue_from_entry(string $entryXml): ?int {
    return preg_match('#<zk:issue>([0-9]+)</zk:issue>#', $entryXml, $m) ? (int) $m[1] : null;
}

/** Slug => Issue for every note in sitemap.xml that has one. */
function issue_map_from_sitemap(string $sitemapXml): array {
    $map = [];
    preg_match_all('#<url>(.*?)</url>#s', $sitemapXml, $entries);
    foreach ($entries[1] as $entry) {
        if (preg_match('#<loc>https://zacharykai\.net/notes/([a-z0-9-]+)</loc>#', $entry, $loc)
            && ($issue = issue_from_entry($entry)) !== null) {
            $map[$loc[1]] = $issue;
        }
    }
    return $map;
}

function issue_meta_tag(int $issue): string {
    return '<meta name="issue" content="' . $issue . '">';
}

function issue_kicker_tag(int $issue): string {
    return '<p class="issue">Issue No. ' . $issue . '</p>';
}

function issue_detail_item(int $issue): string {
    return '<li>Issue No. ' . $issue . '</li>';
}

/**
 * Fills the note template's issue placeholders ({{ISSUE_META}}, {{ISSUE_KICKER}},
 * {{ISSUE_DETAIL}}), each on a line of its own. With no Issue the whole line is
 * removed, so a note without one renders exactly as it did before.
 */
function issue_fill(string $template, ?int $issue): string {
    $eol = str_contains($template, "\r\n") ? "\r\n" : "\n";
    $lines = [
        '{{ISSUE_META}}' => $issue === null ? [] : [issue_meta_tag($issue)],
        '{{ISSUE_KICKER}}' => $issue === null ? [] : ['<!-- Issue -->', issue_kicker_tag($issue)],
        '{{ISSUE_DETAIL}}' => $issue === null ? [] : [issue_detail_item($issue)],
    ];
    foreach ($lines as $marker => $replacement) {
        $template = preg_replace_callback(
            '/^([ \t]*)' . preg_quote($marker, '/') . '\r?\n/m',
            fn($m) => $replacement ? $m[1] . implode($eol . $m[1], $replacement) . $eol : '',
            $template
        );
    }
    return $template;
}

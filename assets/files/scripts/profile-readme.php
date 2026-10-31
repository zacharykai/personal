#!/usr/bin/env php
<?php
/**
 * profile-readme.php
 * Rewrites the "latest notes" list in the GitHub profile README from the
 * site's local RSS feed: the five newest notes, each with title, date and link.
 * The README lives in its own repo, a sibling of this one (../zacharykai), and
 * is pushed to GitHub only.
 *
 * Usage:
 *   php assets/files/scripts/profile-readme.php [--dry-run]
 *
 * Options:
 *   --dry-run         Print the new list and what would be committed; change
 *                     nothing.
 *   --profile=<dir>   The profile repo's folder (default: ../zacharykai).
 *   --feed=<file>     The feed to read (default: assets/rss.xml).
 *
 * The list sits between these two lines in README.md, which are kept as-is:
 *   <!-- latest-notes:start -->
 *   <!-- latest-notes:end -->
 *
 * Leaves the README alone, and exits 0, when the feed can't be read or has no
 * usable items, when either marker is missing, when nothing in the list has
 * changed, or when the profile repo has uncommitted changes (so a draft in
 * progress is never committed by accident).
 *
 * Exit codes: 0 done or nothing to do, 1 the commit or push failed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MARKER_START = '<!-- latest-notes:start -->';
const MARKER_END = '<!-- latest-notes:end -->';
const LIST_LENGTH = 5;

$siteDir = dirname(__DIR__, 3);

function say(string $msg = ''): void {
    echo $msg . PHP_EOL;
}

function leave_alone(string $why): never {
    say("Profile README left as it was: $why.");
    exit(0);
}

function git(string $dir, string $args, ?array &$output = null): int {
    $output = [];
    exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $output, $code);
    return $code;
}

$dryRun = false;
$profileDir = dirname($siteDir) . '/zacharykai';
$feedFile = $siteDir . '/assets/rss.xml';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--profile=')) {
        $profileDir = substr($arg, strlen('--profile='));
    } elseif (str_starts_with($arg, '--feed=')) {
        $feedFile = substr($arg, strlen('--feed='));
    } else {
        fwrite(STDERR, "Unknown option: $arg" . PHP_EOL);
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// The five newest notes
// ---------------------------------------------------------------------------

if (!is_file($feedFile)) {
    leave_alone("no feed at $feedFile");
}
libxml_use_internal_errors(true);
$feed = simplexml_load_file($feedFile);
if ($feed === false || !isset($feed->channel->item)) {
    leave_alone('the feed could not be read');
}

$notes = [];
foreach ($feed->channel->item as $item) {
    $title = trim((string) $item->title);
    $link = trim((string) $item->link);
    $time = strtotime((string) $item->pubDate);
    // The notes index page is in the feed, but it isn't a note itself.
    if ($title === '' || $link === '' || $time === false || str_ends_with($link, '/notes/index')) {
        continue;
    }
    $notes[] = ['title' => $title, 'link' => $link, 'time' => $time];
}
if (!$notes) {
    leave_alone('the feed has no usable notes');
}
usort($notes, fn(array $a, array $b) => $b['time'] <=> $a['time']);
$notes = array_slice($notes, 0, LIST_LENGTH);

$lines = [];
foreach ($notes as $note) {
    $title = preg_replace('/([\\\\\[\]`*_<>])/', '\\\\$1', $note['title']);
    $lines[] = '- [' . $title . '](' . $note['link'] . ') · ' . gmdate('j M Y', $note['time']);
}
$list = implode("\n", $lines);

// ---------------------------------------------------------------------------
// The README
// ---------------------------------------------------------------------------

$readmePath = $profileDir . '/README.md';
if (!is_file($readmePath) || git($profileDir, 'rev-parse --is-inside-work-tree') !== 0) {
    leave_alone("$profileDir is not a profile repo with a README.md");
}
$readme = (string) file_get_contents($readmePath);
$start = strpos($readme, MARKER_START);
$end = strpos($readme, MARKER_END);
if ($start === false || $end === false || $end < $start) {
    leave_alone('the README has no latest-notes markers');
}
$from = $start + strlen(MARKER_START);
$updated = substr($readme, 0, $from) . "\n" . $list . "\n" . substr($readme, $end);

if ($updated === $readme) {
    leave_alone('the list is already current');
}

if (git($profileDir, 'status --porcelain', $dirty) !== 0 || $dirty) {
    leave_alone('the profile repo has uncommitted changes, so nothing was committed');
}
git($profileDir, 'symbolic-ref --quiet --short HEAD', $branch);
if (($branch[0] ?? '') !== 'main') {
    leave_alone('the profile repo is not on main');
}

say('Latest notes:');
say($list);

if ($dryRun) {
    say();
    say('Dry run: would commit README.md and push to origin. Nothing changed.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Commit and push to GitHub
// ---------------------------------------------------------------------------

file_put_contents($readmePath, $updated);
if (git($profileDir, 'commit -m ' . escapeshellarg('update: refreshed the latest notes list.') . ' -- README.md', $out) !== 0) {
    git($profileDir, 'checkout -- README.md');
    fwrite(STDERR, 'Commit failed: ' . implode(' ', $out) . PHP_EOL);
    exit(1);
}
if (git($profileDir, 'push origin HEAD:main', $out) !== 0) {
    fwrite(STDERR, 'Pushed nothing: ' . implode(' ', $out) . PHP_EOL
        . 'The commit is kept locally; retry with `git -C ' . $profileDir . ' push origin main`.' . PHP_EOL);
    exit(1);
}
say('Committed and pushed to GitHub.');
exit(0);

#!/usr/bin/env php
<?php
/**
 * ship.php
 * Pushes main to every remote, then deploys every file changed since the last
 * successful deploy over SFTP. The last deployed commit is kept in the local
 * ref refs/deploy/live, which only moves once every file has uploaded.
 *
 * Usage:
 *   php assets/files/scripts/ship.php [extra/file.jpg ...]
 *
 * Options:
 *   --since=<rev>  Deploy everything changed since <rev> instead of since the
 *                  last deploy. Needed once, before refs/deploy/live exists.
 *   --dry-run      Show what would be pushed and uploaded; change nothing.
 *   --no-deploy    Push, but don't upload. The next ship deploys these files.
 *
 * Extra paths are deployed along with the changed files, for gitignored files
 * git can't see (photos, art, feeds). Deleted and renamed-away files are listed
 * but left on the server.
 *
 * Exit codes: 0 all done, 2 deployed (or --no-deploy) but a push failed,
 * 1 anything else.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const LIVE_REF = 'refs/deploy/live';
const NOT_DEPLOYED_NAMES = ['deploy.php', 'deploy-manifest.txt', 'composer.json', 'composer.lock', '.gitignore', 'README.md', 'LICENSE'];
const NOT_DEPLOYED_PREFIXES = ['.git/', '.github/', '.vscode/', 'vendor/', 'auth/tokens/', 'assets/files/scripts/'];

$siteDir = dirname(__DIR__, 3);

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

function run(string $cmd, ?array &$output = null): int {
    $output = [];
    exec($cmd . ' 2>&1', $output, $code);
    return $code;
}

function git(string $args, ?array &$output = null): int {
    global $siteDir;
    return run('git -C ' . escapeshellarg($siteDir) . ' ' . $args, $output);
}

function not_deployed(string $rel): bool {
    $base = basename($rel);
    if ($base === '.env' || str_starts_with($base, '.env.') || in_array($rel, NOT_DEPLOYED_NAMES, true)) {
        return true;
    }
    foreach (NOT_DEPLOYED_PREFIXES as $prefix) {
        if (str_starts_with($rel . '/', $prefix)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$opts = ['dry-run' => false, 'no-deploy' => false, 'since' => null];
$extras = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $opts['since'] = substr($arg, strlen('--since='));
    } elseif (in_array($arg, ['--dry-run', '--no-deploy'], true)) {
        $opts[ltrim($arg, '-')] = true;
    } elseif (str_starts_with($arg, '-')) {
        fail("Unknown option: $arg");
    } else {
        $extras[] = $arg;
    }
}

// ---------------------------------------------------------------------------
// Preconditions
// ---------------------------------------------------------------------------

git('symbolic-ref --quiet --short HEAD', $out);
if (($out[0] ?? '') !== 'main') {
    fail('Not on main (on ' . ($out[0] ?? 'a detached HEAD') . '). Switch to main before shipping.');
}

if ($opts['since'] !== null) {
    if (git('rev-parse --verify --quiet ' . escapeshellarg($opts['since'] . '^{commit}'), $out) !== 0) {
        fail("--since: not a commit: {$opts['since']}");
    }
    $base = $out[0];
    $baseLabel = $opts['since'];
} elseif (git('rev-parse --verify --quiet ' . LIVE_REF, $out) === 0) {
    $base = $out[0];
    $baseLabel = 'last deploy';
} else {
    fail('No record of the last deploy yet. Run once with --since=<commit>, naming the newest commit'
        . ' you know is already on the server (for example --since=HEAD~3).');
}

git('rev-parse HEAD', $out);
$head = $out[0];

// ---------------------------------------------------------------------------
// What changed since the base
// ---------------------------------------------------------------------------

$diff = shell_exec('git -C ' . escapeshellarg($siteDir) . ' diff --name-status -z -M '
    . escapeshellarg($base) . ' ' . escapeshellarg($head));
$fields = explode("\0", rtrim((string) $diff, "\0"));
$upload = [];
$leftOnServer = [];
for ($i = 0; $i < count($fields) && $fields[$i] !== ''; ) {
    $status = $fields[$i++];
    if ($status[0] === 'R') {
        $leftOnServer[] = $fields[$i++] . ' (renamed)';
        $upload[] = $fields[$i++];
    } elseif ($status[0] === 'D') {
        $leftOnServer[] = $fields[$i++] . ' (deleted)';
    } else {
        $upload[] = $fields[$i++];
    }
}

foreach ($extras as $extra) {
    $real = realpath($siteDir . '/' . ltrim($extra, '/'));
    if ($real === false || !is_file($real) || !str_starts_with($real, realpath($siteDir) . '/')) {
        fail("Extra path is not a file in the site repo: $extra");
    }
    $upload[] = substr($real, strlen(realpath($siteDir)) + 1);
}
$upload = array_values(array_unique($upload));
sort($upload);

$skipped = array_values(array_filter($upload, 'not_deployed'));
$deployFiles = array_values(array_diff($upload, $skipped));

// Uploads come from the working tree, so a file with uncommitted edits would
// put work on the server that git doesn't have.
if ($deployFiles) {
    git('diff --name-only HEAD -- ' . implode(' ', array_map('escapeshellarg', $deployFiles)), $dirty);
    if ($dirty) {
        fail("These files would be deployed but have uncommitted changes. Commit or stash them first:\n  "
            . implode("\n  ", $dirty));
    }
}

git('config --get-all remote.origin.pushurl', $pushUrls);
$pushUrls = $pushUrls ?: ['origin'];

// ---------------------------------------------------------------------------
// Report the plan
// ---------------------------------------------------------------------------

say('Shipping ' . substr($head, 0, 7) . ' (changes since ' . $baseLabel . ', ' . substr($base, 0, 7) . ')');
say();
say('Push to:');
foreach ($pushUrls as $url) {
    say("  $url");
}
say();
say('Deploy (' . count($deployFiles) . '):' . ($opts['no-deploy'] ? ' skipped (--no-deploy)' : ''));
foreach ($deployFiles as $rel) {
    say("  $rel");
}
if ($skipped) {
    say();
    say('Not deployed (local-only):');
    foreach ($skipped as $rel) {
        say("  $rel");
    }
}
if ($leftOnServer) {
    say();
    say('Left on the server (remove by hand if they should go):');
    foreach ($leftOnServer as $rel) {
        say("  $rel");
    }
}
if (in_array('sitemap.xml', $deployFiles, true) && !in_array('sitemap.html', $deployFiles, true)) {
    say();
    say('WARNING: sitemap.xml changed but sitemap.html didn\'t. Run the site build if the derived pages are stale.');
}

if ($opts['dry-run']) {
    say();
    say('Dry run: nothing pushed or uploaded.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Push to every remote
// ---------------------------------------------------------------------------

$pushFailed = [];
say();
say('Pushing...');
foreach ($pushUrls as $url) {
    if (git('push ' . escapeshellarg($url) . ' HEAD:main', $out) === 0) {
        say("  ok      $url");
    } else {
        say("  FAILED  $url");
        $pushFailed[] = $url;
    }
}
git('fetch --quiet origin');

// ---------------------------------------------------------------------------
// Deploy, then record what's live
// ---------------------------------------------------------------------------

$retry = 'php assets/files/scripts/ship.php'
    . ($opts['since'] !== null ? ' --since=' . substr($base, 0, 12) : '')
    . ($extras ? ' ' . implode(' ', array_map('escapeshellarg', $extras)) : '');

if ($opts['no-deploy']) {
    $deployState = 'skipped (--no-deploy); the next ship deploys these files';
} elseif (!$deployFiles) {
    git('update-ref ' . LIVE_REF . ' ' . escapeshellarg($head));
    $deployState = 'nothing to upload';
} elseif (!is_file("$siteDir/vendor/autoload.php")) {
    $deployState = "FAILED: run `composer install` in the site repo, then `$retry`";
} else {
    $manifest = "$siteDir/deploy-manifest.txt";
    file_put_contents($manifest, implode("\n", $deployFiles) . "\n");
    say();
    say('Deploying...');
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$siteDir/deploy.php")
        . ' --manifest=' . escapeshellarg($manifest), $deployCode);
    if ($deployCode === 0) {
        unlink($manifest);
        git('update-ref ' . LIVE_REF . ' ' . escapeshellarg($head));
        $deployState = 'done';
    } else {
        $deployState = "FAILED: fix the problem above, then run `$retry`";
    }
}

say();
say('Done.');
say('  Pushed:  ' . (count($pushUrls) - count($pushFailed)) . '/' . count($pushUrls) . ' remotes'
    . ($pushFailed ? ' (failed: ' . implode(', ', $pushFailed) . '; re-run ship later)' : ''));
say("  Deploy:  $deployState");
exit(str_starts_with($deployState, 'FAILED') ? 1 : ($pushFailed ? 2 : 0));

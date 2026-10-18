#!/usr/bin/env php
<?php
/**
 * sitebuild.php
 * Combined site build script for zacharykai.net (+ lunaseeker.com and
 * roadlessread.com on the server).
 *
 * Detects environment by OS:
 *   - Linux (server): runs all tasks inline for zacharykai.net, then calls the
 *     separate cron scripts for lunaseeker.com and roadlessread.com.
 *   - Windows (local): runs location sync, RSS generation, and bakes a static
 *     index.html from index.php (resolving the latest-notes PHP block).
 *
 * Usage:
 *   php assets/files/scripts/sitebuild.php             # full local build
 *   php assets/files/scripts/sitebuild.php --offline   # skip Hardcover + location (used by publish.php)
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function log_msg(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

function run_script(string $label, string $path): bool {
    if (!file_exists($path)) {
        log_msg("SKIP $label — file not found: $path");
        return false;
    }
    log_msg("RUN  $label");
    $output = [];
    $exit   = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exit);
    foreach ($output as $line) {
        echo '       ' . $line . PHP_EOL;
    }
    if ($exit !== 0) {
        log_msg("FAIL $label (exit $exit)");
        return false;
    }
    log_msg("OK   $label");
    return true;
}

// Loads KEY=value lines from the site's .env (gitignored) into the environment.
function load_env_file(string $path): void {
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . trim($value, " \t\"'"));
        }
    }
}

function env_value(string $key): string {
    $value = getenv($key);
    return $value === false ? '' : $value;
}

// ---------------------------------------------------------------------------
// Startup
// ---------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

load_env_file(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env');

// `--offline` skips the network and the Now-page steps (Hardcover currently
// reading + location sync). publish.php always runs the build this way.
$offline = in_array('--offline', $argv, true);

log_msg('sitebuild starting (local-only)' . ($offline ? ' [offline]' : ''));

// Targeted rebuild: `php sitebuild.php gratitudes-only` rebuilds just the
// static gratitudes/index.html (function defined below; hoisted at runtime).
if (($argv[1] ?? '') === 'gratitudes-only') {
    $root = dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR; // …/site/
    bake_static_gratitudes_index($root);
    log_msg('sitebuild done (gratitudes-only)');
    exit(0);
}

// ---------------------------------------------------------------------------
// Shared: location sync
// Reads "Now? I'm in [LOCATION]." from now.html and updates target files.
// ---------------------------------------------------------------------------

function sync_location(string $site_dir): void {
    $now_file = $site_dir . 'now.html';
    $pattern  = "/Now\\? I'm in ([^.<]+)\\./";

    if (!file_exists($now_file)) {
        log_msg('SKIP update-location — now.html not found');
        return;
    }

    $now_content = file_get_contents($now_file);
    if (!preg_match($pattern, $now_content, $matches)) {
        log_msg('SKIP update-location — location pattern not found in now.html');
        return;
    }

    $location = $matches[1];
    log_msg("       Current location: $location");

    foreach (['about.html', 'index.php', 'hello.html'] as $filename) {
        $filepath = $site_dir . $filename;
        if (!file_exists($filepath)) {
            log_msg("       SKIP $filename (not found)");
            continue;
        }
        $content = file_get_contents($filepath);
        $updated = preg_replace($pattern, "Now? I'm in $location.", $content);
        if ($updated !== $content) {
            file_put_contents($filepath, $updated);
            log_msg("       Updated: $filename");
        } else {
            log_msg("       Already current: $filename");
        }
    }
}

// ---------------------------------------------------------------------------
// Shared: Hardcover currently-reading fetch + file update
// ---------------------------------------------------------------------------

define('HARDCOVER_API_TOKEN', env_value('HARDCOVER_API_TOKEN'));

function get_currently_reading(string $cache_file): array {
    $cache_duration = 43200; // 12 hours

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached !== null) {
            return $cached;
        }
    }

    if (!function_exists('curl_init')) {
        // curl not available locally — return whatever is in the cache, or empty
        return [];
    }

    $query = '{
        me {
            user_books(where: {status_id: {_eq: 2}}) {
                book {
                    title
                    slug
                    image { url }
                    cached_contributors
                }
            }
        }
    }';

    $ch = curl_init('https://api.hardcover.app/v1/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . HARDCOVER_API_TOKEN,
        ],
        CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        CURLOPT_TIMEOUT    => 10,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        return [];
    }

    $data       = json_decode($response, true);
    $user_books = $data['data']['me'][0]['user_books'] ?? [];

    $books = [];
    foreach ($user_books as $ub) {
        $book    = $ub['book'];
        $authors = [];
        foreach ($book['cached_contributors'] ?? [] as $c) {
            if (!empty($c['author']['name'])) {
                $authors[] = $c['author']['name'];
            }
        }
        $books[] = [
            'title'   => $book['title'] ?? '',
            'slug'    => $book['slug']  ?? '',
            'cover'   => $book['image']['url'] ?? '',
            'authors' => implode(', ', $authors),
        ];
    }

    $cache_dir = dirname($cache_file);
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    file_put_contents($cache_file, json_encode($books));

    return $books;
}

function replace_between_markers(string $file, string $replacement): bool {
    $start   = '<!-- CURRENTLY-READING-START -->';
    $end     = '<!-- CURRENTLY-READING-END -->';
    $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s';

    if (!file_exists($file)) {
        log_msg('ERROR: file not found: ' . $file);
        return false;
    }

    $content    = file_get_contents($file);
    $newContent = preg_replace($pattern, $replacement, $content);

    if ($newContent === null) {
        log_msg('ERROR: regex failed on ' . basename($file));
        return false;
    }
    if ($newContent === $content) {
        log_msg('ERROR: markers not found in ' . basename($file));
        return false;
    }

    file_put_contents($file, $newContent);
    return true;
}

function update_currently_reading(string $site_dir, string $cache_file): void {
    if (!function_exists('curl_init')) {
        log_msg('SKIP currentread — curl extension not available locally');
        return;
    }

    $books = get_currently_reading($cache_file);
    log_msg('Hardcover cache updated (' . count($books) . ' books)');

    if (empty($books)) {
        log_msg('No books found. Skipping currently-reading updates.');
        return;
    }

    $start = '<!-- CURRENTLY-READING-START -->';
    $end   = '<!-- CURRENTLY-READING-END -->';
    $in    = '                '; // 16 spaces

    // now.html: plain list
    $nowHtml  = $start . '<!-- Currently Reading -->' . PHP_EOL;
    $nowHtml .= $in . '<section>' . PHP_EOL;
    $nowHtml .= $in . '    <h2>Currently Reading</h2>' . PHP_EOL;
    $nowHtml .= $in . '    <p>The aim is, once I\'ve finished these, only reading one book at a time!</p>' . PHP_EOL;
    $nowHtml .= $in . '    <ul>' . PHP_EOL;
    foreach ($books as $book) {
        $entry = htmlspecialchars($book['title']);
        if ($book['authors']) {
            $entry .= ' by ' . htmlspecialchars($book['authors']);
        }
        $nowHtml .= $in . '        <li>' . $entry . '</li>' . PHP_EOL;
    }
    $nowHtml .= $in . '    </ul>' . PHP_EOL;
    $nowHtml .= $in . '</section>' . PHP_EOL;
    $nowHtml .= $in . $end;

    $ok = replace_between_markers($site_dir . 'now.html', $nowHtml);
    log_msg('now.html ' . ($ok ? 'updated' : 'FAILED'));

    // index.php: cover grid
    $indexHtml  = $start . '<!-- Currently Reading -->' . PHP_EOL;
    $indexHtml .= $in . '<section>' . PHP_EOL;
    $indexHtml .= $in . '    <h2>Currently Reading</h2>' . PHP_EOL;
    $indexHtml .= $in . '    <div class="currently-reading">' . PHP_EOL;
    foreach ($books as $book) {
        $title   = htmlspecialchars($book['title']);
        $authors = htmlspecialchars($book['authors']);
        $cover   = htmlspecialchars($book['cover']);
        $url     = 'https://hardcover.app/books/' . htmlspecialchars($book['slug']);
        $tooltip = $authors ? $title . ' by ' . $authors : $title;

        $indexHtml .= $in . '        <div class="currently-reading-book">' . PHP_EOL;
        if ($cover) {
            $indexHtml .= $in . '            <a href="' . $url . '" rel="noopener" title="' . $tooltip . '">'
                        . '<img src="' . $cover . '" alt="Cover of ' . $title . '" loading="lazy"></a>' . PHP_EOL;
        }
        $indexHtml .= $in . '            <p>' . $title . '</p>' . PHP_EOL;
        if ($authors) {
            $indexHtml .= $in . '            <p>' . $authors . '</p>' . PHP_EOL;
        }
        $indexHtml .= $in . '        </div>' . PHP_EOL;
    }
    $indexHtml .= $in . '    </div>' . PHP_EOL;
    $indexHtml .= $in . '</section>' . PHP_EOL;
    $indexHtml .= $in . $end;

    $ok = replace_between_markers($site_dir . 'index.php', $indexHtml);
    log_msg('index.php ' . ($ok ? 'updated' : 'FAILED'));
}

// ---------------------------------------------------------------------------
// Shared: RSS feed generation
// ---------------------------------------------------------------------------

function extract_html_content(string $htmlContent, string $filePath, string $siteRoot, string $siteBaseUrl): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent);
    libxml_clear_errors();

    $entry = [];
    $xpath = new DOMXPath($dom);

    $titleNodes = $dom->getElementsByTagName('title');
    if ($titleNodes->length > 0) {
        $entry['title'] = preg_replace('/ \| Zachary Kai$/i', '', trim($titleNodes->item(0)->textContent));
    } else {
        $h1Nodes = $dom->getElementsByTagName('h1');
        $entry['title'] = $h1Nodes->length > 0 ? trim($h1Nodes->item(0)->textContent) : 'Untitled Entry';
    }

    $entry['published'] = null;
    $metaNodes = $xpath->query('//meta[@name="date" or @name="published" or @property="article:published_time"]');
    if ($metaNodes->length > 0) {
        $entry['published'] = $metaNodes->item(0)->getAttribute('content');
    } else {
        $timeNodes = $xpath->query('//time[@datetime]');
        if ($timeNodes->length > 0) {
            $entry['published'] = $timeNodes->item(0)->getAttribute('datetime');
        }
    }

    $contentNode = null;
    $eContentNodes = $xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " e-content ")]');
    if ($eContentNodes->length > 0) {
        $contentNode = $eContentNodes->item(0);
    }
    $entry['content'] = $contentNode ? $dom->saveHTML($contentNode) : '';

    $relativePath = str_replace($siteRoot, '', $filePath);
    $relativePath = str_replace('\\', '/', $relativePath);
    $entry['url'] = $siteBaseUrl . ltrim($relativePath, '/');
    if (substr($entry['url'], -5) === '.html') {
        $entry['url'] = substr($entry['url'], 0, -5);
    }

    $entry['sourceFilePath'] = $filePath;

    return $entry;
}

function convert_relative_to_absolute(string $html, string $baseUrl): string {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    foreach ($xpath->query('//a[@href]') as $link) {
        $href = $link->getAttribute('href');
        if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
            $link->setAttribute('href', rtrim($baseUrl, '/') . $href);
        }
    }
    foreach ($xpath->query('//*[@src]') as $element) {
        $src = $element->getAttribute('src');
        if (strpos($src, '/') === 0 && strpos($src, '//') !== 0) {
            $element->setAttribute('src', rtrim($baseUrl, '/') . $src);
        }
    }

    return preg_replace('/<\?xml[^>]*?>\s*/', '', $dom->saveHTML());
}

function collect_html_files(string $directory): array {
    $files = [];
    if (!is_dir($directory)) return $files;
    foreach (new DirectoryIterator($directory) as $fi) {
        if ($fi->isFile() && $fi->getExtension() === 'html') {
            $files[] = $fi->getPathname();
        }
    }
    return $files;
}

function collect_md_files(string $directory): array {
    $files = [];
    if (!is_dir($directory)) return $files;
    foreach (new DirectoryIterator($directory) as $fi) {
        if ($fi->isFile() && $fi->getExtension() === 'md') {
            $files[] = $fi->getPathname();
        }
    }
    return $files;
}

/**
 * Parse a link or jot .md file into an entry array.
 * Frontmatter keys: date, id, title (links), url (links), commentary, tags.
 * For jots, title is synthesised from the date.
 */
function extract_md_content(string $filePath, string $siteRoot, string $siteBaseUrl, string $section): array {
    $raw = file_get_contents($filePath);
    if ($raw === false) return [];

    $meta    = [];
    $bodyHtml = '';

    // Strip BOM if present
    $raw = ltrim($raw, "\xEF\xBB\xBF");

    if (preg_match('/^---\s*\n(.*?)\n---\s*\n?(.*)/s', $raw, $m)) {
        foreach (explode("\n", trim($m[1])) as $line) {
            if (preg_match('/^(\w+):\s*"?(.+?)"?\s*$/', $line, $kv)) {
                $meta[$kv[1]] = $kv[2];
            }
        }
        $bodyHtml = trim($m[2]);
    } else {
        $bodyHtml = trim($raw);
    }

    $slug        = $meta['id'] ?? pathinfo($filePath, PATHINFO_FILENAME);
    $dateRaw     = $meta['date'] ?? '';
    $published   = $dateRaw ?: null;
    $siteUrl     = rtrim($siteBaseUrl, '/') . '/' . $section . '/' . $slug;

    // Title: explicit for links; synthesised for jots
    if (!empty($meta['title'])) {
        $title = $meta['title'];
    } elseif ($dateRaw) {
        $title = 'Jot: ' . date('j M Y', strtotime($dateRaw));
    } else {
        $title = 'Jot';
    }

    $tags = [];
    if (!empty($meta['tags'])) {
        $tags = array_map('trim', explode(',', $meta['tags']));
    }

    return [
        'title'          => $title,
        'published'      => $published,
        'content'        => $bodyHtml,
        'url'            => $siteUrl,
        'sourceFilePath' => $filePath,
        'tags'           => $tags,
    ];
}

function sort_entries_by_date(array &$entries): void {
    usort($entries, function ($a, $b) {
        $tA = !empty($a['published']) ? strtotime($a['published']) : filemtime($a['sourceFilePath']);
        $tB = !empty($b['published']) ? strtotime($b['published']) : filemtime($b['sourceFilePath']);
        return $tB <=> $tA;
    });
}

function build_feed(array $entries, string $title, string $description, string $selfUrl, string $siteBaseUrl, string $feedLanguage, string $feedImageUrl, bool $includeContent = true): string {
    $dom = new DOMDocument('1.0', 'UTF-8');

    $pi = $dom->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="/assets/feed.xsl"');
    $dom->appendChild($pi);

    $rss = $dom->createElement('rss');
    $rss->setAttribute('version', '2.0');
    if ($includeContent) {
        $rss->setAttribute('xmlns:content', 'http://purl.org/rss/1.0/modules/content/');
    }
    $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');
    $dom->appendChild($rss);

    $channel = $dom->createElement('channel');
    $rss->appendChild($channel);

    $channel->appendChild($dom->createElement('title', $title));
    $channel->appendChild($dom->createElement('link', $siteBaseUrl));
    $channel->appendChild($dom->createElement('description', $description));
    $channel->appendChild($dom->createElement('language', $feedLanguage));
    $channel->appendChild($dom->createElement('lastBuildDate', date(DATE_RSS)));

    $atomLink = $dom->createElement('atom:link');
    $atomLink->setAttribute('href', $siteBaseUrl . ltrim($selfUrl, '/'));
    $atomLink->setAttribute('rel', 'self');
    $atomLink->setAttribute('type', 'application/rss+xml');
    $channel->appendChild($atomLink);

    $image = $dom->createElement('image');
    $image->appendChild($dom->createElement('url', $feedImageUrl));
    $image->appendChild($dom->createElement('title', $title));
    $image->appendChild($dom->createElement('link', $siteBaseUrl));
    $channel->appendChild($image);

    foreach ($entries as $entry) {
        $pubTimestamp = !empty($entry['published']) ? strtotime($entry['published']) : false;
        if ($pubTimestamp === false) {
            $pubTimestamp = filemtime($entry['sourceFilePath']);
        }

        $item = $dom->createElement('item');
        $channel->appendChild($item);

        $item->appendChild($dom->createElement('title', htmlspecialchars($entry['title'])));
        $item->appendChild($dom->createElement('link', htmlspecialchars($entry['url'])));

        $desc = trim(strip_tags($entry['content']));
        if (strlen($desc) > 200) $desc = substr($desc, 0, 200) . '...';
        if (empty($desc)) $desc = $entry['title'];
        $item->appendChild($dom->createElement('description', htmlspecialchars($desc)));

        if ($includeContent && !empty($entry['content'])) {
            $abs = convert_relative_to_absolute($entry['content'], $siteBaseUrl);
            $ce  = $dom->createElement('content:encoded');
            $ce->appendChild($dom->createCDATASection($abs));
            $item->appendChild($ce);
        }

        $item->appendChild($dom->createElement('pubDate', date(DATE_RSS, $pubTimestamp)));

        $guid = $dom->createElement('guid', htmlspecialchars($entry['url']));
        $guid->setAttribute('isPermaLink', 'true');
        $item->appendChild($guid);
    }

    $dom->formatOutput = true;
    return $dom->saveXML();
}

function generate_rss_feeds(string $siteRoot, string $siteBaseUrl): void {
    $feedsDir    = $siteRoot . 'assets/files/feeds/';
    $sitemapPath = $siteRoot . 'sitemap.xml';
    $feedDesc    = 'Hi! I\'m Zachary Kai, a space fantasy writer, offbeat queer, twenty-something, curiosity enthusiast, and traveler.';
    $feedLang    = 'en-us';
    $feedImg     = $siteBaseUrl . 'assets/icon.png';

    if (!is_dir($feedsDir)) {
        mkdir($feedsDir, 0755, true);
    }

    $sourcesByCategory = [
        'notes' => collect_html_files($siteRoot . 'notes/'),
        'lists' => collect_html_files($siteRoot . 'lists/'),
    ];

    $singlePages = [
        'changelog'    => $siteRoot . 'changelog.html',
        'bookmarklets' => $siteRoot . 'bookmarklets.html',
        'wordle'       => $siteRoot . 'wordle.html',
    ];

    $allEntries          = [];
    $entriesByCategory   = ['notes' => [], 'lists' => []];
    $pbEntries           = [];
    $ssEntries           = [];
    $rlrEntries          = [];
    $jotsEntries         = [];
    $linksEntries        = [];

    // Series subfolders — collected separately, routed to their own arrays
    $seriesDirs = [
        'pb'  => &$pbEntries,
        'ss'  => &$ssEntries,
        'rlr' => &$rlrEntries,
    ];
    foreach ($seriesDirs as $dir => &$target) {
        foreach (collect_html_files($siteRoot . 'notes/' . $dir . '/') as $filePath) {
            if (pathinfo($filePath, PATHINFO_FILENAME) === 'index') continue;
            $html = file_get_contents($filePath);
            if ($html === false) { error_log("Could not read: $filePath"); continue; }
            $entry             = extract_html_content($html, $filePath, $siteRoot, $siteBaseUrl);
            $entry['category'] = 'notes';
            $target[]          = $entry;
        }
    }
    unset($target);

    // Jots — individual .md files in jots/
    foreach (collect_md_files($siteRoot . 'jots/') as $filePath) {
        $entry = extract_md_content($filePath, $siteRoot, $siteBaseUrl, 'jots');
        if (empty($entry)) continue;
        $jotsEntries[] = $entry;
    }

    // Links — individual .md files in links/
    foreach (collect_md_files($siteRoot . 'links/') as $filePath) {
        $entry = extract_md_content($filePath, $siteRoot, $siteBaseUrl, 'links');
        if (empty($entry)) continue;
        $linksEntries[] = $entry;
    }

    foreach ($sourcesByCategory as $cat => $files) {
        foreach ($files as $filePath) {
            // Skip any .html file whose base name matches a subdirectory (i.e. it's an index page)
            $base = pathinfo($filePath, PATHINFO_FILENAME);
            if (is_dir(dirname($filePath) . DIRECTORY_SEPARATOR . $base)) continue;
            $html = file_get_contents($filePath);
            if ($html === false) { error_log("Could not read: $filePath"); continue; }
            $entry             = extract_html_content($html, $filePath, $siteRoot, $siteBaseUrl);
            $entry['category'] = $cat;
            $allEntries[]              = $entry;
            $entriesByCategory[$cat][] = $entry;
        }
    }

    $singleEntries = [];
    foreach ($singlePages as $slug => $filePath) {
        if (!file_exists($filePath)) { error_log("Missing: $filePath"); continue; }
        $html = file_get_contents($filePath);
        if ($html === false) { error_log("Could not read: $filePath"); continue; }
        $entry             = extract_html_content($html, $filePath, $siteRoot, $siteBaseUrl);
        $entry['category'] = $slug;
        $allEntries[]      = $entry;
        $singleEntries[$slug] = [$entry];
    }

    // Tag data from sitemap
    $tagEntries = [];
    if (file_exists($sitemapPath)) {
        $sitemap = simplexml_load_file($sitemapPath);
        $sitemap->registerXPathNamespace('zk', 'https://zacharykai.net/sitemap-ext');

        $urlToEntry = [];
        foreach ($allEntries as &$e) {
            $urlToEntry[$e['url']] = &$e;
        }
        unset($e);

        foreach ($sitemap->url as $url) {
            $zk      = $url->children('https://zacharykai.net/sitemap-ext');
            $loc     = rtrim((string) $url->loc, '/');
            $tagsRaw = (string) $zk->tags;
            if (!$tagsRaw) continue;
            $tags = array_map('trim', explode(',', $tagsRaw));
            if (!isset($urlToEntry[$loc])) continue;
            $urlToEntry[$loc]['tags'] = $tags;
            foreach ($tags as $tag) {
                if (!$tag) continue;
                $tagEntries[$tag][] = &$urlToEntry[$loc];
            }
        }
    }

    sort_entries_by_date($allEntries);
    $generated  = 0;
    $feedLimit  = 25;

    // Main feed (notes only)
    sort_entries_by_date($entriesByCategory['notes']);
    if (file_put_contents($siteRoot . 'assets/rss.xml',
        build_feed(array_slice($entriesByCategory['notes'], 0, $feedLimit), 'Zachary Kai', $feedDesc, 'assets/rss.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Notes feed
    if (file_put_contents($feedsDir . 'notes.xml',
        build_feed(array_slice($entriesByCategory['notes'], 0, $feedLimit), 'Notes — Zachary Kai', 'Notes, reflections, and writings from Zachary Kai.', 'assets/files/feeds/notes.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Lists feed
    sort_entries_by_date($entriesByCategory['lists']);
    if (file_put_contents($feedsDir . 'lists.xml',
        build_feed(array_slice($entriesByCategory['lists'], 0, $feedLimit), 'Lists — Zachary Kai', 'Curated directories and lists from Zachary Kai.', 'assets/files/feeds/lists.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Single-page feeds
    $singleMeta = [
        'changelog'    => ['title' => 'Changelog — Zachary Kai',    'desc' => 'Site changes and updates from Zachary Kai.'],
        'bookmarklets' => ['title' => 'Bookmarklets — Zachary Kai', 'desc' => 'Useful JavaScript bookmarklets from Zachary Kai.'],
        'wordle'       => ['title' => 'Wordle — Zachary Kai',       'desc' => 'A log of Wordle results from Zachary Kai.'],
    ];
    foreach ($singleMeta as $slug => $meta) {
        if (!isset($singleEntries[$slug])) continue;
        if (file_put_contents($feedsDir . $slug . '.xml',
            build_feed(array_slice($singleEntries[$slug], 0, $feedLimit), $meta['title'], $meta['desc'], 'assets/files/feeds/' . $slug . '.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
            $generated++;
        }
    }

    // Tag feeds
    ksort($tagEntries);
    foreach ($tagEntries as $tag => $entries) {
        sort_entries_by_date($entries);
        if (file_put_contents($feedsDir . 'tag-' . $tag . '.xml',
            build_feed(array_slice($entries, 0, $feedLimit), ucfirst($tag) . ' — Zachary Kai', 'Pages tagged "' . $tag . '" on zacharykai.net.', 'assets/files/feeds/tag-' . $tag . '.xml', $siteBaseUrl, $feedLang, $feedImg, false)) !== false) {
            $generated++;
        }
    }

    // People and Blogs feed
    sort_entries_by_date($pbEntries);
    if (file_put_contents($feedsDir . 'pb.xml',
        build_feed(array_slice($pbEntries, 0, $feedLimit), 'People and Blogs — Zachary Kai', 'Notes from the People and Blogs series on zacharykai.net.', 'assets/files/feeds/pb.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Shelf Source feed
    sort_entries_by_date($ssEntries);
    if (file_put_contents($feedsDir . 'ss.xml',
        build_feed(array_slice($ssEntries, 0, $feedLimit), 'Shelf Source — Zachary Kai', 'Shelf Source notes from zacharykai.net.', 'assets/files/feeds/ss.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Road Less Read feed
    sort_entries_by_date($rlrEntries);
    if (file_put_contents($feedsDir . 'rlr.xml',
        build_feed(array_slice($rlrEntries, 0, $feedLimit), 'Road Less Read — Zachary Kai', 'Book notes from zacharykai.net.', 'assets/files/feeds/rlr.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Jots feed
    sort_entries_by_date($jotsEntries);
    if (file_put_contents($feedsDir . 'jots.xml',
        build_feed(array_slice($jotsEntries, 0, $feedLimit), 'Jots — Zachary Kai', 'Microblog posts and short updates from Zachary Kai.', 'assets/files/feeds/jots.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    // Links feed
    sort_entries_by_date($linksEntries);
    if (file_put_contents($feedsDir . 'links.xml',
        build_feed(array_slice($linksEntries, 0, $feedLimit), 'Links — Zachary Kai', 'Links to things worth reading, from Zachary Kai.', 'assets/files/feeds/links.xml', $siteBaseUrl, $feedLang, $feedImg)) !== false) {
        $generated++;
    }

    log_msg("Generated $generated RSS feeds");
}

// ---------------------------------------------------------------------------
// Local: overwrite the "Latest Notes" list on the index.html homepage.
// Rewrites the list in place, pulling only from the notes/ root folder
// (subfolders such as notes/pb, notes/rlr, etc. are deliberately excluded).
// ---------------------------------------------------------------------------

function bake_static_homepage(string $site_dir): void {
    $html_file = $site_dir . 'index.html';

    if (!file_exists($html_file)) {
        log_msg('SKIP bake-homepage — index.html not found');
        return;
    }

    $content = file_get_contents($html_file);

    // Locate the latest-notes block: from its marker comment to the next </ul>.
    // The surrounding <section class="hub-band-main"> … </section> wrapper is
    // preserved — only the comment, heading, and <ul> are rebuilt.
    $block_start = '<!-- Latest Notes: Script For Pulling In Latest Ones -->';
    $block_end   = '</ul>';

    $start_pos = strpos($content, $block_start);
    if ($start_pos === false) {
        log_msg('SKIP bake-homepage — latest-notes comment marker not found in index.html');
        return;
    }

    // Find the end of the <ul> that closes the list
    $end_pos = strpos($content, $block_end, $start_pos);
    if ($end_pos === false) {
        log_msg('SKIP bake-homepage — could not find closing </ul> for latest-notes block');
        return;
    }
    $end_pos += strlen($block_end);

    // Build the latest-notes list from the notes/ root folder only
    $notes_dir = $site_dir . 'notes/';
    $posts     = [];

    foreach (glob($notes_dir . '*.html') ?: [] as $file_path) {
        // Skip subfolder index pages (file whose base name matches a subdirectory)
        $base = pathinfo($file_path, PATHINFO_FILENAME);
        if (is_dir($notes_dir . $base)) continue;

        $html = file_get_contents($file_path);

        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/s', $html, $m)) {
            $title = preg_replace('/ \| Zachary Kai$/i', '', trim($m[1]));
        }

        $date_attr = '';
        if (preg_match('/<time\s+class="dt-published"\s+datetime="([^"]+)">/s', $html, $m)) {
            $date_attr = $m[1];
        }

        if ($title && $date_attr) {
            $slug    = pathinfo($file_path, PATHINFO_FILENAME);
            $posts[] = [
                'title'        => $title,
                'date_attr'    => $date_attr,
                'display_date' => date('j M Y', strtotime($date_attr)),
                'url'          => '/notes/' . $slug,
            ];
        }
    }

    usort($posts, fn($a, $b) => strtotime($b['date_attr']) - strtotime($a['date_attr']));
    $latest = array_slice($posts, 0, 6);

    $items = '';
    foreach ($latest as $post) {
        $items .= '                        <li><a href="' . htmlspecialchars($post['url']) . '">'
                . htmlspecialchars($post['title']) . '</a> | '
                . htmlspecialchars($post['display_date']) . "</li>\n";
    }

    $static_block = $block_start . "\n"
        . "                    <section class=\"hub-band-main\">\n"
        . "                        <h2>Latest Notes</h2>\n"
        . "\n"
        . "                        <ul>\n"
        . $items
        . "                        </ul>";

    $baked = substr($content, 0, $start_pos) . $static_block . substr($content, $end_pos);

    if (file_put_contents($html_file, $baked) !== false) {
        log_msg("Overwrote homepage Latest Notes: index.html (" . count($latest) . " notes listed)");
    } else {
        log_msg('FAIL bake-homepage — could not write index.html');
    }
}

// ---------------------------------------------------------------------------
// Bake a static sitemap.html from sitemap.xml
// Mirrors the logic in sitemap.php — reads sitemap.xml, builds categorized
// page data, renders the full HTML page, and writes sitemap.html.
// ---------------------------------------------------------------------------

function bake_static_sitemap(string $site_dir, string $base_url): void {
    $sitemap_xml  = $site_dir . 'sitemap.xml';
    $sitemap_html = $site_dir . 'sitemap.html';

    if (!file_exists($sitemap_xml)) {
        log_msg('SKIP bake-sitemap — sitemap.xml not found');
        return;
    }

    // --- Parse sitemap.xml into categorized_pages (same logic as sitemap.php) ---
    $categorized_pages = [];

    $dom = new DOMDocument();
    if (!$dom->load($sitemap_xml)) {
        log_msg('FAIL bake-sitemap — could not load sitemap.xml');
        return;
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s',  'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xpath->registerNamespace('zk', 'https://zacharykai.net/sitemap-ext');

    foreach ($xpath->query('//s:url') as $urlNode) {
        $url = $title = $content = $category_key = $subcategory_key = $subsubcategory_key = '';

        $locNode = $xpath->query('s:loc', $urlNode)->item(0);
        if ($locNode) {
            $fullUrl = trim($locNode->nodeValue);
            $url = str_replace($base_url, '', $fullUrl);
            if (empty($url)) {
                $url = '/';
            } elseif (substr($url, -1) === '/' && $url !== '/') {
                $url = rtrim($url, '/');
            }
        }

        $titleNode = $xpath->query('zk:title', $urlNode)->item(0);
        if ($titleNode) {
            $title = trim($titleNode->nodeValue);
        } elseif (!empty($url)) {
            $cleaned = preg_replace('/\.(php|html|htm)$/i', '', ltrim($url, '/'));
            $parts   = explode('/', $cleaned);
            $last    = end($parts);
            $title   = ucwords(str_replace('-', ' ', $last ?: (count($parts) > 1 ? prev($parts) : 'Homepage')));
            if ($title === '') $title = 'Homepage';
        }

        $contentNode = $xpath->query('zk:content', $urlNode)->item(0);
        if ($contentNode) $content = trim($contentNode->nodeValue);

        $categoryNode = $xpath->query('zk:category', $urlNode)->item(0);
        if ($categoryNode) $category_key = trim($categoryNode->nodeValue);

        $subcategoryNode = $xpath->query('zk:subcategory', $urlNode)->item(0);
        if ($subcategoryNode) $subcategory_key = trim($subcategoryNode->nodeValue);

        $subsubcategoryNode = $xpath->query('zk:subsubcategory', $urlNode)->item(0);
        if ($subsubcategoryNode) $subsubcategory_key = trim($subsubcategoryNode->nodeValue);

        if (empty($url) || empty($title) || empty($category_key)) continue;

        $page_data = ['title' => $title, 'url' => $url, 'content' => $content];

        if (!isset($categorized_pages[$category_key])) {
            $categorized_pages[$category_key] = [
                'title'         => ucwords(str_replace('_', ' ', $category_key)),
                'urls'          => [],
                'subcategories' => [],
            ];
        }

        if ($category_key === 'zines' && ($url === '/zines/' || $url === '/zines')) {
            $page_data['url'] = '/zines/';
            $categorized_pages[$category_key]['index_page'] = $page_data;
        } elseif (!empty($subcategory_key)) {
            if (!isset($categorized_pages[$category_key]['subcategories'][$subcategory_key])) {
                $categorized_pages[$category_key]['subcategories'][$subcategory_key] = [
                    'title'             => ucwords(str_replace('_', ' ', $subcategory_key)),
                    'urls'              => [],
                    'subsubcategories'  => [],
                ];
            }
            if (!empty($subsubcategory_key)) {
                if (!isset($categorized_pages[$category_key]['subcategories'][$subcategory_key]['subsubcategories'][$subsubcategory_key])) {
                    $categorized_pages[$category_key]['subcategories'][$subcategory_key]['subsubcategories'][$subsubcategory_key] = [
                        'title' => ucwords(str_replace('_', ' ', $subsubcategory_key)),
                        'urls'  => [],
                    ];
                }
                $categorized_pages[$category_key]['subcategories'][$subcategory_key]['subsubcategories'][$subsubcategory_key]['urls'][] = $page_data;
            } else {
                $categorized_pages[$category_key]['subcategories'][$subcategory_key]['urls'][] = $page_data;
            }
        } else {
            $categorized_pages[$category_key]['urls'][] = $page_data;
        }
    }

    // Sort everything alphabetically by title
    uasort($categorized_pages, fn($a, $b) => strcmp($a['title'], $b['title']));
    foreach ($categorized_pages as &$category) {
        usort($category['urls'], fn($a, $b) => strcmp($a['title'], $b['title']));
        uasort($category['subcategories'], fn($a, $b) => strcmp($a['title'], $b['title']));
        foreach ($category['subcategories'] as &$subcat) {
            usort($subcat['urls'], fn($a, $b) => strcmp($a['title'], $b['title']));
            uasort($subcat['subsubcategories'], fn($a, $b) => strcmp($a['title'], $b['title']));
            foreach ($subcat['subsubcategories'] as &$subsubcat) {
                usort($subsubcat['urls'], fn($a, $b) => strcmp($a['title'], $b['title']));
            }
            unset($subsubcat);
        }
        unset($subcat);
    }
    unset($category);

    // --- Render HTML ---
    $e = 'htmlspecialchars'; // shorthand

    // Table of contents
    $toc = '';
    foreach ($categorized_pages as $cat_key => $category) {
        $has_urls        = !empty($category['urls']);
        $has_subcat_urls = false;
        foreach (($category['subcategories'] ?? []) as $subcat) {
            if (!empty($subcat['urls']) || !empty($subcat['subsubcategories'])) { $has_subcat_urls = true; break; }
        }
        if (!$has_urls && !$has_subcat_urls && !isset($category['index_page'])) continue;

        if ($cat_key === 'zines' && isset($category['index_page'])) {
            $toc .= '                            <li><a href="' . $e($category['index_page']['url']) . '">' . $e($category['index_page']['title']) . "</a></li>\n";
            continue;
        }

        $toc .= '                            <li><a href="#' . $e($cat_key) . '">' . $e($category['title']) . "</a>";

        $has_sub_items = !empty($category['urls']) || $has_subcat_urls;
        if ($has_sub_items) {
            $toc .= "\n                                <ul>\n";
            foreach ($category['urls'] as $page) {
                $toc .= '                                    <li><a href="' . $e($page['url']) . '">' . $e($page['title']) . "</a></li>\n";
            }
            foreach (($category['subcategories'] ?? []) as $subcat_key => $subcat) {
                $subcat_has_direct = !empty($subcat['urls']);
                $subcat_has_subsub = !empty($subcat['subsubcategories']);
                if (!$subcat_has_direct && !$subcat_has_subsub) continue;
                $toc .= '                                    <li><a href="#' . $e($subcat_key) . '">' . $e($subcat['title']) . "</a>";
                if ($subcat_has_subsub) {
                    $toc .= "\n                                        <ul>\n";
                    foreach ($subcat['subsubcategories'] as $subsubcat_key => $subsubcat) {
                        if (empty($subsubcat['urls'])) continue;
                        $toc .= '                                            <li><a href="#' . $e($subsubcat_key) . '">' . $e($subsubcat['title']) . "</a></li>\n";
                    }
                    $toc .= "                                        </ul>\n                                    ";
                }
                $toc .= "</li>\n";
            }
            $toc .= "                                </ul>\n                            ";
        }
        $toc .= "</li>\n";
    }

    // Category sections
    $sections = '';
    foreach ($categorized_pages as $cat_key => $category) {
        $has_urls        = !empty($category['urls']);
        $has_subcat_urls = false;
        foreach (($category['subcategories'] ?? []) as $subcat) {
            if (!empty($subcat['urls']) || !empty($subcat['subsubcategories'])) { $has_subcat_urls = true; break; }
        }
        if (!$has_urls && !$has_subcat_urls && !isset($category['index_page'])) continue;

        $sections .= "\n                <hr>\n";
        $sections .= '                <section id="' . $e($cat_key) . "\">\n";

        if ($cat_key === 'zines' && isset($category['index_page'])) {
            $sections .= '                    <h2><a href="' . $e($category['index_page']['url']) . '">' . $e($category['index_page']['title']) . "</a></h2>\n";
        } else {
            $sections .= '                    <h2>' . $e($category['title']) . "</h2>\n";
        }

        if (!empty($category['urls'])) {
            $sections .= "                    <ul>\n";
            foreach ($category['urls'] as $page) {
                $sections .= '                        <li><a href="' . $e($page['url']) . '">' . $e($page['title']) . '</a>: ' . $e($page['content']) . "</li>\n";
            }
            $sections .= "                    </ul>\n";
        }

        foreach (($category['subcategories'] ?? []) as $subcat_key => $subcat) {
            $subcat_has_direct = !empty($subcat['urls']);
            $subcat_has_subsub = !empty($subcat['subsubcategories']);
            if (!$subcat_has_direct && !$subcat_has_subsub) continue;

            $sections .= '                    <h3 id="' . $e($subcat_key) . '">' . $e($subcat['title']) . "</h3>\n";
            if ($subcat_has_direct) {
                $sections .= "                    <ul>\n";
                foreach ($subcat['urls'] as $page) {
                    $sections .= '                        <li><a href="' . $e($page['url']) . '">' . $e($page['title']) . '</a>: ' . $e($page['content']) . "</li>\n";
                }
                $sections .= "                    </ul>\n";
            }
            if ($subcat_has_subsub) {
                foreach ($subcat['subsubcategories'] as $subsubcat_key => $subsubcat) {
                    if (empty($subsubcat['urls'])) continue;
                    $sections .= '                    <h4 id="' . $e($subsubcat_key) . '">' . $e($subsubcat['title']) . "</h4>\n";
                    $sections .= "                    <ul>\n";
                    foreach ($subsubcat['urls'] as $page) {
                        $sections .= '                        <li><a href="' . $e($page['url']) . '">' . $e($page['title']) . '</a>: ' . $e($page['content']) . "</li>\n";
                    }
                    $sections .= "                    </ul>\n";
                }
            }
        }

        $sections .= "                </section>\n";
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en-US">

    <!-- Head -->
    <head>
        <!-- Meta Tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- File Links -->
        <link rel="shortcut icon" href="/assets/icon.ico" type="image/x-icon">
        <link rel="stylesheet" href="/assets/style.css">
        <link rel="alternate" type="application/rss+xml" title="Zachary Kai" href="/assets/rss.xml">
        <link rel="webmention" href="https://webmention.io/zacharykai.net/webmention" />
        <link rel="canonical" href="https://zacharykai.net/sitemap">
        <!-- Page Info -->
        <title>Sitemap | Zachary Kai</title>
        <meta name="date" content="2024-06-26">
        <meta name="last-modified" content="2026-01-06">
        <meta name="description" content="Herein are all pages, notes, and assorted miscellanea on this site so far. Always in progress. Enjoy your perusal and I hope you find (something) useful.">
    </head>

    <!-- Body -->
    <body>        <!-- Sidebar -->
        <aside class="sidebar">

            <!-- Skip/Return Link -->
            <p><a href="#top" class="smalltext">Skip/Return To Top</a></p>

            <!-- Mobile Menu Toggle -->
            <input type="checkbox" class="menu-toggle" id="menu-toggle">

            <header>
                <div>
                    <p class="sitetitle"><a href="/">Zachary Kai</a></p>
                    <label for="menu-toggle" class="menu-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </label>
                </div>
                <nav>
                    <p><a href="/about"><img src="/assets/imgs/svg/user.svg" class="nav-icon" alt="">About</a></p>
                    <p><a href="/jots"><img src="/assets/imgs/svg/jots.svg" class="nav-icon" alt="">Jots</a></p>
                    <p><a href="/links"><img src="/assets/imgs/svg/links.svg" class="nav-icon" alt="">Links</a></p>
                    <p><a href="/notes"><img src="/assets/imgs/svg/pencil-line.svg" class="nav-icon" alt="">Notes</a></p>
                    <p><a href="/now"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="">Now</a></p>
                    <p><a href="/pages"><img src="/assets/imgs/svg/files.svg" class="nav-icon" alt="">Pages</a></p>
                    <p><a href="/projects"><img src="/assets/imgs/svg/projects.svg" class="nav-icon" alt="">Projects</a></p>
                    <p><a href="/sitemap"><img src="/assets/imgs/svg/tree-structure.svg" class="nav-icon" alt="">Sitemap</a></p>
                    <p class="sidebar-icons"><a href="/contact" aria-label="Contact"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a> <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a> <a href="/subscribe" aria-label="Subscribe"><img src="/assets/imgs/svg/rss.svg" class="nav-icon" alt="RSS"></a></p>
                </nav>
            </header>

            <p class="smalltext">Est. 2019</p>

        </aside>

        <!-- Main Content Area -->
        <div class="main-content-area">
        <!-- Main Content -->
        <main class="h-entry e-content">

            <!-- Page Header -->
            <header>
                <!-- Breadcrumbs -->
                <p class="essentials"><a href="/">Homepage</a> • <a href="/sitemap#pages">Pages</a></p>
                <!-- Page Title -->
                <h1 class="p-name">Sitemap</h1>
                <!-- Necessary Metadata -->
                <p class="essentials">
                    <strong>Written By</strong>: <a href="/about">Zachary Kai</a> »
                    <strong>Published</strong>: <time class="dt-published" datetime="2024-06-26">26 Jun 2024</time> |
                    <strong>Updated</strong>: <time class="dt-modified" datetime="2026-01-06">6 Jan 2026</time>
                </p>
                <!-- Other Metadata -->
                <details class="essentials">
                    <summary>Expand For Other (Hopefully Useful) Metadata</summary>
                    <ul>
                        <li>Reading Time: ~1 min (at 238 WPM)
                        <li>Word Count: 14</li>
                    </ul>
                </details>
            </header>

            <!-- Page Body -->
            <section class="e-content">
                <p id="top" class="p-summary">Everything on my site (so far.) Please enjoy.</p>
                <section id="table-of-contents">
                    <details>
                        <summary><strong>Table Of Contents</strong></summary>
                        <ul>
$toc                        </ul>
                    </details>
                </section>
$sections
                <p>•--♡--•</p>


            </section>

            <section>
                <!-- Closing Metadata -->

                <section>
                    <!-- Tags -->
                    <p class="essentials"><strong>Tags</strong>: <a href="/tags/indieweb">indieweb</a></p>
                </section>

                <section>
                    <!-- Copy + Share -->
                    <p><strong>Copy + Share</strong>: <a href="/sitemap">zacharykai.net/sitemap</a></p>
                </section>

            </section>

        </main>

        <!-- Back To Top Link -->
        <p><a href="#top" class="essentials">Read again...</a></p>

        <!-- Divider -->
        <div class="end"></div>

        <!-- Footer -->
        <footer>

            <!-- Support Me -->
            <section class="support">
                <h2>Enjoy What I Do? Find It (Hopefully) Helpful?</h2>
                <p>I'm glad! If you feel moved to <a href="/support">support me</a>, it's most appreciated.</p>
            </section>

            <hr>

            <!-- H-Card -->
            <section class="h-card vcard">

                <!-- Avatar -->
                <section id="h-card-image">
                    <picture>
                        <source srcset="/assets/icon.webp" type="image/webp">
                        <img class="u-photo" loading="lazy" src="/assets/icon.png" alt="Zach's digital drawing: blue/green/purple book stack, decorated with green leaves and purple hearts.">
                    </picture>
                </section>

                <!-- Byline -->
                <section id="h-card-content">
                    <p>
                        <!-- Name + URL -->
                        <strong><a class="u-url u-id p-name" href="https://zacharykai.net" rel="me"><span class="fn">Zachary Kai</span></a></strong> 
                        — 
                        <!-- Pronouns-->
                        <span class="p-pronouns">he/him</span> 
                        — 
                        <!-- Email -->
                        <a class="u-email email" href="mailto:hi@zacharykai.net" rel="me">hi@zacharykai.net</a>
                        — 
                        <!-- Biography -->
                        <span class="p-note">A space fantasy writer, offbeat queer, traveler, zinester, and avowed generalist. The internet is his livelihood and lifeline.</span>
                    </p>
                </section>

            </section>

            <hr>

            <!-- Acknowledgement Of Country -->
            <p><strong>Acknowledgement</strong>: I owe my existence to the <a href="https://kht.org.au/" rel="noopener">Koori people's</a> lands: tended for millennia by the traditional storytellers. What a legacy. May it prevail.</p>

            <hr>

            <!-- Reply Via & Elsewhere -->
            <p class="footer-icons">
                <strong>Reply Via</strong>:
                <a href="/contact" aria-label="Email"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a>
                <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a>
                <a href="/unoffice-hours" aria-label="UnOffice Hours"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="UnOffice Hours"></a>
                <a href="/webmentions" rel="noopener" aria-label="Webmention"><img src="/assets/imgs/svg/at.svg" class="nav-icon" alt="Webmention"></a>
                <strong style="margin-left: 0.75em;">Elsewhere</strong>:
                <a href="https://zacharykai.net/newsletter/" rel="noopener" aria-label="Newsletter"><img src="/assets/imgs/svg/newspaper.svg" class="nav-icon" alt="Newsletter"></a>
                <a href="https://ko-fi.com/zacharykai" rel="noopener" aria-label="Ko-fi"><img src="/assets/imgs/svg/coffee.svg" class="nav-icon" alt="Ko-fi"></a>
                <a href="https://lunaseeker.metalabel.com/" rel="noopener" aria-label="Metalabel"><img src="/assets/imgs/svg/tag.svg" class="nav-icon" alt="Metalabel"></a>
            </p>

            <hr>

            <!-- Footer Menu -->
            <p style="margin-bottom: 0;">
                <a href="/about">About</a> |
                <a href="/art">Art</a> |
                <a href="/accessibility">Accessibility</a> |
                <a href="/colophon">Colophon</a> |
                <a href="/changelog">Changelog</a> |
                <a href="/press">Press</a> | 
                <a href="/random">Random</a> |
                <a href="/uses">Uses</a>
            </p>

            <hr>

            <!-- Final Message -->
            <p class="essentials" style="margin-top: 2em;">Hi. Yes, you, lovely human. Have a wonderful <a href="https://indieweb.org/Universal_Greeting_Time" rel="noopener">morning</a> and make time for a small moment of joy, wherever you are. ♡</p>

        </footer>

        </div><!-- /.main-content-area -->


    </body>
</html>
HTML;

    if (file_put_contents($sitemap_html, $html) !== false) {
        log_msg('Baked static sitemap: sitemap.html');
    } else {
        log_msg('FAIL bake-sitemap — could not write sitemap.html');
    }
}

// ---------------------------------------------------------------------------
// Bake static tags pages: tags.html (index) + tags/{tag}.html per tag.
// Mirrors the logic in tags.php — parses sitemap.xml for zk:tags elements.
// ---------------------------------------------------------------------------

function bake_static_tags(string $site_dir, string $base_url): void {
    $sitemap_xml = $site_dir . 'sitemap.xml';
    $tags_dir    = $site_dir . 'tags' . DIRECTORY_SEPARATOR;
    $index_html  = $site_dir . 'tags.html';

    if (!file_exists($sitemap_xml)) {
        log_msg('SKIP bake-tags — sitemap.xml not found');
        return;
    }

    // Parse tags from sitemap.xml
    $dom = new DOMDocument();
    if (!$dom->load($sitemap_xml)) {
        log_msg('FAIL bake-tags — could not load sitemap.xml');
        return;
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s',  'http://www.sitemaps.org/schemas/sitemap/0.9');
    $xpath->registerNamespace('zk', 'https://zacharykai.net/sitemap-ext');

    $all_tags  = [];
    $tag_pages = [];

    foreach ($xpath->query('//s:url') as $urlNode) {
        $locNode   = $xpath->query('s:loc',      $urlNode)->item(0);
        $titleNode = $xpath->query('zk:title',   $urlNode)->item(0);
        $tagsNode  = $xpath->query('zk:tags',    $urlNode)->item(0);

        if (!$tagsNode || !$locNode) continue;

        $tags_raw = trim($tagsNode->nodeValue);
        if ($tags_raw === '') continue;

        $full_url = trim($locNode->nodeValue);
        $path     = str_replace($base_url, '/', $full_url);
        $path     = '/' . ltrim($path, '/');
        $path     = rtrim($path, '/') ?: '/';

        $title = $titleNode ? trim($titleNode->nodeValue) : '';
        if ($title === '') {
            $cleaned = preg_replace('/\.(php|html|htm)$/i', '', ltrim($path, '/'));
            $parts   = explode('/', $cleaned);
            $title   = ucwords(str_replace('-', ' ', end($parts) ?: 'Homepage'));
        }

        foreach (array_map('trim', explode(',', $tags_raw)) as $tag) {
            if ($tag === '') continue;
            $tag_pages[$tag][] = ['title' => $title, 'url' => $path];
            $all_tags[$tag]    = ($all_tags[$tag] ?? 0) + 1;
        }
    }

    ksort($all_tags);
    foreach ($tag_pages as &$pages) {
        usort($pages, fn($a, $b) => strcmp($a['title'], $b['title']));
    }
    unset($pages);

    // Ensure tags/ directory exists
    if (!is_dir($tags_dir) && !mkdir($tags_dir, 0755, true)) {
        log_msg('FAIL bake-tags — could not create tags/ directory');
        return;
    }

    $e = 'htmlspecialchars';

    // Shared boilerplate
    $sidebar = <<<'SIDEBAR'
    <!-- Body -->
    <body>        <!-- Sidebar -->
        <aside class="sidebar">

            <!-- Skip/Return Link -->
            <p><a href="#top" class="smalltext">Skip/Return To Top</a></p>

            <!-- Mobile Menu Toggle -->
            <input type="checkbox" class="menu-toggle" id="menu-toggle">

            <header>
                <div>
                    <p class="sitetitle"><a href="/">Zachary Kai</a></p>
                    <label for="menu-toggle" class="menu-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </label>
                </div>
                <nav>
                    <p><a href="/about"><img src="/assets/imgs/svg/user.svg" class="nav-icon" alt="">About</a></p>
                    <p><a href="/jots"><img src="/assets/imgs/svg/jots.svg" class="nav-icon" alt="">Jots</a></p>
                    <p><a href="/links"><img src="/assets/imgs/svg/links.svg" class="nav-icon" alt="">Links</a></p>
                    <p><a href="/notes"><img src="/assets/imgs/svg/pencil-line.svg" class="nav-icon" alt="">Notes</a></p>
                    <p><a href="/now"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="">Now</a></p>
                    <p><a href="/pages"><img src="/assets/imgs/svg/files.svg" class="nav-icon" alt="">Pages</a></p>
                    <p><a href="/projects"><img src="/assets/imgs/svg/projects.svg" class="nav-icon" alt="">Projects</a></p>
                    <p><a href="/sitemap"><img src="/assets/imgs/svg/tree-structure.svg" class="nav-icon" alt="">Sitemap</a></p>
                    <p class="sidebar-icons"><a href="/contact" aria-label="Contact"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a> <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a> <a href="/subscribe" aria-label="Subscribe"><img src="/assets/imgs/svg/rss.svg" class="nav-icon" alt="RSS"></a></p>
                </nav>
            </header>

            <p class="smalltext">Est. 2019</p>

        </aside>
SIDEBAR;

    $footer = <<<'FOOTER'
        <!-- Back To Top Link -->
        <p><a href="#top" class="essentials">Read again...</a></p>

        <!-- Divider -->
        <div class="end"></div>

        <!-- Footer -->
        <footer>

            <!-- Support Me -->
            <section class="support">
                <h2>Enjoy What I Do? Find It (Hopefully) Helpful?</h2>
                <p>I'm glad! If you feel moved to <a href="/support">support me</a>, it's most appreciated.</p>
            </section>

            <hr>

            <!-- H-Card -->
            <section class="h-card vcard">

                <!-- Avatar -->
                <section id="h-card-image">
                    <picture>
                        <source srcset="/assets/icon.webp" type="image/webp">
                        <img class="u-photo" loading="lazy" src="/assets/icon.png" alt="Zach's digital drawing: blue/green/purple book stack, decorated with green leaves and purple hearts.">
                    </picture>
                </section>

                <!-- Byline -->
                <section id="h-card-content">
                    <p>
                        <!-- Name + URL -->
                        <strong><a class="u-url u-id p-name" href="https://zacharykai.net" rel="me"><span class="fn">Zachary Kai</span></a></strong> 
                        — 
                        <!-- Pronouns-->
                        <span class="p-pronouns">he/him</span> 
                        — 
                        <!-- Email -->
                        <a class="u-email email" href="mailto:hi@zacharykai.net" rel="me">hi@zacharykai.net</a>
                        — 
                        <!-- Biography -->
                        <span class="p-note">A space fantasy writer, offbeat queer, traveler, zinester, and avowed generalist. The internet is his livelihood and lifeline.</span>
                    </p>
                </section>

            </section>

            <hr>

            <!-- Acknowledgement Of Country -->
            <p><strong>Acknowledgement</strong>: I owe my existence to the <a href="https://kht.org.au/" rel="noopener">Koori people's</a> lands: tended for millennia by the traditional storytellers. What a legacy. May it prevail.</p>

            <hr>

            <!-- Reply Via & Elsewhere -->
            <p class="footer-icons">
                <strong>Reply Via</strong>:
                <a href="/contact" aria-label="Email"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a>
                <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a>
                <a href="/unoffice-hours" aria-label="UnOffice Hours"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="UnOffice Hours"></a>
                <a href="/webmentions" rel="noopener" aria-label="Webmention"><img src="/assets/imgs/svg/at.svg" class="nav-icon" alt="Webmention"></a>
                <strong style="margin-left: 0.75em;">Elsewhere</strong>:
                <a href="https://zacharykai.net/newsletter/" rel="noopener" aria-label="Newsletter"><img src="/assets/imgs/svg/newspaper.svg" class="nav-icon" alt="Newsletter"></a>
                <a href="https://ko-fi.com/zacharykai" rel="noopener" aria-label="Ko-fi"><img src="/assets/imgs/svg/coffee.svg" class="nav-icon" alt="Ko-fi"></a>
                <a href="https://lunaseeker.metalabel.com/" rel="noopener" aria-label="Metalabel"><img src="/assets/imgs/svg/tag.svg" class="nav-icon" alt="Metalabel"></a>
            </p>

            <hr>

            <!-- Footer Menu -->
            <p style="margin-bottom: 0;">
                <a href="/about">About</a> |
                <a href="/art">Art</a> |
                <a href="/accessibility">Accessibility</a> |
                <a href="/colophon">Colophon</a> |
                <a href="/changelog">Changelog</a> |
                <a href="/press">Press</a> | 
                <a href="/random">Random</a> |
                <a href="/uses">Uses</a>
            </p>

            <hr>

            <!-- Final Message -->
            <p class="essentials" style="margin-top: 2em;">Hi. Yes, you, lovely human. Have a wonderful <a href="https://indieweb.org/Universal_Greeting_Time" rel="noopener">morning</a> and make time for a small moment of joy, wherever you are. ♡</p>

        </footer>

        </div><!-- /.main-content-area -->


    </body>
</html>
FOOTER;

    // Build the "Browse Tags" link list used by all pages
    $browse_links = [];
    foreach ($all_tags as $tag => $count) {
        $browse_links[] = '<a href="/tags/' . $e(urlencode($tag)) . '">' . $e($tag) . '</a>';
    }
    $browse_str = implode(' · ', $browse_links);

    // --- Bake tags.html (index) ---
    $tag_list_items = '';
    foreach ($all_tags as $tag => $count) {
        $tag_list_items .= '                    <li><a href="/tags/' . $e(urlencode($tag)) . '">' . $e($tag) . '</a> <span class="essentials">(' . $count . ")</span></li>\n";
    }

    $index_page = <<<HTML
<!DOCTYPE html>
<html lang="en-US">

    <!-- Head -->
    <head>
        <!-- Meta Tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- File Links -->
        <link rel="shortcut icon" href="/assets/icon.ico" type="image/x-icon">
        <link rel="stylesheet" href="/assets/style.css">
        <link rel="alternate" type="application/rss+xml" title="Zachary Kai" href="/assets/rss.xml">
        <link rel="webmention" href="https://webmention.io/zacharykai.net/webmention" />
        <link rel="canonical" href="https://zacharykai.net/tags">
        <!-- Page Info -->
        <title>Tags | Zachary Kai</title>
        <meta name="description" content="A browsable index of tags across zacharykai.net.">
    </head>

$sidebar

        <!-- Main Content Area -->
        <div class="main-content-area">
        <!-- Main Content -->
        <main>

            <!-- Page Header -->
            <header>
                <!-- Breadcrumbs -->
                <p class="essentials"><a href="/">Homepage</a> • <a href="/tags">Tags</a> ↴</p>
                <!-- Page Title -->
                <h1>Tags</h1>
            </header>

            <!-- Page Body -->
            <section>

                <p id="top">Browse pages by tag. Select any tag to see what's filed under it.</p>

                <ul>
$tag_list_items                </ul>

                <p>•--♡--•</p>


            </section>

            <section>
                <!-- Closing Metadata -->

                <section>
                    <!-- Tags -->
                    <p class="essentials"><strong>Browse Tags</strong>: $browse_str</p>
                </section>

                <section>
                    <!-- Copy + Share -->
                    <p><strong>Copy + Share</strong>: <a href="/tags">zacharykai.net/tags</a></p>
                </section>

            </section>

        </main>

$footer
HTML;

    if (file_put_contents($index_html, $index_page) !== false) {
        log_msg('Baked static tags index: tags.html');
    } else {
        log_msg('FAIL bake-tags — could not write tags.html');
        return;
    }

    // --- Bake tags/{tag}.html for each tag ---
    $baked_count = 0;
    foreach ($tag_pages as $tag => $pages) {
        $tag_title  = ucfirst($tag);
        $count      = count($pages);
        $entry_word = $count === 1 ? 'entry' : 'entries';

        $page_items = '';
        foreach ($pages as $page) {
            $page_items .= '                    <li><a href="' . $e($page['url']) . '">' . $e($page['title']) . "</a></li>\n";
        }

        $tag_page = <<<HTML
<!DOCTYPE html>
<html lang="en-US">

    <!-- Head -->
    <head>
        <!-- Meta Tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- File Links -->
        <link rel="shortcut icon" href="/assets/icon.ico" type="image/x-icon">
        <link rel="stylesheet" href="/assets/style.css">
        <link rel="alternate" type="application/rss+xml" title="Zachary Kai" href="/assets/rss.xml">
        <link rel="webmention" href="https://webmention.io/zacharykai.net/webmention" />
        <link rel="canonical" href="https://zacharykai.net/tags/{$e($tag)}">
        <!-- Page Info -->
        <title>{$e($tag_title)} — Tags | Zachary Kai</title>
        <meta name="description" content="Pages tagged &ldquo;{$e($tag)}&rdquo; on zacharykai.net.">
    </head>

$sidebar

        <!-- Main Content Area -->
        <div class="main-content-area">
        <!-- Main Content -->
        <main>

            <!-- Page Header -->
            <header>
                <!-- Breadcrumbs -->
                <p class="essentials"><a href="/">Homepage</a> • <a href="/tags">Tags</a> • {$e($tag_title)} ↴</p>
                <!-- Page Title -->
                <h1>{$e($tag_title)}</h1>
            </header>

            <!-- Page Body -->
            <section>

                <p id="top">All pages tagged <strong>{$e($tag)}</strong>. $count $entry_word.</p>

                <ul>
$page_items                </ul>

                <p>•--♡--•</p>


            </section>

            <section>
                <!-- Closing Metadata -->

                <section>
                    <!-- Tags -->
                    <p class="essentials"><strong>Browse Tags</strong>: $browse_str</p>
                </section>

                <section>
                    <!-- Copy + Share -->
                    <p><strong>Copy + Share</strong>: <a href="/tags/{$e($tag)}">zacharykai.net/tags/{$e($tag)}</a></p>
                </section>

            </section>

        </main>

$footer
HTML;

        $out_file = $tags_dir . $tag . '.html';
        if (file_put_contents($out_file, $tag_page) !== false) {
            $baked_count++;
        } else {
            log_msg('FAIL bake-tags — could not write ' . $out_file);
        }
    }

    log_msg("Baked static tag pages: $baked_count tag file(s) in tags/");
}

// ---------------------------------------------------------------------------
// (Server build removed — this script now runs locally only. The lunaseeker
// and roadlessread cron orchestration lives in their own server cron scripts.)
// ---------------------------------------------------------------------------
// Bake a static notes/index.html listing all notes sorted by date (newest first).
// ---------------------------------------------------------------------------

function bake_static_notes_index(string $site_dir): void {
    $notes_dir  = $site_dir . 'notes' . DIRECTORY_SEPARATOR;
    $index_file = $notes_dir . 'index.html';

    $posts = [];

    foreach (glob($notes_dir . '*.html') ?: [] as $file_path) {
        $base = pathinfo($file_path, PATHINFO_FILENAME);
        if ($base === 'index') continue;
        if (is_dir($notes_dir . $base)) continue;

        $html = file_get_contents($file_path);
        if ($html === false) continue;

        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/s', $html, $m)) {
            $title = preg_replace('/ \| Zachary Kai$/i', '', trim($m[1]));
        }

        $date_attr = '';
        if (preg_match('/<time\s+class="dt-published"\s+datetime="([^"]+)">/s', $html, $m)) {
            $date_attr = $m[1];
        }

        if ($title && $date_attr) {
            $posts[] = [
                'title'        => $title,
                'date_attr'    => $date_attr,
                'display_date' => date('j M Y', strtotime($date_attr)),
                'url'          => '/notes/' . $base,
            ];
        }
    }

    usort($posts, fn($a, $b) => strtotime($b['date_attr']) - strtotime($a['date_attr']));

    $e = 'htmlspecialchars';

    $items = '';
    foreach ($posts as $post) {
        $items .= '                    <li><a href="' . $e($post['url']) . '">' . $e($post['title']) . '</a> | ' . $e($post['display_date']) . "</li>\n";
    }

    $count = count($posts);

    $html_out = <<<HTML
<!DOCTYPE html>
<html lang="en-US">

    <!-- Head -->
    <head>
        <!-- Meta Tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- File Links -->
        <link rel="shortcut icon" href="/assets/icon.ico" type="image/x-icon">
        <link rel="stylesheet" href="/assets/style.css">
        <link rel="alternate" type="application/rss+xml" title="Zachary Kai" href="/assets/rss.xml">
        <link rel="alternate" type="application/rss+xml" title="Notes — Zachary Kai" href="/assets/files/feeds/notes.xml">
        <link rel="webmention" href="https://webmention.io/zacharykai.net/webmention" />
        <link rel="canonical" href="https://zacharykai.net/notes">
        <!-- Page Info -->
        <title>Notes | Zachary Kai</title>
        <meta name="description" content="All notes, essays, and reflections on zacharykai.net, newest first.">
    </head>

    <!-- Body -->
    <body>        <!-- Sidebar -->
        <aside class="sidebar">

            <!-- Skip/Return Link -->
            <p><a href="#top" class="smalltext">Skip/Return To Top</a></p>

            <!-- Mobile Menu Toggle -->
            <input type="checkbox" class="menu-toggle" id="menu-toggle">

            <header>
                <div>
                    <p class="sitetitle"><a href="/">Zachary Kai</a></p>
                    <label for="menu-toggle" class="menu-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </label>
                </div>
                <nav>
                    <p><a href="/about"><img src="/assets/imgs/svg/user.svg" class="nav-icon" alt="">About</a></p>
                    <p><a href="/jots"><img src="/assets/imgs/svg/jots.svg" class="nav-icon" alt="">Jots</a></p>
                    <p><a href="/links"><img src="/assets/imgs/svg/links.svg" class="nav-icon" alt="">Links</a></p>
                    <p><a href="/notes"><img src="/assets/imgs/svg/pencil-line.svg" class="nav-icon" alt="">Notes</a></p>
                    <p><a href="/now"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="">Now</a></p>
                    <p><a href="/pages"><img src="/assets/imgs/svg/files.svg" class="nav-icon" alt="">Pages</a></p>
                    <p><a href="/projects"><img src="/assets/imgs/svg/projects.svg" class="nav-icon" alt="">Projects</a></p>
                    <p><a href="/sitemap"><img src="/assets/imgs/svg/tree-structure.svg" class="nav-icon" alt="">Sitemap</a></p>
                    <p class="sidebar-icons"><a href="/contact" aria-label="Contact"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a> <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a> <a href="/subscribe" aria-label="Subscribe"><img src="/assets/imgs/svg/rss.svg" class="nav-icon" alt="RSS"></a></p>
                </nav>
            </header>

            <p class="smalltext">Est. 2019</p>

        </aside>

        <!-- Main Content Area -->
        <div class="main-content-area">
        <!-- Main Content -->
        <main>

            <!-- Page Header -->
            <header>
                <!-- Breadcrumbs -->
                <p class="essentials"><a href="/">Homepage</a> &bull; Notes</p>
                <!-- Page Title -->
                <h1 id="top">Notes</h1>
            </header>

            <!-- Page Body -->
            <section>

                <p>All $count notes, newest first. Subscribe via <a href="/assets/files/feeds/notes.xml">RSS</a>.</p>

                <!-- NOTES-LIST-START -->
                <ul>
$items                </ul>
                <!-- NOTES-LIST-END -->

                <p>&bull;--&hearts;--&bull;</p>

            </section>

            <section>
                <!-- Closing Metadata -->

                <section>
                    <!-- Tags -->
                    <p class="essentials"><strong>Tags</strong>: <a href="/tags/notes">notes</a></p>
                </section>

                <section>
                    <!-- Copy + Share -->
                    <p><strong>Copy + Share</strong>: <a href="/notes">zacharykai.net/notes</a></p>
                </section>

            </section>

        </main>

        <!-- Back To Top Link -->
        <p><a href="#top" class="essentials">Read again...</a></p>

        <!-- Divider -->
        <div class="end"></div>

        <!-- Footer -->
        <footer>

            <!-- Support Me -->
            <section class="support">
                <h2>Enjoy What I Do? Find It (Hopefully) Helpful?</h2>
                <p>I'm glad! If you feel moved to <a href="/support">support me</a>, it's most appreciated.</p>
            </section>

            <hr>

            <!-- H-Card -->
            <section class="h-card vcard">

                <!-- Avatar -->
                <section id="h-card-image">
                    <picture>
                        <source srcset="/assets/icon.webp" type="image/webp">
                        <img class="u-photo" loading="lazy" src="/assets/icon.png" alt="Zach's digital drawing: blue/green/purple book stack, decorated with green leaves and purple hearts.">
                    </picture>
                </section>

                <!-- Byline -->
                <section id="h-card-content">
                    <p>
                        <!-- Name + URL -->
                        <strong><a class="u-url u-id p-name" href="https://zacharykai.net" rel="me"><span class="fn">Zachary Kai</span></a></strong> 
                        — 
                        <!-- Pronouns-->
                        <span class="p-pronouns">he/him</span> 
                        — 
                        <!-- Email -->
                        <a class="u-email email" href="mailto:hi@zacharykai.net" rel="me">hi@zacharykai.net</a>
                        — 
                        <!-- Biography -->
                        <span class="p-note">A space fantasy writer, offbeat queer, traveler, zinester, and avowed generalist. The internet is his livelihood and lifeline.</span>
                    </p>
                </section>

            </section>

            <hr>

            <!-- Acknowledgement Of Country -->
            <p><strong>Acknowledgement</strong>: I owe my existence to the <a href="https://kht.org.au/" rel="noopener">Koori people's</a> lands: tended for millennia by the traditional storytellers. What a legacy. May it prevail.</p>

            <hr>

            <!-- Reply Via & Elsewhere -->
            <p class="footer-icons">
                <strong>Reply Via</strong>:
                <a href="/contact" aria-label="Email"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a>
                <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a>
                <a href="/unoffice-hours" aria-label="UnOffice Hours"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="UnOffice Hours"></a>
                <a href="/webmentions" rel="noopener" aria-label="Webmention"><img src="/assets/imgs/svg/at.svg" class="nav-icon" alt="Webmention"></a>
                <strong style="margin-left: 0.75em;">Elsewhere</strong>:
                <a href="https://zacharykai.net/newsletter/" rel="noopener" aria-label="Newsletter"><img src="/assets/imgs/svg/newspaper.svg" class="nav-icon" alt="Newsletter"></a>
                <a href="https://ko-fi.com/zacharykai" rel="noopener" aria-label="Ko-fi"><img src="/assets/imgs/svg/coffee.svg" class="nav-icon" alt="Ko-fi"></a>
                <a href="https://lunaseeker.metalabel.com/" rel="noopener" aria-label="Metalabel"><img src="/assets/imgs/svg/tag.svg" class="nav-icon" alt="Metalabel"></a>
            </p>

            <hr>

            <!-- Footer Menu -->
            <p style="margin-bottom: 0;">
                <a href="/about">About</a> |
                <a href="/art">Art</a> |
                <a href="/accessibility">Accessibility</a> |
                <a href="/colophon">Colophon</a> |
                <a href="/changelog">Changelog</a> |
                <a href="/press">Press</a> | 
                <a href="/random">Random</a> |
                <a href="/uses">Uses</a>
            </p>

            <hr>

            <!-- Final Message -->
            <p class="essentials" style="margin-top: 2em;">Hi. Yes, you, lovely human. Have a wonderful <a href="https://indieweb.org/Universal_Greeting_Time" rel="noopener">morning</a> and make time for a small moment of joy, wherever you are. ♡</p>

        </footer>

        </div><!-- /.main-content-area -->


    </body>
</html>
HTML;

    if (file_put_contents($index_file, $html_out) !== false) {
        log_msg("Baked static notes index: notes/index.html ($count notes listed)");
    } else {
        log_msg('FAIL bake-notes-index — could not write notes/index.html');
    }
}

// ---------------------------------------------------------------------------
// Bake a static gratitudes/index.html — a reverse-chronological masonry grid
// of every gratitude page, grouped by year. Mirrors the /jots microblog look
// (jots.css) and auto-updates whenever new gratitudes/*.html pages are added.
// ---------------------------------------------------------------------------

function bake_static_gratitudes_index(string $site_dir): void {
    $grat_dir   = $site_dir . 'gratitudes' . DIRECTORY_SEPARATOR;
    $index_file = $grat_dir . 'index.html';

    if (!is_dir($grat_dir)) {
        log_msg('SKIP bake-gratitudes-index — gratitudes/ not found');
        return;
    }

    $posts = [];
    foreach (glob($grat_dir . '*.html') ?: [] as $file_path) {
        $base = pathinfo($file_path, PATHINFO_FILENAME);
        if ($base === 'index') continue;

        $html = file_get_contents($file_path);
        if ($html === false) continue;

        $date_attr = '';
        if (preg_match('/<time\s+class="dt-published"\s+datetime="([^"]+)">/s', $html, $m)) {
            $date_attr = $m[1];
        }
        // Grab the gratitude list verbatim to embed in the card.
        $list = '';
        if (preg_match('/<ul class="gratitudes">(.*?)<\/ul>/s', $html, $m)) {
            $list = trim($m[1]);
        }
        if ($date_attr === '' || $list === '') continue;

        $posts[] = [
            'slug'         => $base,
            'date_attr'    => $date_attr,
            'display_date' => date('j M Y', strtotime($date_attr)),
            'year'         => date('Y', strtotime($date_attr)),
            'list'         => $list,
        ];
    }

    usort($posts, fn($a, $b) => strtotime($b['date_attr']) - strtotime($a['date_attr']));
    $count = count($posts);
    $e = 'htmlspecialchars';

    // Build masonry cards, grouped by year (year heading spans all columns).
    $cards = '';
    $currentYear = '';
    foreach ($posts as $post) {
        if ($post['year'] !== $currentYear) {
            $currentYear = $post['year'];
            $cards .= "\n                <!-- {$currentYear} -->\n";
            $cards .= "                <h2 id=\"{$currentYear}\">{$currentYear}</h2>\n\n";
        }
        $cards .= '                <article class="h-entry" id="' . $e($post['slug']) . "\">\n";
        $cards .= '                    <p class="jot-date"><time class="dt-published" datetime="' . $e($post['date_attr']) . '">' . $e($post['display_date']) . "</time></p>\n";
        $cards .= "                    <section class=\"e-content p-name\">\n";
        $cards .= "                        <ul class=\"gratitudes\">" . $post['list'] . "</ul>\n";
        $cards .= "                    </section>\n";
        $cards .= '                    <a href="/gratitudes/' . $e($post['slug']) . '" class="u-url" title="Permalink">&rarr;</a>' . "\n";
        $cards .= "                </article>\n";
    }

    $today      = date('Y-m-d');
    $today_disp = date('j M Y');

    $html_out = <<<HTML
<!DOCTYPE html>
<html lang="en-US">

    <!-- Head -->
    <head>
        <!-- Meta Tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- File Links -->
        <link rel="shortcut icon" href="/assets/icon.ico" type="image/x-icon">
        <link rel="stylesheet" href="/assets/styles/jots.css">
        <link rel="alternate" type="application/rss+xml" title="Zachary Kai" href="/assets/rss.xml">
        <link rel="webmention" href="https://webmention.io/zacharykai.net/webmention" />
        <link rel="canonical" href="https://zacharykai.net/gratitudes">
        <!-- Page Info -->
        <title>Gratitudes | Zachary Kai</title>
        <meta name="date" content="2026-06-21">
        <meta name="last-modified" content="$today">
        <meta name="description" content="A running collection of my daily gratitude lists: small things, noticed and noted, newest first.">
    </head>

    <!-- Body -->
    <body>

        <!-- Skip Link -->
        <p><a href="#top" class="essentials">Begin reading...</a></p>

        <!-- Site Header -->
        <header><nav><a href="/">Zachary Kai</a></nav></header>

        <!-- Main Content -->
        <main class="h-feed">
            <a class="u-url" href="https://zacharykai.net/gratitudes"></a>
            <section class="p-author h-card" style="display: none;">
                <a class="u-url" href="https://zacharykai.net" rel="me">Zachary Kai</a>
            </section>

            <!-- Page Header -->
            <header>
                <!-- Breadcrumbs -->
                <p class="essentials"><a href="/">Homepage</a> • <a href="/sitemap#pages">Pages</a> ↴</p>
                <!-- Page Title -->
                <h1 class="p-name">Gratitudes</h1>
                <!-- Necessary Metadata -->
                <p class="essentials">
                    <strong>Written By</strong>: <a href="/about">Zachary Kai</a> »
                    <strong>Published</strong>: <time class="dt-published" datetime="2026-06-21">21 Jun 2026</time> |
                    <strong>Updated</strong>: <time class="dt-modified" datetime="$today">$today_disp</time>
                </p>
                <!-- Introduction -->
                <p id="top" class="p-summary">A running collection of my daily gratitude lists: $count days of small things, noticed and noted. (Ordered reverse chronologically.)</p>
            </header>

            <!-- Page Body -->
            <section class="e-content">

                <section class="jots-grid">
$cards                </section>

            </section>

            <section>
                <!-- Closing Metadata -->

                <section>
                    <!-- Tags -->
                    <p class="essentials"><strong>Tags</strong>: <a href="/tags/gratitude">gratitude</a> · <a href="/tags/personal">personal</a></p>
                </section>

                <section>
                    <!-- Copy + Share -->
                    <p><strong>Copy + Share</strong>: <a href="/gratitudes">zacharykai.net/gratitudes</a></p>
                </section>

            </section>

        </main>

        <!-- Back To Top Link -->
        <p><a href="#top" class="essentials">Read again...</a></p>

        <!-- Divider -->
        <div class="end"></div>

        <!-- H-Card -->
        <section class="h-card vcard">
            <section id="h-card-image">
                <picture>
                    <source srcset="/assets/icon.webp" type="image/webp">
                    <img class="u-photo" loading="lazy" src="/assets/icon.png" alt="Zachary Kai's digital drawing: 5 stacked books (blue/teal/green/purple, black spine designs), green plant behind top book, purple heart on either side.">
                </picture>
            </section>
            <section id="h-card-content">
                <p><strong><a class="u-url u-id p-name" href="https://zacharykai.net" rel="me"><span class="fn">Zachary Kai</span></a></strong> — <span class="p-pronouns">he/him</span> | <a class="u-email email" href="mailto:hi@zacharykai.net" rel="me">hi@zacharykai.net</a></p>
                <p class="p-note">Zachary Kai is a space fantasy writer, offbeat queer, traveler, zinester, and avowed generalist. The internet is his livelihood and lifeline.</p>
            </section>
        </section>

        <!-- Footer -->
        <footer>

            <!-- Support Me -->
            <section class="support">
                <h2>Enjoy What I Do? Find It (Hopefully) Helpful?</h2>
                <p>I'm glad! If you feel moved to <a href="/support">support me</a>, it's most appreciated.</p>
            </section>

            <hr>

            <!-- H-Card -->
            <section class="h-card vcard">

                <!-- Avatar -->
                <section id="h-card-image">
                    <picture>
                        <source srcset="/assets/icon.webp" type="image/webp">
                        <img class="u-photo" loading="lazy" src="/assets/icon.png" alt="Zach's digital drawing: blue/green/purple book stack, decorated with green leaves and purple hearts.">
                    </picture>
                </section>

                <!-- Byline -->
                <section id="h-card-content">
                    <p>
                        <!-- Name + URL -->
                        <strong><a class="u-url u-id p-name" href="https://zacharykai.net" rel="me"><span class="fn">Zachary Kai</span></a></strong> 
                        — 
                        <!-- Pronouns-->
                        <span class="p-pronouns">he/him</span> 
                        — 
                        <!-- Email -->
                        <a class="u-email email" href="mailto:hi@zacharykai.net" rel="me">hi@zacharykai.net</a>
                        — 
                        <!-- Biography -->
                        <span class="p-note">A space fantasy writer, offbeat queer, traveler, zinester, and avowed generalist. The internet is his livelihood and lifeline.</span>
                    </p>
                </section>

            </section>

            <hr>

            <!-- Acknowledgement Of Country -->
            <p><strong>Acknowledgement</strong>: I owe my existence to the <a href="https://kht.org.au/" rel="noopener">Koori people's</a> lands: tended for millennia by the traditional storytellers. What a legacy. May it prevail.</p>

            <hr>

            <!-- Reply Via & Elsewhere -->
            <p class="footer-icons">
                <strong>Reply Via</strong>:
                <a href="/contact" aria-label="Email"><img src="/assets/imgs/svg/envelope.svg" class="nav-icon" alt="Email"></a>
                <a href="/guestbook" aria-label="Guestbook"><img src="/assets/imgs/svg/book-open.svg" class="nav-icon" alt="Guestbook"></a>
                <a href="/unoffice-hours" aria-label="UnOffice Hours"><img src="/assets/imgs/svg/clock.svg" class="nav-icon" alt="UnOffice Hours"></a>
                <a href="/webmentions" rel="noopener" aria-label="Webmention"><img src="/assets/imgs/svg/at.svg" class="nav-icon" alt="Webmention"></a>
                <strong style="margin-left: 0.75em;">Elsewhere</strong>:
                <a href="https://zacharykai.net/newsletter/" rel="noopener" aria-label="Newsletter"><img src="/assets/imgs/svg/newspaper.svg" class="nav-icon" alt="Newsletter"></a>
                <a href="https://ko-fi.com/zacharykai" rel="noopener" aria-label="Ko-fi"><img src="/assets/imgs/svg/coffee.svg" class="nav-icon" alt="Ko-fi"></a>
                <a href="https://lunaseeker.metalabel.com/" rel="noopener" aria-label="Metalabel"><img src="/assets/imgs/svg/tag.svg" class="nav-icon" alt="Metalabel"></a>
            </p>

            <hr>

            <!-- Footer Menu -->
            <p style="margin-bottom: 0;">
                <a href="/about">About</a> |
                <a href="/art">Art</a> |
                <a href="/accessibility">Accessibility</a> |
                <a href="/colophon">Colophon</a> |
                <a href="/changelog">Changelog</a> |
                <a href="/press">Press</a> | 
                <a href="/random">Random</a> |
                <a href="/uses">Uses</a>
            </p>

            <hr>

            <!-- Final Message -->
            <p class="essentials" style="margin-top: 2em;">Hi. Yes, you, lovely human. Have a wonderful <a href="https://indieweb.org/Universal_Greeting_Time" rel="noopener">morning</a> and make time for a small moment of joy, wherever you are. ♡</p>

        </footer>

    </body>
</html>
HTML;

    if (file_put_contents($index_file, $html_out) !== false) {
        log_msg("Baked static gratitudes index: gratitudes/index.html ($count entries listed)");
    } else {
        log_msg('FAIL bake-gratitudes-index — could not write gratitudes/index.html');
    }
}

// ---------------------------------------------------------------------------
// Local build
// ---------------------------------------------------------------------------

// This script lives at <site>/assets/files/scripts/sitebuild.php, so the site
// root is three directories up.
$script_dir = __DIR__;
$personal   = dirname($script_dir, 3); // …/site

if (!is_dir($personal . DIRECTORY_SEPARATOR . 'notes')) {
    // Fallback: maybe running from the site root directly
    $personal = getcwd();
}

if (!is_dir($personal)) {
    log_msg('WARNING: Could not locate The Personal folder. Tried: ' . $personal);
    exit(1);
}

// Normalise with trailing separator
$site_dir = rtrim($personal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$site_url = 'https://zacharykai.net/';

log_msg('The Personal folder: ' . $personal);

// Local cache next to this script
$cache_file = $script_dir . DIRECTORY_SEPARATOR . 'currently-reading.json';

log_msg('--- zacharykai.net (local) ---');

if ($offline) {
    log_msg('SKIP currentread + update-location (--offline)');
} else {
    log_msg('RUN  currentread (Hardcover cache + now.html + index.php)');
    update_currently_reading($site_dir, $cache_file);

    log_msg('RUN  update-location');
    sync_location($site_dir);
}

log_msg('RUN  zkrss (RSS feeds)');
generate_rss_feeds($site_dir, $site_url);

log_msg('RUN  bake-sitemap (static sitemap.html)');
bake_static_sitemap($site_dir, rtrim($site_url, '/'));

log_msg('RUN  bake-tags (static tags.html + tags/*.html)');
bake_static_tags($site_dir, rtrim($site_url, '/'));

log_msg('RUN  bake-notes-index (static notes/index.html)');
bake_static_notes_index($site_dir);

log_msg('RUN  bake-gratitudes-index (static gratitudes/index.html)');
bake_static_gratitudes_index($site_dir);

log_msg('RUN  bake-homepage (static index.html)');
bake_static_homepage($site_dir);

log_msg('--- lunaseeker.com / roadlessread.com (local) ---');
log_msg('SKIP lsrss      — lunaseeker.com has no local copy');
log_msg('SKIP lssitebook — lunaseeker.com has no local copy');
log_msg('SKIP rlrrss     — roadlessread.com has no local copy');
log_msg('SKIP build-site — roadlessread.com has no local copy');

log_msg('sitebuild done (local)');
log_msg('Tip: push The Personal changes to the server, then run this script there for full regeneration.');
exit(0);

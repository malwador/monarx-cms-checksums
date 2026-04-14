#!/usr/bin/env php
<?php declare(strict_types=1);
ini_set('memory_limit', '512M');   // PharData / ZipArchive need headroom for large archives
/**
 * MonarX Vision — CMS Checksum Generator
 *
 * Generates SHA-256 checksum manifests for Drupal, Joomla, PrestaShop,
 * OpenCart, and Magento. One JSON file per version is written to
 * checksums/{cms}/{version}.json, and a master index is kept at
 * checksums/manifest.json.
 *
 * Usage:
 *   php generate.php --cms=drupal --version=10.2.2
 *   php generate.php --cms=joomla --latest=5
 *   php generate.php --cms=all --latest=3
 *   php generate.php --cms=opencart --list
 *   php generate.php --cms=all --latest=3 --force
 *
 * Options:
 *   --cms        drupal|joomla|prestashop|opencart|magento|all   [default: all]
 *   --version    Generate a single specific version
 *   --latest=N   Generate the N most recent stable releases       [default: 3]
 *   --list       Print available versions and exit
 *   --force      Re-generate even when output file already exists
 *   --tmpdir     Temp directory for downloads/extraction          [default: sys_get_temp_dir()]
 *   --token      GitHub personal access token (avoids rate limits)
 *
 * Requirements: PHP 7.4+, ext-zip, ext-phar, ext-hash, ext-curl or allow_url_fopen
 */
// ══════════════════════════════════════════════════════════════════════════════
//  CONSTANTS & CONFIG
// ══════════════════════════════════════════════════════════════════════════════

define('OUTPUT_DIR', __DIR__ . '/checksums');
define('MANIFEST',   OUTPUT_DIR . '/manifest.json');

/**
 * Per-CMS definitions:
 *   archive_type   tar.gz | zip
 *   download_url   URL with {version} and optionally {version_dir} placeholders
 *   strip_prefix   Path prefix to remove from archive entries (e.g. 'drupal/')
 *   version_source drupal_api | github:{owner}/{repo}
 *   stable_filter  Regex that a version string must match to be considered stable
 *   exclude        Glob-style path patterns relative to the CMS root to skip
 *   notes          Free-text notes about quirks
 */
const CMS_DEFS = [

    'drupal' => [
        'label'          => 'Drupal',
        'archive_type'   => 'tar.gz',
        'download_url'   => 'https://ftp.drupal.org/files/projects/drupal-{version}.tar.gz',
        'strip_prefix'   => 'drupal/',
        'version_source' => 'drupal_api',
        'stable_filter'  => '/^\d+\.\d+\.\d+$/',
        'exclude'        => [
            'sites/*/settings.php',
            'sites/*/settings.local.php',
            'sites/*/services.yml',
            'sites/*/files/**',
            'sites/*/private/**',
            'sites/*/translations/**',
            '.git/**',
            '.gitignore',
            '.gitattributes',
        ],
    ],

    'joomla' => [
        'label'          => 'Joomla',
        'archive_type'   => 'zip',
        // Tag on GitHub may or may not use a 'v' prefix; handled in download_url()
        'download_url'   => 'https://github.com/joomla/joomla-cms/releases/download/{version}/Joomla_{version}-Stable-Full_Package.zip',
        'strip_prefix'   => '',
        'version_source' => 'github:joomla/joomla-cms',
        'stable_filter'  => '/^\d+\.\d+\.\d+$/',
        'exclude'        => [
            'configuration.php',
            'cache/**',
            'tmp/**',
            'logs/**',
            'images/**',
            '.git/**',
            '.gitignore',
            '.gitattributes',
        ],
    ],

    'prestashop' => [
        'label'          => 'PrestaShop',
        'archive_type'   => 'zip',
        // The outer zip contains an inner prestashop.zip — handled specially
        'download_url'   => 'https://github.com/PrestaShop/PrestaShop/releases/download/{version}/prestashop_{version}.zip',
        'strip_prefix'   => '',
        'version_source' => 'github:PrestaShop/PrestaShop',
        'stable_filter'  => '/^\d+\.\d+\.\d+$/',
        'nested_zip'     => 'prestashop.zip',   // extract this inner zip after download
        'exclude'        => [
            'app/config/parameters.php',
            'app/config/parameters_test.php',
            'var/**',
            'img/**',
            'upload/**',
            'download/**',
            'config/settings.inc.php',
            '.git/**',
            '.gitignore',
            '.gitattributes',
        ],
    ],

    'opencart' => [
        'label'          => 'OpenCart',
        'archive_type'   => 'zip',
        'download_url'   => 'https://github.com/opencart/opencart/releases/download/{version}/opencart-{version}.zip',
        'strip_prefix'   => 'upload/',          // OpenCart zips files inside an upload/ folder
        'version_source' => 'github:opencart/opencart',
        'stable_filter'  => '/^\d+\.\d+\.\d+(\.\d+)?$/',
        'exclude'        => [
            'config.php',
            'admin/config.php',
            'system/storage/**',
            'image/**',
            '.git/**',
            '.gitignore',
            '.gitattributes',
        ],
    ],

    'magento' => [
        'label'          => 'Magento 2',
        'archive_type'   => 'tar.gz',
        // GitHub source archive — version_dir handles the 'magento2-{version}' prefix
        'download_url'   => 'https://github.com/magento/magento2/archive/refs/tags/{version}.tar.gz',
        'strip_prefix'   => 'magento2-{version}/',
        'version_source' => 'github:magento/magento2',
        'stable_filter'  => '/^\d+\.\d+\.\d+(-p\d+)?$/',
        'exclude'        => [
            'app/etc/env.php',
            'app/etc/config.php',
            'var/**',
            'generated/**',
            'pub/media/**',
            'pub/static/**',
            'dev/**',
            '.git/**',
            '.gitignore',
            '.gitattributes',
            'node_modules/**',
        ],
    ],
];

// ══════════════════════════════════════════════════════════════════════════════
//  ENTRY POINT
// ══════════════════════════════════════════════════════════════════════════════

main($argc ?? 1, $argv ?? ['generate.php']);

function main(int $argc, array $argv): void
{
    $opts = parse_args(array_slice($argv, 1));

    $selected_cms = $opts['cms'] ?? 'all';
    $version_arg  = $opts['version'] ?? null;
    $latest_n     = (int)($opts['latest'] ?? 3);
    $list_only    = isset($opts['list']);
    $force        = isset($opts['force']);
    $tmpdir       = rtrim($opts['tmpdir'] ?? sys_get_temp_dir(), '/');
    $gh_token     = $opts['token'] ?? getenv('GITHUB_TOKEN') ?: '';

    check_requirements();

    $cms_keys = ($selected_cms === 'all') ? array_keys(CMS_DEFS) : [$selected_cms];
    foreach ($cms_keys as $key) {
        if (!isset(CMS_DEFS[$key])) {
            err("Unknown CMS: $key. Available: " . implode(', ', array_keys(CMS_DEFS)));
            continue;
        }
    }

    foreach ($cms_keys as $key) {
        $def = CMS_DEFS[$key];
        log_info("\n── {$def['label']} ──────────────────────────────────────────");

        // Determine which versions to process
        if ($version_arg) {
            $versions = [$version_arg];
        } else {
            $versions = fetch_versions($key, $def, $latest_n, $gh_token);
            if (empty($versions)) {
                log_warn("  No versions found for {$def['label']}");
                continue;
            }
        }

        if ($list_only) {
            log_info("  Available versions:");
            foreach ($versions as $v) {
                $exists = file_exists(OUTPUT_DIR . "/$key/$v.json") ? ' [cached]' : '';
                log_info("    $v$exists");
            }
            continue;
        }

        foreach ($versions as $ver) {
            $out_file = OUTPUT_DIR . "/$key/$ver.json";
            if (file_exists($out_file) && !$force) {
                log_info("  ✓ $ver — already exists (use --force to regenerate)");
                continue;
            }
            process_version($key, $def, $ver, $tmpdir, $gh_token);
        }
    }

    if (!$list_only) {
        rebuild_manifest();
        log_info("\n✅ Done. Commit checksums/ and push to your public GitHub repo.");
    }
}

// ══════════════════════════════════════════════════════════════════════════════
//  VERSION FETCHING
// ══════════════════════════════════════════════════════════════════════════════

function fetch_versions(string $cms, array $def, int $n, string $token): array
{
    $source = $def['version_source'];
    log_info("  Fetching version list from {$source}...");

    if ($source === 'drupal_api') {
        return fetch_versions_drupal($n, $def['stable_filter']);
    }
    if (str_starts_with($source, 'github:')) {
        $repo = substr($source, 7);
        return fetch_versions_github($repo, $n, $def['stable_filter'], $token);
    }

    log_warn("  Unknown version source: $source");
    return [];
}

function fetch_versions_drupal(int $n, string $stable_filter): array
{
    // Drupal publishes a release history XML feed
    $xml_body = http_get('https://updates.drupal.org/release-history/drupal/all');
    if (!$xml_body) {
        log_warn("  Could not fetch Drupal release history XML");
        return [];
    }
    // Suppress XML errors; parse tag <version>...</version>
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xml_body);
    if (!$xml) {
        log_warn("  Could not parse Drupal release history XML");
        return [];
    }

    $versions = [];
    foreach ($xml->releases->release ?? [] as $rel) {
        $v   = (string)($rel->version ?? '');
        $sec = (string)($rel->security ?? '');
        $sta = (string)($rel->status   ?? '');
        // Accept published, non-security-only releases that match the stable pattern
        if ($sta === 'published' && preg_match($stable_filter, $v)) {
            $versions[] = $v;
        }
        if (count($versions) >= $n) break;
    }
    return $versions;
}

function fetch_versions_github(string $repo, int $n, string $stable_filter, string $token): array
{
    $url     = "https://api.github.com/repos/$repo/releases?per_page=50";
    $headers = ['User-Agent: MonarX-Checksum-Generator'];
    if ($token) $headers[] = "Authorization: Bearer $token";

    $body = http_get($url, $headers);
    if (!$body) {
        log_warn("  Could not fetch GitHub releases for $repo");
        return [];
    }
    $releases = json_decode($body, true);
    if (!is_array($releases)) {
        log_warn("  Could not parse GitHub releases JSON for $repo");
        return [];
    }

    $versions = [];
    foreach ($releases as $r) {
        if (!empty($r['prerelease']) || !empty($r['draft'])) continue;
        $tag = ltrim($r['tag_name'] ?? '', 'v');  // strip leading 'v'
        if (preg_match($stable_filter, $tag)) {
            $versions[] = $tag;
        }
        if (count($versions) >= $n) break;
    }
    return $versions;
}

// ══════════════════════════════════════════════════════════════════════════════
//  PROCESS ONE VERSION
// ══════════════════════════════════════════════════════════════════════════════

function process_version(string $cms, array $def, string $ver, string $tmpdir, string $token): void
{
    $label = $def['label'];
    log_info("  Processing {$label} {$ver}...");

    // Resolve download URL (replace {version} and {strip_prefix} placeholders)
    $url   = resolve_url($def['download_url'], $ver);
    $strip = str_replace('{version}', $ver, $def['strip_prefix']);

    // Create temp workspace
    $workspace = $tmpdir . '/mv_ck_' . $cms . '_' . preg_replace('/[^a-z0-9\.\-]/', '_', $ver);
    @mkdir($workspace, 0755, true);

    $archive = $workspace . '/archive.' . ($def['archive_type'] === 'tar.gz' ? 'tar.gz' : 'zip');

    // Download
    log_info("    ↓ Downloading $url");
    if (!download_file($url, $archive)) {
        log_warn("    ✗ Download failed: $url");
        rmdir_recursive($workspace);
        return;
    }
    $size_mb = round(filesize($archive) / 1048576, 1);
    log_info("    ✓ Downloaded {$size_mb} MB");

    // Extract
    $extract_dir = $workspace . '/extracted';
    @mkdir($extract_dir, 0755, true);

    $ok = false;
    if ($def['archive_type'] === 'tar.gz') {
        $ok = extract_targz($archive, $extract_dir);
    } else {
        // Handle PrestaShop's double-zip: outer zip → inner prestashop.zip → actual files
        if (!empty($def['nested_zip'])) {
            $outer_dir = $workspace . '/outer';
            @mkdir($outer_dir, 0755, true);
            if (extract_zip($archive, $outer_dir)) {
                $inner = $outer_dir . '/' . $def['nested_zip'];
                if (file_exists($inner)) {
                    $ok = extract_zip($inner, $extract_dir);
                } else {
                    log_warn("    ✗ Expected nested zip '{$def['nested_zip']}' not found inside outer archive");
                }
                rmdir_recursive($outer_dir);
            }
        } else {
            $ok = extract_zip($archive, $extract_dir);
        }
    }

    if (!$ok) {
        log_warn("    ✗ Extraction failed");
        rmdir_recursive($workspace);
        return;
    }

    // Determine the root of the CMS files inside the extracted directory
    $cms_root = find_cms_root($extract_dir, $strip);

    // Generate checksums
    log_info("    ⟳ Computing SHA-256 checksums...");
    $checksums = generate_checksums($cms_root, $def['exclude']);
    $file_count = count($checksums);
    log_info("    ✓ Checksummed $file_count files");

    // Write output
    $out_file = OUTPUT_DIR . "/$cms/$ver.json";
    $payload  = json_encode([
        'cms'       => $cms,
        'version'   => $ver,
        'generated' => gmdate('Y-m-d\TH:i:s\Z'),
        'files'     => $file_count,
        'checksums' => $checksums,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($out_file, $payload) !== false) {
        log_info("    ✓ Saved → checksums/$cms/$ver.json");
    } else {
        log_warn("    ✗ Could not write $out_file");
    }

    // Cleanup workspace
    rmdir_recursive($workspace);
}

// ══════════════════════════════════════════════════════════════════════════════
//  DOWNLOAD & EXTRACTION
// ══════════════════════════════════════════════════════════════════════════════

function download_file(string $url, string $dest): bool
{
    if (function_exists('curl_init')) {
        $fh = fopen($dest, 'wb');
        if (!$fh) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MonarX-Checksum-Generator/1.0',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        fclose($fh);
        if ($code !== 200) {
            @unlink($dest);
            if ($err) log_warn("    cURL error: $err");
            log_warn("    HTTP $code for $url");
            return false;
        }
        return true;
    }
    if (ini_get('allow_url_fopen')) {
        $data = @file_get_contents($url, false,
            stream_context_create(['http' => ['timeout' => 300, 'user_agent' => 'MonarX-Checksum-Generator/1.0']]));
        if ($data === false) return false;
        return file_put_contents($dest, $data) !== false;
    }
    log_warn("    No HTTP client available (need curl or allow_url_fopen)");
    return false;
}

function extract_targz(string $archive, string $dest_dir): bool
{
    if (!class_exists('PharData')) {
        log_warn("    PharData (ext-phar) is required to extract .tar.gz");
        return false;
    }
    try {
        $phar = new PharData($archive);
        $phar->extractTo($dest_dir, null, true);
        return true;
    } catch (Exception $e) {
        log_warn("    tar.gz extraction failed: " . $e->getMessage());
        return false;
    }
}

function extract_zip(string $archive, string $dest_dir): bool
{
    if (!class_exists('ZipArchive')) {
        log_warn("    ZipArchive (ext-zip) is required to extract .zip");
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($archive) !== true) {
        log_warn("    Could not open zip: $archive");
        return false;
    }
    $zip->extractTo($dest_dir);
    $zip->close();
    return true;
}

// After extraction, the files may sit inside a versioned subdirectory.
// strip_prefix tells us what prefix to navigate into (e.g. 'drupal/' or 'magento2-2.4.7/').
function find_cms_root(string $extract_dir, string $strip_prefix): string
{
    if ($strip_prefix === '') return $extract_dir;

    // If the prefix ends with '/', it's a directory name (possibly with {version} already resolved)
    $sub = rtrim($strip_prefix, '/');
    $candidate = $extract_dir . '/' . $sub;
    if (is_dir($candidate)) return $candidate;

    // Maybe there's exactly one subdirectory (common for GitHub archives)
    $entries = array_diff((array)scandir($extract_dir), ['.', '..']);
    if (count($entries) === 1) {
        $only = $extract_dir . '/' . reset($entries);
        if (is_dir($only)) return $only;
    }

    // Fall back to the extraction directory itself
    return $extract_dir;
}

// ══════════════════════════════════════════════════════════════════════════════
//  CHECKSUM GENERATION
// ══════════════════════════════════════════════════════════════════════════════

function generate_checksums(string $root, array $exclude_patterns): array
{
    $root    = rtrim($root, '/');
    $results = [];
    $iter    = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $abs = $file->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');

        if (path_is_excluded($rel, $exclude_patterns)) continue;

        $hash = hash_file('sha256', $abs);
        if ($hash !== false) {
            $results[$rel] = $hash;
        }
    }
    ksort($results);   // deterministic order
    return $results;
}

function path_is_excluded(string $rel, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (match_glob($pattern, $rel)) return true;
    }
    return false;
}

/**
 * Simple glob matcher that supports:
 *   *   — any segment (no slash)
 *   **  — any number of segments (including slashes)
 */
function match_glob(string $pattern, string $path): bool
{
    // Escape regex special chars except * and /
    $regex = preg_quote($pattern, '#');
    // Replace \*\* with a placeholder, then \* with a single segment matcher
    $regex = str_replace('\*\*', '__DOUBLE_STAR__', $regex);
    $regex = str_replace('\*', '[^/]*', $regex);
    $regex = str_replace('__DOUBLE_STAR__', '.*', $regex);
    return (bool)preg_match('#^' . $regex . '$#', $path);
}

// ══════════════════════════════════════════════════════════════════════════════
//  MANIFEST
// ══════════════════════════════════════════════════════════════════════════════

function rebuild_manifest(): void
{
    $manifest = ['updated' => gmdate('Y-m-d\TH:i:s\Z'), 'cms' => []];

    foreach (array_keys(CMS_DEFS) as $cms) {
        $dir = OUTPUT_DIR . "/$cms";
        if (!is_dir($dir)) continue;
        $files = glob($dir . '/*.json') ?: [];
        $versions = [];
        foreach ($files as $f) {
            $versions[] = basename($f, '.json');
        }
        // Sort versions descending (newest first) using version_compare
        usort($versions, function ($a, $b) {
            return version_compare($b, $a);
        });
        if (!empty($versions)) {
            $manifest['cms'][$cms] = [
                'label'    => CMS_DEFS[$cms]['label'],
                'versions' => $versions,
                'latest'   => $versions[0],
            ];
        }
    }

    file_put_contents(MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    log_info("\n✓ Manifest updated → checksums/manifest.json");
}

// ══════════════════════════════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function resolve_url(string $url, string $version): string
{
    return str_replace('{version}', $version, $url);
}

function http_get(string $url, array $extra_headers = []): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MonarX-Checksum-Generator/1.0',
            CURLOPT_HTTPHEADER     => $extra_headers,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($code === 200 && $body !== false) ? $body : null;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx_opts = ['http' => ['timeout' => 30, 'user_agent' => 'MonarX-Checksum-Generator/1.0']];
        if ($extra_headers) {
            $ctx_opts['http']['header'] = implode("\r\n", $extra_headers);
        }
        $result = @file_get_contents($url, false, stream_context_create($ctx_opts));
        return $result !== false ? $result : null;
    }
    return null;
}

function rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function parse_args(array $args): array
{
    $opts = [];
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $opts[$k] = $v;
            } else {
                $opts[$arg] = true;
            }
        }
    }
    return $opts;
}

function check_requirements(): void
{
    $missing = [];
    if (!class_exists('ZipArchive'))   $missing[] = 'ext-zip (ZipArchive)';
    if (!class_exists('PharData'))     $missing[] = 'ext-phar (PharData)';
    if (!function_exists('hash_file')) $missing[] = 'ext-hash';
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
        $missing[] = 'curl or allow_url_fopen';
    }
    if (!empty($missing)) {
        log_warn("Missing requirements: " . implode(', ', $missing));
        log_warn("Some operations may fail.");
    }
}

function log_info(string $msg): void  { echo $msg . "\n"; }
function log_warn(string $msg): void  { fwrite(STDERR, "WARN: $msg\n"); }
function err(string $msg): void       { fwrite(STDERR, "ERROR: $msg\n"); }

// PHP 7.x compat: str_starts_with / str_contains were added in PHP 8.0
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}

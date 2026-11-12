<?php
/**
 * check-freshness.php — every Directory listing must advertise what its repo actually ships.
 *
 * For each data/<listing>.json (not index.json):
 *   - `version` equals the repo's latest GitHub release tag (a leading "v" stripped), and `ref` equals that tag;
 *   - every repo-relative or absolute image (logo, hero, screenshots) resolves — HTTP 200 — using the same rule
 *     Tiger_Module_Registry::_resolveImages() uses: the listing's `ref`, else `main`;
 *   - `tiger_md`, when set, resolves.
 *
 * A listing that lags its repo shows buyers a stale version (a 1.x module advertised as a beta) and, when its
 * image ref does not exist, a broken logo on the Add Module card. Nothing else keeps the Directory in step with
 * the module repos, so this runs on every listing change AND on a daily schedule (drift happens when a module
 * repo releases, not when this repo changes).
 *
 *   php scripts/check-freshness.php [data/<one>.json ...]     # default: every listing
 *
 * Exit 0 when every listing is fresh; 1 with one line per problem, each naming its listing.
 * Uses GITHUB_TOKEN when set (rate limits).
 */

const API = 'https://api.github.com';
const RAW = 'https://raw.githubusercontent.com';

$files = array_slice($argv, 1) ?: glob(__DIR__ . '/../data/*.json');
$files = array_values(array_filter($files, static fn($f) => basename($f) !== 'index.json'));

$problems = [];
foreach ($files as $file) {
    $name    = basename($file);
    $listing = json_decode((string) file_get_contents($file), true);
    if (!is_array($listing)) { $problems[] = "$name: not valid JSON"; continue; }

    if (!preg_match('#^https://github\.com/([^/]+)/([^/]+?)/?$#', (string) ($listing['repository'] ?? ''), $m)) {
        $problems[] = "$name: repository is not a https://github.com/<org>/<repo> URL";
        continue;
    }
    [$org, $repo] = [$m[1], $m[2]];

    $release = api("/repos/$org/$repo/releases/latest");
    $tag     = is_array($release) ? (string) ($release['tag_name'] ?? '') : '';
    if ($tag === '') {
        $problems[] = "$name: $org/$repo has no published release to compare against";
    } else {
        $latest = ltrim($tag, 'v');
        if ((string) ($listing['version'] ?? '') !== $latest) {
            $problems[] = "$name: version {$listing['version']} but $org/$repo's latest release is $tag";
        }
        if (isset($listing['ref']) && (string) $listing['ref'] !== $tag) {
            $problems[] = "$name: ref {$listing['ref']} but $org/$repo's latest release is $tag";
        }
    }

    // Images, resolved exactly as the Module Manager resolves them.
    $ref    = (string) ($listing['ref'] ?? 'main');
    $images = [];
    foreach (['logo', 'hero'] as $k) { if (!empty($listing[$k])) { $images[$k] = $listing[$k]; } }
    foreach ((array) ($listing['screenshots'] ?? []) as $i => $s) { if ($s) { $images["screenshots[$i]"] = $s; } }
    foreach ($images as $k => $path) {
        $url = preg_match('#^https?://#i', (string) $path) ? (string) $path : RAW . "/$org/$repo/$ref/" . ltrim((string) $path, '/');
        $code = status($url);
        if ($code !== 200) { $problems[] = "$name: $k does not resolve (HTTP $code) — $url"; }
    }
    if (!empty($listing['tiger_md'])) {
        $code = status((string) $listing['tiger_md']);
        if ($code !== 200) { $problems[] = "$name: tiger_md does not resolve (HTTP $code) — {$listing['tiger_md']}"; }
    }
}

if ($problems) {
    fwrite(STDOUT, "Directory listings out of step with their repos:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}
fwrite(STDOUT, "All " . count($files) . " listings match their repo's latest release, and every image resolves.\n");
exit(0);

/** GET a GitHub API resource as decoded JSON, or null. */
function api(string $path): ?array
{
    $body = request(API . $path, true)[1];
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : null;
}

/** The HTTP status of a URL (HEAD-like GET, redirects followed). */
function status(string $url): int
{
    return request($url, false)[0];
}

/** @return array{0:int,1:string} [status, body] */
function request(string $url, bool $api): array
{
    $h = ['User-Agent: tiger-vendor-bot'];
    if ($api) {
        $h[] = 'Accept: application/vnd.github+json';
        if ($t = getenv('GITHUB_TOKEN')) { $h[] = 'Authorization: Bearer ' . $t; }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $h, CURLOPT_NOBODY => !$api]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $body];
}

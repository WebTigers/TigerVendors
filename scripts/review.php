<?php
/**
 * review.php — the tiger-vendor-bot's listing reviewer (validation core).
 *
 * The open, AI-reviewed submission flow:
 *   1. A vendor opens a PR adding data/<Org>_<Repo>.json.
 *   2. A scheduled Action (a few times/day) runs this over the changed listing(s):
 *        a. DETERMINISTIC checks (below) — schema, public repo, a manifest (module.json OR a
 *           theme's theme.json) + TIGER.md present at the pinned ref, license, slug match.
 *        b. AI review (stubbed) — an LLM pass for quality/safety heuristics on the module code.
 *   3. The bot opens a PR **on the vendor's own repo** with its findings (public + accountable).
 *   4. On accept it sets review.status = "accepted" and merges the listing; CI recompiles
 *      index.json (compile-index.php). Rejected → the vendor corrects + the check re-runs.
 *
 * This file implements (a) — buildable today, no keys. (b) + the PR posting are marked TODO.
 *
 *   php scripts/review.php data/WebTigers_TigerDocs.json
 */

const RAW = 'https://raw.githubusercontent.com';
const API = 'https://api.github.com';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php scripts/review.php data/<Org>_<Repo>.json\n");
    exit(2);
}

$findings = [];
$fail = static function ($msg) use (&$findings) { $findings[] = ['ok' => false, 'msg' => $msg]; };
$pass = static function ($msg) use (&$findings) { $findings[] = ['ok' => true,  'msg' => $msg]; };

$listing = json_decode((string) file_get_contents($path), true);
if (!is_array($listing)) {
    $fail('listing is not valid JSON');
    report($findings); exit(1);
}

// --- filename convention: data/<Org>_<Repo>.json ---
if (!preg_match('/^([A-Za-z0-9._-]+)_([A-Za-z0-9._-]+)\.json$/', basename($path), $fm)) {
    $fail('filename must be data/<GitHubOrg>_<GitHubRepo>.json');
}

// --- required fields ---
foreach (['schema', 'slug', 'module', 'vendor', 'repository', 'version', 'description', 'license', 'pricing'] as $req) {
    if (!isset($listing[$req]) || $listing[$req] === '') { $fail("missing required field: {$req}"); }
}
if (($listing['schema'] ?? '') !== 'tiger.registry/v1') { $fail("schema must be 'tiger.registry/v1'"); }
if (isset($listing['slug']) && !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $listing['slug'])) { $fail('slug must be [a-z0-9_-]'); }

// --- repository must be a PUBLIC github repo ---
$org = $repo = null;
if (preg_match('~^https://github\.com/([^/]+)/([^/]+?)/?$~', (string) ($listing['repository'] ?? ''), $rm)) {
    [$org, $repo] = [$rm[1], $rm[2]];
    $meta = json_decode((string) http(API . "/repos/{$org}/{$repo}"), true);
    if (!is_array($meta) || empty($meta['full_name'])) {
        $fail('repository is not a reachable public GitHub repo (private repos are not allowed)');
    } elseif (!empty($meta['private'])) {
        $fail('repository is private — module code must be public for review');
    } else {
        $pass("public repo: {$meta['full_name']}");
    }
} else {
    $fail('repository must be a https://github.com/<org>/<repo> URL');
}

// --- the pinned ref exists + carries a valid manifest (slug match) + a TIGER.md ---
// Manifest detection is PRESENCE-based, not type-based: a code module ships module.json;
// a theme ships theme.json (its module slug is 'theme-' + the manifest key). We accept
// whichever exists — the listing's `type` is only a search label and never gates this.
$ref = (string) ($listing['ref'] ?? ($listing['version'] ?? 'main'));
if ($org) {
    $mj = http(RAW . "/{$org}/{$repo}/{$ref}/module.json");
    $tj = $mj === null ? http(RAW . "/{$org}/{$repo}/{$ref}/theme.json") : null;

    if ($mj !== null) {                                        // code module (module.json)
        $m = json_decode($mj, true);
        if (!is_array($m) || empty($m['slug'])) {
            $fail('module.json is invalid');
        } elseif (($m['slug'] ?? '') !== ($listing['slug'] ?? '')) {
            $fail("slug mismatch: listing='{$listing['slug']}' module.json='{$m['slug']}'");
        } else {
            $pass('module.json present + slug matches');
        }
    } elseif ($tj !== null) {                                  // theme (theme.json)
        $t = json_decode($tj, true);
        if (!is_array($t) || empty($t['key'])) {
            $fail('theme.json is invalid (needs a "key")');
        } elseif (($t['type'] ?? '') !== 'theme') {
            $fail('theme.json "type" must be "theme"');
        } elseif (('theme-' . $t['key']) !== ($listing['slug'] ?? '')) {
            $fail("slug mismatch: listing='{$listing['slug']}' expected 'theme-{$t['key']}' (theme.json key)");
        } else {
            $pass("theme.json present + slug matches (theme-{$t['key']})");
        }
    } else {
        $fail("no module.json or theme.json at {$org}/{$repo}@{$ref}");
    }

    if (http(RAW . "/{$org}/{$repo}/{$ref}/TIGER.md") === null) {
        $fail('no TIGER.md (the vendor description shown in the directory)');
    } else {
        $pass('TIGER.md present');
    }
}

// --- translated blurbs, if present, must be REAL translations pulled from the module -------------
// The directory publishes this text on the vendor's behalf, so it has to come from the vendor's own
// languages/ at the pinned ref (scripts/pull-i18n.php), not be typed into the listing by hand. A
// value that does not match the module's file is the signal that someone edited the wrong copy.
if (isset($listing['description_i18n'])) {
    if (!is_array($listing['description_i18n'])) {
        $fail('description_i18n must be an object of <locale> => <string>');
    } else {
        foreach ($listing['description_i18n'] as $loc => $text) {
            if (!preg_match('/^[a-z]{2}(-[A-Za-z0-9]+)?$/', (string) $loc)) {
                $fail("description_i18n: '{$loc}' is not a language code");
            }
            if (!is_string($text) || trim($text) === '') {
                $fail("description_i18n[{$loc}] must be a non-empty string");
            }
            if (is_string($text) && trim($text) === trim((string) ($listing['description'] ?? ''))) {
                $fail("description_i18n[{$loc}] is identical to the English description — untranslated");
            }
        }
        $pass('description_i18n: ' . count($listing['description_i18n']) . ' locale(s)');
    }
}

// --- license declared ---
if (empty($listing['license'])) { $fail('license is required (public code may still be proprietary — declare it)'); }
else { $pass("license: {$listing['license']}"); }

// TODO: AI review pass (quality + safety heuristics on the module code at the pinned ref).
//   -> call the LLM with the repo tree + key files, capture a verdict + notes.
// TODO: open a PR on the vendor's repo (github.com/<org>/<repo>) with these findings,
//   and on accept flip review.status = "accepted" in this listing + merge.

report($findings);
exit(array_filter($findings, fn($f) => !$f['ok']) ? 1 : 0);

function report(array $findings): void
{
    echo "\nTiger Vendor Registry — review\n" . str_repeat('-', 34) . "\n";
    foreach ($findings as $f) {
        echo ($f['ok'] ? '  ✓ ' : '  ✗ ') . $f['msg'] . "\n";
    }
    $bad = array_filter($findings, fn($f) => !$f['ok']);
    echo "\n" . ($bad ? '  RESULT: changes requested (' . count($bad) . ')' : '  RESULT: passes automated checks — pending AI review') . "\n\n";
}

function http(string $url): ?string
{
    $ctx  = stream_context_create(['http' => ['user_agent' => 'tiger-vendor-bot', 'timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { return null; }
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
    }
    return ($code >= 200 && $code < 300) ? $body : null;
}

<?php
/**
 * pull-i18n.php — pull each listing's translated blurb from the MODULE's own languages/ at its
 * pinned ref, and record it on the listing as `description_i18n`.
 *
 *   php scripts/pull-i18n.php [data/<Org>_<Repo>.json ...]     (default: every listing)
 *
 * WHY A SEPARATE STEP, not part of compile-index.php: the compiler is deliberately OFFLINE and
 * deterministic — it reads data/ + taxonomy.json and nothing else, so a build can never depend on
 * GitHub being up. Fetching there would make every build network-bound and non-reproducible.
 * Pulling here instead means the translations land in `data/*.json`, which is the reviewable
 * artifact: they show up in the PR diff like any other listing change, and a human can see exactly
 * what text the directory is about to publish on a vendor's behalf.
 *
 * WHY FROM THE MODULE, not written into the listing by hand: the module already ships
 * `languages/<loc>/<file>.php` for its own UI. Keeping the blurb there means one place to translate
 * and no second copy to drift — the same reason the registry stays vendor-clean (AGENTS.md).
 *
 * The key is `<slug>.listing.description`, matching the module's own key prefix. A module that
 * ships no translations is simply skipped: `description` (English) remains, which is the fallback
 * the consumer already expects.
 */
$root = dirname(__DIR__);
const RAW = 'https://raw.githubusercontent.com';

/** GET a URL, or null. A missing file is normal here (most modules ship no listing key yet). */
function fetch(string $url): ?string
{
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'TigerVendors-i18n', 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { return null; }
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { return (int) $m[1] === 200 ? $body : null; }
    }
    return $body;
}

/**
 * The value of $key in a PHP translation file, WITHOUT executing it.
 *
 * These files are third-party code fetched over the network; include()ing one would be remote code
 * execution in the build. A token scan is enough for `'key' => 'value',` and cannot run anything.
 */
function keyFromPhpArray(string $src, string $key): ?string
{
    foreach (token_get_all($src) as $i => $t) {
        if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        if (trim($t[1], "'\"") !== $key) { continue; }
        $tokens = token_get_all($src);
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $x = $tokens[$j];
            if (is_array($x) && $x[0] === T_CONSTANT_ENCAPSED_STRING) {
                $lit = $x[1];
                $q   = $lit[0];
                $val = substr($lit, 1, -1);
                // Undo only the escaping the quoting style actually applies.
                return $q === "'"
                    ? str_replace(["\\'", '\\\\'], ["'", '\\'], $val)
                    : stripcslashes($val);
            }
            if (is_string($x) && $x === ',') { break; }   // key with no string value
        }
    }
    return null;
}

$files = array_slice($argv, 1);
if (!$files) { $files = glob($root . '/data/*.json'); }
$files = array_values(array_filter($files, static fn($f) => basename($f) !== 'index.json'));

$changed = 0;
foreach ($files as $file) {
    $listing = json_decode((string) file_get_contents($file), true);
    if (!is_array($listing)) { echo "  !! " . basename($file) . ": unreadable\n"; continue; }

    $slug = (string) ($listing['slug'] ?? '');
    $ref  = (string) ($listing['ref'] ?? $listing['version'] ?? 'main');
    if (!preg_match('~github\.com/([^/]+)/([^/]+)~', (string) ($listing['repository'] ?? ''), $m)) { continue; }
    [, $org, $repo] = $m;

    // Which locales does the module ship? Read its en file's NAME from the repo tree.
    $key  = $slug . '.listing.description';
    $i18n = [];
    foreach (['en', 'es', 'pt', 'hi', 'de', 'fr'] as $loc) {
        // Convention: languages/<loc>/<slug-ish>.php — try the slug, then the repo name lowercased.
        foreach ([$slug, strtolower($repo), str_replace('-', '', $slug)] as $stem) {
            $src = fetch(RAW . "/$org/$repo/$ref/languages/$loc/$stem.php");
            if ($src === null) { continue; }
            $val = keyFromPhpArray($src, $key);
            if ($val !== null && $val !== '') { $i18n[$loc] = $val; }
            break;
        }
    }

    if (!$i18n) { printf("  -  %-22s no %s in languages/ at %s\n", $slug, $key, $ref); continue; }

    // English lives in `description` (unchanged); the map carries the rest.
    unset($i18n['en']);
    if (!$i18n) { printf("  -  %-22s only en\n", $slug); continue; }

    if (($listing['description_i18n'] ?? null) === $i18n) { printf("  =  %-22s up to date (%d locales)\n", $slug, count($i18n)); continue; }

    $listing['description_i18n'] = $i18n;
    $enc = json_encode($listing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $enc = preg_replace_callback('/^( +)/m', static fn($m) => str_repeat(' ', (int) (strlen($m[1]) / 2)), $enc);
    file_put_contents($file, $enc . "\n");
    printf("  +  %-22s %d locales from %s@%s\n", $slug, count($i18n), "$org/$repo", $ref);
    $changed++;
}
printf("\n  %d listing(s) updated. Run scripts/compile-index.php to rebuild the index.\n", $changed);

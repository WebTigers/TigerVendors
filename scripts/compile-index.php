<?php
/**
 * compile-index.php — build data/index.json from the per-module data/<Org>_<Repo>.json files.
 *
 * The registry is one JSON file per module (so PRs never conflict). This concatenates the
 * ACCEPTED listings into a single search index that Tiger fetches + caches. Run by CI on any
 * change under data/ or to taxonomy.json (see .github/workflows/compile.yml).
 *
 * TAXONOMY-ENFORCED. taxonomy.json (repo root, WebTigers-owned) declares the canonical `types`
 * (the top-level filter doors) and `categories` (functional filters, each scoped to a type). This
 * script FOLDS that vocabulary into index.json (so one fetch gives Tiger the catalog + its filters)
 * and VALIDATES every listing against it:
 *   - `type` MUST be a known type id → else the listing is SKIPPED (a vendor can't invent a door).
 *   - `category` MUST be valid for that type → else it's DROPPED (warned), the listing still lists.
 *   - `keywords` are FREE-FORM (any strings) and are NOT validated — they power search only.
 *
 * SPONSORSHIP lives in its own repo (WebTigers/Sponsors), never here: Tiger fetches that ranks map
 * separately and merges `priority` at search time.
 *
 *   php scripts/compile-index.php
 */
$root    = dirname(__DIR__);
$dataDir = $root . '/data';

// --- Load + normalize the taxonomy (the filter vocabulary) ---------------------------------------
$taxonomy = json_decode((string) @file_get_contents($root . '/taxonomy.json'), true);
if (!is_array($taxonomy) || empty($taxonomy['types'])) {
    fwrite(STDERR, "!! taxonomy.json missing or invalid — cannot validate listings\n");
    exit(1);
}
$typeIds = [];
foreach ($taxonomy['types'] as $t) { $typeIds[$t['id']] = true; }
$catTypes = [];   // categoryId => [typeId => true]  (which types a category is valid for)
foreach (($taxonomy['categories'] ?? []) as $c) {
    foreach (($c['types'] ?? []) as $ty) { $catTypes[$c['id']][$ty] = true; }
}

$modules = [];
foreach (glob($dataDir . '/*.json') as $file) {
    $name = basename($file);
    if ($name === 'index.json') {                          // our compiled output
        continue;
    }
    $json = json_decode((string) file_get_contents($file), true);
    if (!is_array($json)) {
        fwrite(STDERR, "  skip (invalid JSON): $name\n");
        continue;
    }
    if (($json['review']['status'] ?? '') !== 'accepted') { // only accepted listings appear
        fwrite(STDERR, "  skip (not accepted): $name\n");
        continue;
    }

    // TYPE must be a known door — else the listing doesn't appear (WebTigers owns the doors).
    $type = (string) ($json['type'] ?? '');
    if (!isset($typeIds[$type])) {
        fwrite(STDERR, "  skip (unknown type '{$type}', not in taxonomy): $name\n");
        continue;
    }

    // CATEGORY (string or array) must be valid for this type — invalid ones are dropped, not fatal.
    $cats = $json['category'] ?? [];
    $cats = is_array($cats) ? $cats : ($cats === '' ? [] : [$cats]);
    $kept = [];
    foreach ($cats as $cat) {
        if (isset($catTypes[$cat][$type])) {
            $kept[] = $cat;
        } else {
            fwrite(STDERR, "  drop category '{$cat}' (not valid for type '{$type}'): $name\n");
        }
    }
    // Store normalized: single value stays a string (back-compat), multiple → array.
    $json['category'] = count($kept) <= 1 ? ($kept[0] ?? '') : $kept;

    $modules[] = $json;
}

// Stable default order: alphabetical by module name. Tiger re-sorts per the chosen view
// (Featured = sponsored priority, Title, Latest) after merging sponsored.json.
usort($modules, static fn($a, $b) => strcasecmp($a['module'] ?? '', $b['module'] ?? ''));

$index = [
    'schema'       => 'tiger.registry-index/v1',
    'generated_at' => gmdate('c'),
    'count'        => count($modules),
    'taxonomy'     => [
        'types'      => $taxonomy['types'],
        'categories' => $taxonomy['categories'] ?? [],
    ],
    'modules'      => $modules,
];

file_put_contents($dataDir . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "  wrote data/index.json (" . count($modules) . " module" . (count($modules) === 1 ? '' : 's') . ", "
   . count($taxonomy['types']) . " types, " . count($taxonomy['categories'] ?? []) . " categories)\n";

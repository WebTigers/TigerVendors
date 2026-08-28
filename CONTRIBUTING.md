# Submitting a module to the Tiger Vendor Registry

One PR, one file. Takes about two minutes.

## 1. Prerequisites (in your module repo)

- It's a **public** GitHub repo (private repos are rejected — module code must be reviewable).
- Its root has a valid **manifest**:
  - a **code module** (app or plugin) → **`module.json`** (slug, version, `requires`, `provides`).
    See [WebTigers/TigerDocs](https://github.com/WebTigers/TigerDocs) as a template.
  - a **theme** → **`theme.json`** (`key`, `type:"theme"`, `assetBase`). Its listing `slug` is
    **`theme-` + the manifest `key`** (e.g. key `grey-mist` → slug `theme-grey-mist`).
- Its root has a **`TIGER.md`** — your human-facing description (this becomes the "View more"
  modal in Tiger). Markdown; keep it current.
- You've cut a **release** (a tag, e.g. `v1.0.0`) — the registry pins to a reviewed release,
  so what's reviewed is exactly what installs.

**`type` vs `category`** are two independent axes: `type` (`theme` \| `app` \| `plugin`) is a
searchable *kind* label — it's **non-behavioral**, purely for filtering the directory (Tiger
detects real capabilities like `theme.json` / a `migrations/` folder at activation). `category`
is the subject-matter *domain* (Commerce, Media, …). A theme can still be a Media-domain theme.

## 2. Add your listing

Create **`data/<GitHubOrg>_<GitHubRepo>.json`** (exact repo org + name, e.g.
`data/Acme_AcmeBilling.json`), following [`schema/registry.v1.json`](schema/registry.v1.json):

```json
{
  "schema": "tiger.registry/v1",
  "slug": "billing",
  "module": "Billing",
  "vendor": "Acme",
  "repository": "https://github.com/Acme/AcmeBilling",
  "website": "https://acme.example",
  "contact": "support@acme.example",
  "version": "1.0.0",
  "ref": "v1.0.0",
  "logo": "https://raw.githubusercontent.com/Acme/AcmeBilling/v1.0.0/assets/logo.png",
  "hero": "https://raw.githubusercontent.com/Acme/AcmeBilling/v1.0.0/assets/hero.png",
  "description": "Stripe billing for your Tiger orgs — plans, subscriptions, invoices.",
  "tiger_md": "https://raw.githubusercontent.com/Acme/AcmeBilling/v1.0.0/TIGER.md",
  "keywords": ["billing", "stripe", "subscriptions"],
  "category": "Commerce",
  "license": "MIT",
  "pricing": { "model": "freemium", "pro_url": "https://acme.example/billing/pro" }
}
```

Leave out the `review` block — the bot writes that.

### Media (logo / hero / screenshots / video)

Directory media lives in **your module's own repo, in a dedicated flat `media/` folder** (e.g.
`media/logo.jpg`, `media/hero.jpg`, `media/screenshot-01.jpg`) — **not** `assets/`. `assets/` is your module's *runtime* files (the CSS/JS/
images it serves — for a theme they're symlinked to the web root); directory media is marketing/docs,
so keep it separate and out of what ships and gets served. `media/` only needs to be **committed at
your release `ref`** — raw serves the git tree, so a release build can even exclude it from the
installable zip and the URL still resolves. The registry only points at it, never hosts it. In
`logo`, `hero`, `screenshots[]`, and `video` you give either:

- a **repo-relative path** (`media/screenshot-01.jpg`) — Tiger resolves it against your pinned
  `ref` to `https://raw.githubusercontent.com/<org>/<repo>/<ref>/…`. Use the **same paths in your
  `README.md`** (GitHub renders them relatively) so one set of files serves both. *(Recommended.)*
- a **full URL** — used as-is.

Pin media to a release `ref` (not `main`) so it matches the reviewed version.

**Sizes & formats:**

| Field | Recommended | Notes |
|---|---|---|
| `logo` | **256×256 PNG** (transparent), static | Shown small but crisp on retina. **Not SVG** — GitHub raw serves SVG as `text/plain`, so it won't render. Avoid animated GIF logos (distracting + heavy). |
| `hero` | **~1200×630**, < 400 KB | Wide banner, cropped `cover`. A short animated WebP/GIF demo is fine here. |
| `screenshots[]` | **1280×720 (16:9)**, PNG/WebP, < 300 KB each, ≤ 6–8 | Consistent aspect ratio reads as designed. Shown as a **lightbox gallery**. |

**Video** (`video`) — opened in the lightbox from a play tile:

- **Self-hosted** (best, no third party): a repo-relative `.mp4`/`.webm`, or a full URL to your own
  CDN. GitHub raw *can* serve `.mp4` (Range requests work), but it sends `application/octet-stream`,
  which plays in Chrome/Firefox but is **unreliable in Safari** — for a production demo prefer a real
  CDN URL (correct `video/mp4`). Keep repo-hosted clips short (100 MB file cap; avoid history bloat).
- **YouTube / Vimeo**: paste a normal watch link. It's embedded **lazily via `youtube-nocookie`** —
  nothing loads from Google until a viewer clicks play.
- Object form adds a **repo-hosted poster** so the card shows no third-party thumbnail:
  `"video": { "src": "https://youtu.be/…", "poster": "media/demo-poster.jpg" }`.

### Translations (optional)

The directory can show your blurb in a reader's own language. You do **not** write the translations
into your listing — you keep them where your module's other translations already live:

```php
// languages/es/<yourmodule>.php   (and de, fr, hi, pt … whichever you ship)
'<your-slug>.listing.description' => 'Tu descripción, traducida.',
```

The key is `<slug>.listing.description`, matching your module's own key prefix. Then:

```bash
php scripts/pull-i18n.php data/<Org>_<Repo>.json   # reads your languages/ at the PINNED ref
php scripts/compile-index.php                      # rebuild the index
```

That writes a `description_i18n` map onto your listing, which is what ends up in `index.json`.

Three things follow from doing it this way:

- **Your repo stays the single source of truth for your own copy.** The registry never holds a
  second version of your text that can drift from the one your module ships.
- **It is pulled from the pinned `ref`, not `main`** — so publishing a translation means cutting a
  release, exactly like any other change the directory shows.
- **It is optional.** Ship nothing and your listing keeps its English `description`; every consumer
  already falls back to it.

The review gate rejects a `description_i18n` entry that is byte-identical to the English
`description` — that means the wrong copy was edited, not that the text was translated.


## 3. Open the PR

Open a pull request with just that one file. The **`tiger-vendor-bot`** runs a few times a
day: it validates the schema, confirms the repo is public with a manifest (`module.json` or a
theme's `theme.json`) + `TIGER.md` at your pinned `ref`, checks the license and that the release
exists, then does an AI review pass. It opens a PR **on your repo** with the findings.

- **Accepted** → your listing merges (`review.status: accepted`) and `index.json` recompiles;
  you're searchable in Tiger within a few hours.
- **Changes requested** → address the findings, push a new release/ref, and it re-runs.

## Updating

Bump `version` + `ref` in your listing (point at the new release) and open a PR. Same review.

# install/catalog.json — what a fresh Tiger is offered

Read **live** by installer front-ends (TigerWHM's Install flyout today; Softaculous and the web
installer as they adopt it). Change this file on `main` and every new install sees it — no host
has to update a plugin.

- `featured.theme` / `featured.modules` — what is pre-selected. The theme and module lists themselves
  come from [`data/index.json`](../data/index.json) (the Directory), so listing a module there is what
  makes it *offerable*; this file only decides what is ticked by default.
- `skill_packs[]` — groups of Agent Skills installed **as a set** (a user picks "Web design", not
  fifteen individual skills). Each skill is `{repo, path, ref?}` pointing at a folder with a
  `SKILL.md` (the portable Agent Skills format), in any public GitHub repo. `default: true` packs
  are pre-ticked.

A host can override the defaults for their server in WHM; this file is the platform-wide baseline.

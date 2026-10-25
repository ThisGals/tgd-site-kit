# TGD Site Kit

A WordPress plugin from This Gal's Design: one plugin, modules switched on per
site. Read `README.md` for what it does. This file is for working on it.

Private development notes (local paths, which sites it is tested against) live in
`CLAUDE.local.md`, which is gitignored. **This repository is public**: no client
names, site URLs, measurement IDs, user names, credentials or content copied from
a client's site go into any tracked file or commit message. Test evidence stays
outside the repo.

## Names

Slug and text domain `tgd-site-kit`, namespace `TGD_Site_Kit\`, options
`tgdsk_*`, post/term/user meta `_tgdsk_*`, REST `tgdsk/v1`. **Renaming any of
these means migrating stored data on every site that runs the kit.**

## Commands

```bash
composer install          # dev tools only; the plugin has no runtime dependencies
vendor/bin/phpcs          # WordPress Coding Standards; must be clean
vendor/bin/phpcbf
```

Release build: see `README.md`, Releasing.

## How it is put together

- `tgd-site-kit.php` loads `includes/registry.php`, `includes/admin.php`,
  `includes/updater.php` and the module files in `includes/modules/`.
- The updater is not a module: it is always on, because a site that cannot
  see updates cannot be told it is behind. It never offers an update to a copy
  with a `.git` folder, because the upgrader deletes the old plugin folder and
  a development copy is often a link to the repository.
- **A module file declares itself and nothing more until it is switched on.**
  `register_module()` at load; the module's `boot` runs on `plugins_loaded`
  priority 20 only when its id is in `tgdsk_modules`.
- **Every module is off by default and activation writes nothing.** With no
  module on, the front end must be byte-identical to the site without the
  plugin. Test that after any change to loading.
- **Nothing may call `__()` before `init`.** Module labels are callables
  (`module_text()`), and boot paths use string-free checks. WordPress 6.7+ logs
  a notice on every request otherwise.
- Settings: Settings → TGD Site Kit, `manage_options` only. Every write is an
  admin-post handler that calls `check_owner_write()` (capability and nonce).
- Uninstall deletes the plugin's options only. **Post, term and user meta stay**:
  SEO values are the site's content.

## Modules

- `connector`: `GET /wp-json/tgdsk/v1/status`, administrators only. Versions,
  waiting updates (from WordPress's update transients; never an outbound
  request), auto-update settings, enabled modules.
- `analytics`: the GA4 tag exactly as Beehive Analytics prints it, with an
  import of Beehive's settings. Silent while Beehive, Site Kit by Google, or any
  other tag for the same ID is on the page.
- `seo`: a port of SmartCrawl 3.17.1's head output (title, description,
  canonical, robots, Open Graph, Twitter), an idempotent import from SmartCrawl,
  core sitemaps with SmartCrawl's URLs redirected, robots.txt. Silent while
  SmartCrawl is active. Template text must not go through
  `sanitize_text_field()` (it deletes `%ca` from `%%category%%`); use
  `sanitize_template()`.
- `schema`: one JSON-LD `@graph` per page in a site schema plugin's shape
  (business, WebSite, typed page, breadcrumbs, services, extra nodes), every
  value from settings. Never two graphs: silent while SmartCrawl's schema, a
  must-use plugin printing JSON-LD, or JSON-LD in Avada's code fields is
  present; on the finished page it removes itself next to another graph or
  business entity, and drops only its breadcrumb or page node next to a theme's
  own. The page description comes from `SEO\resolved_description()`, so it
  matches whichever plugin prints the meta description.

## Testing a switch

The test that matters is the rendered page, not the stored data. The tools in
`tools/` snapshot every published URL's head (`head-snapshot.php`) or JSON-LD
(`schema-snapshot.php`) as an anonymous visitor and diff two snapshots. Capture
the old plugin's output first, switch, capture again, and account for every
difference.

## Versions

Requires WordPress 6.5 and PHP 8.1 by header. Developed on WordPress 7.1 and
PHP 8.4; nothing knowingly depends on either.

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
  `includes/updater.php`, `includes/db.php`, `includes/breadcrumbs/trail.php`
  and the module files in `includes/modules/`.
- **Tables are created by a module's boot, never on activation**
  (`ensure_table()` in `includes/db.php`, versions in `tgdsk_db`). A site that
  never switches redirects or the 404 log on never gets their tables.
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
- Uninstall deletes the plugin's options and the 404 log table. **Post, term
  and user meta and the redirects table stay**: SEO values and redirects are
  the site's content.

## Modules

- `connector`: `GET /wp-json/tgdsk/v1/status`, administrators only. Versions,
  waiting updates (from WordPress's update transients; never an outbound
  request), auto-update settings, enabled modules. Since 0.4.0 also
  `includes/connector/routes.php`: `GET|POST /seo/post/<id>` and
  `/seo/term/<id>` (SEO fields and the schema per-page fields), and, while
  those modules are on, `/redirects`, `/redirects/<id>` and `GET /not-found`.
  **Every write is guarded** (SEO: `expected_version`, an md5 of the stored
  values as the GET shows them, or `expected` per field; redirects:
  `expected_modified_gmt`) and refused with 409, nothing written, when it
  doesn't match. Values are validated as the screens do (`sanitize_template()`
  for text), and refused where cleaning would change their meaning (a
  canonical that isn't a URL, a directive `sanitize_key()` would alter); the
  answer lists what cleaning did change as `adjusted`. `robots_adv` goes
  through `SEO\parse_robots_directives()` in the box and the route alike
  (stored as entered, or refused). Meta is written through
  `wp_slash()` so it reads back byte for byte. No route writes content,
  users or plugins, and none deletes; the one setting a route writes is a
  post's place in the SEO settings' `exclude_post_ids` (`sitemap_exclude`,
  0.4.3, through `SEO\set_post_sitemap_excluded()`, which rewrites only
  that key of the stored option). Posts also answer `in_sitemap` and
  `sitemap_reason` from `SEO\post_sitemap_status()`, which asks core's
  posts provider and runs core's sitemap query args narrowed to the post,
  so every plugin's filters count. While SmartCrawl is active
  `renders.notes` says `renders` is the kit's, not SmartCrawl's. `renders` is the SEO
  resolver's output for that object, the same code that prints the head.
  **Elementor:** `Post_Entity::content()` goes through `builder_text()`: on
  an `_elementor_edit_mode` = builder page, Elementor's own
  `get_plain_text_from_data()` of the current `_elementor_data`, cached in
  the `tgdsk_el_text_<id>` transient against the layout's MD5, because
  post_content's copy goes stale after a REST write to the layout. Since
  0.4.2 it renders as Elementor's editor save does (dynamic tags in
  `remove` mode) and with `pre_wp_get_loading_optimization_attributes`
  short-circuited: the render runs in the head, and WordPress would
  otherwise count its images and lazy-load the page's first ones. A cold
  and a warm request for a page must be byte-identical. Elementor's element
  and CSS caches don't hold the head; nothing needs purging after a route
  write unless a page cache plugin is on.
- `analytics`: the GA4 tag exactly as Beehive Analytics prints it, with an
  import of Beehive's settings. Silent while Beehive, Site Kit by Google, or any
  other tag for the same ID is on the page. Since 0.2.4 also Beehive's Tag
  Manager container: `var dataLayer = [{…}]` plus Google's loader after the
  head scripts (jQuery enqueued, as Beehive's handle depends on it), the
  noscript frame on `wp_body_open`, no role exclusion or preview test (Beehive
  has neither for the container), each half taken out of a page that already
  loads the same container. Variable names: Beehive pushes the bare key
  (`login_status`) unless a name is stored in its option, so that is the
  default here and the import copies stored names. Forminator and Hustle event
  pushes are not reproduced; the import lists them. `tgdsk_analytics_print`
  (default true) keeps both tags off a request. Each conflict notice clears
  when the page it names next renders clean (0.2.5; before that only Save
  cleared it, and it otherwise lasted a week).
- `seo`: a port of SmartCrawl 3.17.1's head output (title, description,
  canonical, robots, Open Graph, Twitter), an idempotent import from SmartCrawl,
  core sitemaps with SmartCrawl's URLs redirected, robots.txt. Silent while
  SmartCrawl is active. The import carries SmartCrawl's ignored post IDs
  into `sitemap.exclude_post_ids`; since 0.4.3 they are listed on the SEO
  tab (own form, `handle_sitemap_posts_save()`, removes only ticked IDs the
  screen listed) and switchable in the post's SEO box, and the import
  report lists them and counts the ignored and extra URLs it can't carry. Template text must not go through
  `sanitize_text_field()` (it deletes `%ca` from `%%category%%`); use
  `sanitize_template()`.
- `schema`: one JSON-LD `@graph` per page in a site schema plugin's shape
  (business, WebSite, typed page, breadcrumbs, services, extra nodes), every
  value from settings. Never two graphs: silent while SmartCrawl's schema, a
  must-use plugin printing JSON-LD, or JSON-LD in Avada's code fields is
  present; on the finished page it removes itself next to another graph or
  business entity, and drops only its breadcrumb or page node next to a theme's
  own. A block is a business entity when a top-level node claims the kit's
  business or WebSite `@id`, or is typed as one (`is_business_type()`:
  `BUSINESS_TYPES` in `includes/schema/business-types.php`, generated from
  schema.org 30.1, the configured business type, then a suffix fallback).
  Never a suffix match alone: `GeneralContractor`, `Plumber` and most
  LocalBusiness subtypes end in nothing business-like. The page description comes from `SEO\resolved_description()`, so it
  matches whichever plugin prints the meta description. For what the fields
  don't cover, extra properties typed as one JSON object are merged into the
  business node (sitewide setting) and into a page's own node (per-page
  `_tgdsk_schema_page`); neither can change the node's `@id` or `@type`.
  Blog posts (`post` only) get an article node and one `Person` per author
  from the Posts setting (`posts.article_type`), overridable per post
  (`_tgdsk_schema_article`). Settings saved before 0.2.2 have no `posts`
  section and read as `None`, which prints exactly what 0.2.1 did; turning it
  on is a deliberate step. `posts.author` (`user` or `business`, default
  `user`, so 0.2.2 settings need no guard) picks who the article credits;
  `article_author()` is the one place that decides, so a per-post byline
  is one more branch there. With `business` no `Person` is printed. Where the kit prints a post's article it switches
  off the `NewsArticle` that Avada Builder's Meta element prints
  (`awb_meta_element_render_structured_data`). Nothing on a 404 or a search
  page. The conflict notice clears when its page renders clean and on save.
  An archive's `CollectionPage` takes its `@id` and `url` from the queried
  term or post type's own link plus pagination (`archive_url()`), never from
  the request: `get_pagenum_link()` kept the visitor's query string.
- `redirects`: `includes/redirects/` (store, match, import). Matched on
  `init` like Redirection, exact before regex, ID order. Defaults are
  Redirection's (case-insensitive, trailing slash ignored, query exact) and
  the import keeps each item's own flags. The Location is the target as
  stored, so relative stays relative. Regex runs over the decoded path plus
  `?query` with `preg_replace()` on the whole of it, as Redirection does.
  A new redirect defaults to `pass` (0.4.1); the imports switch exact
  redirects with no query in the source to `pass` unless told not to.
  **`validate()` is the one gate** for the form, the route and both imports:
  never wp-admin, login, REST, XML-RPC, cron, or a path that is published
  content (posts via `url_to_postid()`, terms by slug, post type archives,
  the front page; a regex is tried against every published path), no loops,
  no duplicates (same match key and query). Silent while Redirection is
  active or SmartCrawl's redirects are on. Imports: `origin` plus
  `import_hash`, so a rerun updates only rows still as imported. SmartCrawl
  regexes, its per-post 301 field and attachment redirects are reported, not
  carried (it matches regexes against the full URL; the per-post field
  redirects published posts, which `validate()` refuses).
- `not_found`: `template_redirect` at 99, front end, GET and HEAD only, not a
  410 the redirects module sent. `is_noise()` drops probes; the referrer is
  kept as scheme, host and path. Pruned at most daily by age then by count.
- `indexnow`: SmartCrawl's triggers (publish, update while published, trash
  from published) and post types; key from `wds_instant_indexing_options`
  (else generated), served on `parse_request`. URLs collected per request,
  drained into a single cron event at shutdown (`tgdsk_indexnow_send`), never
  sent inline. **`is_production()` gates every send**: environment type
  `production` and a host not ending .local, .test or .localhost; the
  `tgdsk_indexnow_is_production` filter is for tests with a stubbed endpoint
  only. Silent while SmartCrawl's `instant_indexing` is on. History in
  `tgdsk_indexnow_log` (100 entries, 90 days).
- `breadcrumbs`: `[tgdsk_breadcrumbs]` and the `tgd-site-kit/breadcrumbs`
  block (plain-JS editor script in `assets/`, server-rendered).
  `Breadcrumbs\post_trail()` builds the schema module's BreadcrumbList too:
  **change the trail there, never in graph.php.** The home label is sitewide
  only, so the visible trail and the graph can't differ.

## Testing a switch

The test that matters is the rendered page, not the stored data. The tools in
`tools/` snapshot every published URL's head (`head-snapshot.php`) or JSON-LD
(`schema-snapshot.php`) as an anonymous visitor and diff two snapshots. Capture
the old plugin's output first, switch, capture again, and account for every
difference. Hand-written JSON-LD that nests everything in one object (no
`@graph`) is compared with `schema-embed-diff.php`, which resolves the kit's
`@id` references first so both sides have the same shape.

## Versions

Requires WordPress 6.5 and PHP 8.1 by header. Developed on WordPress 7.1 and
PHP 8.4; nothing knowingly depends on either.

## The client portal is not here

0.3.0 (never released) had a client portal module. It moved to its own
plugin, which runs only on thisgals.com; client sites don't carry it. Don't add
it back, and don't make this plugin depend on it or the other way round. The
one link is a public hook: the portal adds a callback to
`tgdsk_analytics_print`, which costs nothing when the portal isn't installed.

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
  `includes/updater.php`, `includes/db.php`, `includes/upgrade.php`,
  `includes/breadcrumbs/trail.php` and the module files in
  `includes/modules/`.
- **Tables are created by a module's boot, never on activation**
  (`ensure_table()` in `includes/db.php`, versions in `tgdsk_db`). A site that
  never switches redirects or the 404 log on never gets their tables.
- **Stored data is upgraded by `includes/upgrade.php`**, on `plugins_loaded`
  priority 5 whenever `tgdsk_version` is behind `VERSION`, because a manual
  upload runs no activation hook. Each step is gated on its version and safe
  to run twice. 0.5.0's step: the redirects table gains `replaces_published`,
  origins that named an importer (`<source>:<id>`) become `import` and the
  connector route's old origin becomes `connect`, and the importers'
  bookkeeping (options, transients, `_tgdsk_*_imported` meta) goes.
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
- **No other plugin is named** in code, comments, UI strings or the docs,
  apart from the platforms the kit supports: the page builders (Avada,
  including Fusion, and Elementor) and, since 0.5.1, WooCommerce. The kit
  doesn't step aside for other plugins: a module does its job whatever else
  is active.
- **WooCommerce support runs only while WooCommerce is active**
  (`includes/woocommerce.php`, `Woo\active()`), and adds nothing on a site
  without it: no hook, no query, no tag. Every caller checks first. The checks that remain are about output on
  the page (another JSON-LD graph, a second tag for the same ID), never about
  which plugin printed it.
- Settings: Settings → TGD Site Kit, `manage_options` only. Every write is an
  admin-post handler that calls `check_owner_write()` (capability and nonce).
- Uninstall deletes the plugin's options and the 404 log table. **Post, term
  and user meta and the redirects table stay**: SEO values and redirects are
  the site's content.

### The two output buffers

Only two modules buffer the page, each because no hook sees what it needs:

- **Analytics** (`start_duplicate_check()`, `template_redirect` priority 1):
  takes the kit's own GA4 tag or container back out of a page that already
  loads the same ID. The other copy can come from anywhere (a theme or
  builder code field, a pasted footer snippet, a page's content), and
  nothing but the finished HTML shows them all. Front-end HTML only, a few
  string searches, the page returned unchanged unless a duplicate is found.
  Removing it would double-tag the sites that carry a pasted tag.
- **Schema** (`start_page_check()`, `template_redirect` priority 1): takes the
  kit's graph out next to another graph or business entity, and only its
  breadcrumb or page node next to a theme's own. Avada's breadcrumbs element
  prints its BreadcrumbList in the body after `wp_head`, so the head can't
  know in advance. It starts before `wp_head` so a JSON-LD block printed
  earlier in the head is seen too.

Neither buffer runs in wp-admin, AJAX, cron, REST, feeds, robots.txt or
embeds (`is_front_end_request()`). Don't add a third; use a hook.

## Modules

- `connector` (shown as **TGD Connect**; the id and every route stay as they
  are, because stored module lists and the WordPress MCP server use them):
  `GET /wp-json/tgdsk/v1/status`, administrators only. Versions, waiting
  updates (from WordPress's update transients; never an outbound request),
  auto-update settings, enabled modules, and `kit.health` (`kit_health()`:
  the last IndexNow submission, redirect and 404 counts, the
  automatic-redirect switch, the tag check's last result, recorded module
  errors; options and two counts, never a fetch; `sitemap_ping` is always
  null, the kit doesn't ping). Since 0.4.0 also
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
  so every plugin's filters count. `renders` is the SEO resolver's output
  for that object, the same code that prints the head; its notes say when
  `WP_CACHE` is on (purge before checking the live head).
  Redirects written here get origin `connect`; the answer's `redirect.hides`
  lists the published content a redirect covers.
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
  write unless a page cache is on.
- `analytics`: the GA4 tag (`tgdskDataLayer`, `tgdsk_ga()`; before 0.5.0
  the tag used another name pair, so 0.5.0 changed that one line pair on
  every page) with `config` carrying `anonymize_ip` and
  `allow_google_signals` only, and a Tag Manager container: `var dataLayer =
  [{…}]` plus Google's loader after the head scripts (jQuery enqueued), the
  noscript frame on `wp_body_open`, no role exclusion or preview test, each
  half taken out of a page that already loads the same container (see the
  buffers above). Variable names default to the bare key (`login_status`).
  `tgdsk_analytics_print` (default true) keeps both tags off a request. Each
  conflict notice clears when the page it names next renders clean (0.2.5).
  **The tag check** (`includes/analytics/tag-check.php`, 0.5.0): an async
  Site Health test (`health-check-tgdsk-analytics-tags` through admin-ajax)
  and a button on the tab, never a front-end request: fetches the home page
  logged out and reports the kit's ID or container loaded by something else,
  other Google tags or containers, and the kit's own missing. Last result in
  `tgdsk_analytics_tag_check`.
- `seo`: head output (title, description, canonical, robots, Open Graph,
  Twitter) from per-object values and site defaults, core sitemaps with the
  common sitemap addresses redirected, robots.txt. The `onpage` settings'
  key names and `%%macro%%` language are stored data on every site; never
  rename them. Posts left out one by one are in `sitemap.exclude_post_ids`,
  listed on the SEO tab (own form, `handle_sitemap_posts_save()`, removes
  only ticked IDs the screen listed) and switchable in the post's SEO box.
  **Nothing public-facing may depend on `show_ui` at request time**: Avada's
  role manager (`awb_role_manager_access_capability`, priority `PHP_INT_MAX`)
  makes it per user for Portfolio, FAQs and sliders (false for a logged-out
  visitor, and for an application-password REST request, whose user is only
  known after `init`). The sitemap uses `has_admin_screen()`: `show_ui` or the
  value noted just before the role manager by `note_registered_ui()` (0.4.4).
  Template text must not go through
  `sanitize_text_field()` (it deletes `%ca` from `%%category%%`); use
  `sanitize_template()`.
  **WooCommerce (0.5.1):** products get `og:type` `product` and the
  `Woo\opengraph_tags()` (price as `wc_format_decimal`, a variable product's
  lowest; none when `get_price()` is empty; availability values kept as the
  sites had them, `instock` / `out of stock` / `backorder` + `available for
  order`; brand from `product_brand` only; SKU as `product:retailer_item_id`)
  and no `article:*`. The shop is `Shop_Entity` (the `product` archive, values
  from the shop page's meta first, then `pt-archive-product`), the first
  entity with `pagination_links()` (rel prev/next; printed by `head_tags()`
  for any entity that answers, so later archives only need to answer it).
  A `product_cat` term's images start with its `thumbnail_id`. robots.txt gets
  `Woo\robots_lines()` inside its first group (`add_robots_lines()`), setting
  `robots.woocommerce`. `filter_robots()` lets noindex and nofollow win over
  index and follow, because WooCommerce noindexes its private pages on the
  same `wp_robots` filter. **Author values** go through
  `SEO\public_author_name()`: the display name, or '' when it is an email
  address, the login or the nicename. A login that is a person's name
  (`login_is_real_name()`, 0.6.2: a space between words, and first + last
  name fields saying the same) is printed: such logins are already shown by
  themes' bylines.
- `schema`: one JSON-LD `@graph` per page (business, WebSite, typed page,
  breadcrumbs, services, extra nodes), every value from settings. Never two
  graphs: silent while a must-use plugin printing JSON-LD, or JSON-LD in
  Avada's code fields, is present; on the finished page it removes itself
  next to another graph or business entity, and drops only its breadcrumb or
  page node next to a theme's own. A block is a business entity when a
  top-level node claims the kit's business or WebSite `@id`, or is typed as
  one (`is_business_type()`: `BUSINESS_TYPES` in
  `includes/schema/business-types.php`, generated from schema.org 30.1, the
  configured business type, then a suffix fallback). Never a suffix match
  alone: `GeneralContractor`, `Plumber` and most LocalBusiness subtypes end
  in nothing business-like. The page description comes from
  `SEO\resolved_description()`, the SEO module's rules whether or not that
  module is on. For what the fields don't cover, extra properties typed as
  one JSON object are merged into the business node (sitewide setting) and
  into a page's own node (per-page `_tgdsk_schema_page`); neither can change
  the node's `@id` or `@type`. Blog posts (`post` only) get an article node
  and one `Person` per author from the Posts setting (`posts.article_type`),
  overridable per post (`_tgdsk_schema_article`). Settings saved before 0.2.2
  have no `posts` section and read as `None`, which prints exactly what
  0.2.1 did; turning it on is a deliberate step. `posts.author` (`user` or
  `business`, default `user`, so 0.2.2 settings need no guard) picks who the
  article credits; `article_author()` is the one place that decides, so a
  per-post byline is one more branch there. With `business` no `Person` is
  printed. Where the kit prints a post's article it switches off the
  `NewsArticle` that Avada Builder's Meta element prints
  (`awb_meta_element_render_structured_data`). Nothing on a 404 or a search
  page. The conflict notice clears when its page renders clean and on save.
  An archive's `CollectionPage` takes its `@id` and `url` from the queried
  term or post type's own link plus pagination (`archive_url()`), never from
  the request: `get_pagenum_link()` kept the visitor's query string.
  The business type may be several (`business_types()`, stored as
  `OnlineStore, LocalBusiness`). The Schema tab's "JSON-LD from elsewhere"
  (`found_elsewhere()`) lists must-use plugins, Avada's code fields, Elementor
  Custom Code snippets (`elementor_snippet`, code in `_elementor_code`) and the
  last page where the graph stepped aside (its types are recorded with the
  conflict). **WooCommerce (0.5.1):** `Woo\is_own_structured_data()` (every
  node a Product, ProductGroup, BreadcrumbList or Review) is never competing;
  `woocommerce_structured_data_breadcrumblist` returns nothing while the
  graph prints breadcrumbs; a product page's graph has a Product
  (`Woo\product_node()`), dropped by the page check next to another Product;
  `post_trail()` goes through `Woo\main_category()` as WooCommerce's own
  breadcrumbs do. A Person whose only name is an email or a login isn't
  printed, and the article credits the business.
- `redirects`: `includes/redirects/` (store, match, chains, auto, csv).
  Matched on `wp_loaded` at 0 (0.5.0; `init` before), exact before regex, ID
  order, so every type is registered for the published check. Form defaults:
  case-insensitive, trailing slash ignored, query `pass`. The Location is the
  target as stored, so relative stays relative. Regex runs over the decoded
  path plus `?query` with `preg_replace()` on the whole of it (stored
  redirects depend on that); a `pass` regex whose pattern left the query in
  place doesn't add it again (0.5.0 fix). **`validate()` is the one gate** for
  the form, the route, the CSV import, chain fixes and automatic redirects:
  never wp-admin, login, REST, XML-RPC, cron; a path that is published content
  (`published_item()`: posts via `url_to_postid()`, terms by slug, post type
  archives, the front page; **attachments never count**; a regex is tried
  against every published path, `published_paths()`, kept per request) only
  with `replaces_published`; no loop (`leads_back()` follows exact redirects
  up to ten hops); no duplicates (same match key and query). `validate()`
  takes `$pending` rows so a CSV dry run answers as the import will. At
  request time an unflagged redirect whose request path is published now is
  skipped (`note_skipped()`, `tgdsk_redirects_skipped`), costing queries only
  when a redirect matched. `origin`: '' (the tab), `import`, `connect`,
  `auto`. Chains (`find_chains()`) simulate each hop with `exact_target()` and
  `regex_target()`; regex targets with a backreference are left out; a fix
  rewrites only the chain's own targets, through `validate()`. Automatic
  redirects (`add_auto_hooks()`, setting `auto`, default true): old paths
  noted on `pre_post_update` / `edit_terms`, compared on
  `wp_after_insert_post` (REST fires it after terms and meta) / `edited_term`;
  descendants of hierarchical posts and child terms included; the automatic
  redirect from the new address (a rename back) is deleted first, then
  targets equal to the old path are repointed, then old → new goes in.
  CSV (`export_csv()`, `import_csv()`): UTF-8 with BOM, CRLF, formula cells
  escaped with `'` and unescaped on import, a source or target starting like a
  formula refused, header names matched with aliases, origin kept when known.
  What enabled `replaces_published` redirects hide (`hidden_objects()`, posts
  and terms, transient `tgdsk_redirects_hidden`, forgotten on any redirect,
  post or term change) leaves the sitemap (reason `redirected`) and IndexNow.
  The list route pages (`page`, `per_page` ≤ 500, ID order) and always says
  `total` and `pages`.
- `not_found`: `template_redirect` at 99, front end, GET and HEAD only, not a
  410 the redirects module sent. `is_noise()` drops probes; the referrer is
  kept as scheme, host and path. Pruned at most daily by age then by count.
- `indexnow`: triggers on publish, update while published, and trash from
  published, for the chosen post types; the key is made on first boot unless
  one is stored (`source` `generated`, or `import` for a key taken over
  before 0.5.0), served on `parse_request`. URLs collected per request,
  drained into a single cron event at shutdown (`tgdsk_indexnow_send`), never
  sent inline. **`is_production()` gates every send**: environment type
  `production` and a host not ending .local, .test or .localhost; the
  `tgdsk_indexnow_is_production` filter is for tests with a stubbed endpoint
  only. History in `tgdsk_indexnow_log` (100 entries, 90 days).
- **0.6.0 features are all off by default**, each its own switch, because each
  changes what a site sends or counts. With them off, a site's pages must stay
  byte-identical to 0.5 (tested on Ohio PC with every module on).
  - Analytics (`includes/analytics/extras.php`): `consent_default` prints the
    consent default at `wp_head` -100 into each layer the kit's tags read
    (`tgdskDataLayer` for GA4, `dataLayer` for the container), and makes the
    container's head script push onto `dataLayer` instead of `var dataLayer =
    [...]`, which would drop it. `tap_events` prints one delegated capture-phase
    click listener at `wp_head` 11; names `tap_phone_event`, `tap_email_event`;
    parameters `link` and `page_path` only.
  - SEO: `general.pagination_links` (`pagination_links()` in output.php, used
    when the entity answers none; Shop_Entity answers its own, as in 0.5.1).
    Never on search; none on a split post while core's
    `adjacent_posts_rel_link_wp_head` is hooked. `sitemap.extra_urls`
    (`includes/seo/sitemap-extra.php`, provider `Extra_Sitemap_Provider`
    registered on `wp_sitemaps_init` only when the list isn't empty), each URL
    fetched once when saved (no redirects; non-200 refused; a failed request
    kept with a warning). Route `/tgdsk/v1/seo/sitemap-extra`, version = md5 of
    the list. Sitemap exclusions now also cover `canonical_elsewhere_ids()`
    (own canonical meta that isn't the item's link, posts and terms) and
    `Woo\private_page_ids()`; the post entity says noindex for the latter.
    Author archives use `author_archive_name()` / `author_archive_title()` (the
    site name when the display name is unsafe) and drop core's author feed
    link there (`feed_links_extra_show_author_feed`).
- `security` (0.6.0, `includes/modules/security.php`): four parts in
  `tgdsk_security`, each off by default. XML-RPC off exits with 403 from boot
  when `XMLRPC_REQUEST`. User enumeration: the `request` filter turns a
  logged-out `?author=` into a 404; `rest_pre_dispatch` refuses `/wp/v2/users*`
  without a user. Headers on `send_headers` and `login_init`, skipping any name
  already in `headers_list()`. Login limiting: `authenticate` at 99 refuses a
  locked address and rewrites invalid_username / invalid_email /
  incorrect_password into one `tgdsk_login_failed`; `wp_login_failed` counts
  only that code, only off REST and XML-RPC (`is_form_login()`), keyed by
  `hash_hmac( 'sha256', REMOTE_ADDR, wp_salt( 'auth' ) )`; `wp_login` clears.
  **Never count or lock REST or application passwords**: every site's tools
  reach the site that way.
  - **Headers from the server (0.6.1, `includes/security/server-headers.php`).**
    On Apache the same headers go into the root `.htaccess` between
    `# BEGIN/END TGD Site Kit security headers`, inside
    `<IfModule mod_headers.c>`, each as `Header unset X` + `Header always set
    X "v"` (the unset clears PHP's and core's copy in the normal table, the
    always-set covers every response; tested once each on cached, uncached,
    login, static and 404). PHP keeps sending its copies. The block is
    written and removed by `with_server_block()` byte for byte, **not**
    `insert_with_markers()` (which rewrites every line and its line endings).
    `sync_server_headers()` runs on any write of `tgdsk_security` or
    `tgdsk_modules` (queued to shutdown), on the tab's save and "Check
    again", and as a safety net on `admin_init` once a block has ever been
    written; deactivation removes it (`remove_server_block()`), uninstall.php
    too. Taking the block out is always allowed; writing needs Apache (from
    the request; under WP-CLI the last web request's answer is used), a
    writable file and no multisite. After a write, `check_server_headers()`
    fetches the home page with a one-time token (`tgdsk_header_check`) that
    makes PHP hold its copies back, so what comes back is the server's alone.
    State in `tgdsk_security_server` (deleted when nothing is wanted or
    written), a pre-first-write copy in `tgdsk_security_htaccess_backup`.
    Status route: `kit.health.security_headers`.
- `breadcrumbs`: `[tgdsk_breadcrumbs]` and the `tgd-site-kit/breadcrumbs`
  block (plain-JS editor script in `assets/`, server-rendered).
  `Breadcrumbs\post_trail()` builds the schema module's BreadcrumbList too:
  **change the trail there, never in graph.php.** The home label is sitewide
  only, so the visible trail and the graph can't differ.

## Testing a switch

The test that matters is the rendered page, not the stored data. The tools in
`tools/` snapshot every published URL's head (`head-snapshot.php`) or JSON-LD
(`schema-snapshot.php`) as an anonymous visitor and diff two snapshots. Capture
the old version's output first, switch, capture again, and account for every
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

# Changelog

## 0.4.2 (2026-11-06)

Tested on an Elementor site (Hello Elementor, Elementor 4.3.6, Elementor Pro
4.3.3, SmartCrawl 3.17.1).

- **Elementor pages: the generated description's render no longer changes
  the page.** The layout's text is rendered in the head on the first request
  after a layout change. WordPress counted the images in that render as
  content images, so on that one request the page's first eagerly loaded
  image was lazy-loaded instead (seen on two of three pages checked). Image
  loading attributes are now short-circuited during the render; a page's
  first and later requests are byte-identical.
- **Elementor pages: dynamic tags are left out of the generated text**, as
  Elementor's editor save leaves them out of post_content. Before, a heading
  showing the post title through a dynamic tag put the title at the start of
  the generated description. The cached texts from 0.4.1 rebuild once.
- Checked, no change needed: titles under Hello Elementor and GeneratePress
  (one `<title>`, the same on every page); `[tgdsk_breadcrumbs]` in a
  Shortcode widget, a Theme Builder single template and the editor's widget
  render, each matching the BreadcrumbList; the schema graph beside
  Elementor's FAQ JSON-LD with the element cache on and either CSS print
  method; a route write shows on the next request, nothing to purge.

## 0.4.1 (2026-11-06)

Includes everything in 0.4.0, which was never released.

- **IndexNow module.** Pings IndexNow when a post or page of the chosen
  types is published, updated while published, or trashed (SmartCrawl's
  triggers and post types), and serves `/{key}.txt`. Takes SmartCrawl's key
  and post types, so the engines keep trusting the key; makes a key only on
  a site with none. URLs are collected during the request and sent by a
  one-off cron event, so a save never waits. **Never sends from a
  non-production site** (environment type not `production`, or a `.local`,
  `.test` or `.localhost` host), and says so on its screen. Silent while
  SmartCrawl's instant indexing is on. A history of the last 100 pings (90
  days) with response codes, on its screen and at `GET /tgdsk/v1/indexnow`.
- **Redirects pass the query on by default.** A new redirect (screen, route)
  defaults to `pass`, so `/old/?fbclid=...` still redirects, with its query.
  The Redirection and SmartCrawl imports switch an `exact` redirect whose
  source has no query to `pass` (on by default; `--keep-exact` or the
  checkbox keeps it) and report how many. A source that names a query is
  left as it is.
- **Robots directives are stored as entered.** The SEO box no longer runs
  "other robots directives" through `sanitize_key()`, which turned
  `max-snippet:-1` into `max-snippet-1`. An allowlist (noarchive, nocache,
  nosnippet, noimageindex, notranslate, indexifembedded, and
  `max-snippet:N`, `max-image-preview:none|standard|large`,
  `max-video-preview:N`, `unavailable_after:` an ISO date) is checked by the
  box and the route alike; anything else is refused, the stored value kept
  and the reason shown. A `name:value` directive replaces core's value for
  the same name in the robots tag (`max-image-preview`) instead of printing
  beside it.
- **Elementor pages.** On a page built with Elementor, the generated
  description and the first-image fallback come from the page's current
  layout (Elementor's own plain-text rendering of `_elementor_data`, cached
  against the layout's hash), not from the plain-text copy in post_content,
  which a REST write to the layout leaves stale.
- The SEO route's `renders` gains `description_source` (own, template or
  generated), `elementor`, and `notes`: a reminder to set a description on
  an Elementor page that has none, and the active page cache plugin to purge
  before checking the head.

## 0.4.0 (2026-11-06, not released)

- **Jarvis write routes.** `GET` and `POST /tgdsk/v1/seo/post/<id>` and
  `/seo/term/<id>`: an object's SEO title, description, canonical, robots,
  Open Graph and Twitter values and, for posts, the schema module's per-page
  fields. A write names the `version` it read (or each field's current
  value) and is refused with 409 if anything changed since; values are
  validated as the screens validate them and refused rather than altered
  where cleaning would change their meaning. The answer is the stored values,
  read back, and what the page will print. Administrators only, like
  `/status`. Part of the connector module.
- **Redirects module.** Exact and regex sources; 301, 302, 307, 308 and 410;
  query exact, ignored or passed on; case and trailing slash per redirect;
  hit counts and the last hit. Imports from Redirection and SmartCrawl
  (copies, never deletes, safe to rerun, reports what it couldn't carry).
  Never redirects wp-admin, login, REST, XML-RPC, cron, or a URL that is
  published content, and says why on save. Sends nothing while Redirection
  is active or SmartCrawl's redirects are on. Routes: `/tgdsk/v1/redirects`
  and `/redirects/<id>` (no delete; switch one off).
- **404 log module.** Front-end 404s by path with the last referrer (host
  and path only), count, first and last seen; no IP addresses or user
  agents; bot probes ignored; capped by rows and age. `GET /tgdsk/v1/not-found`.
- **Breadcrumbs module.** `[tgdsk_breadcrumbs]` and a block, printing the
  same trail as the schema module's BreadcrumbList (now built by the same
  function). Settings: home label (shared with the schema graph), separator,
  current page.
- The kit's first database tables (`tgdsk_redirects`, `tgdsk_not_found`),
  created when their module is first switched on. Uninstall drops the 404
  log and keeps the redirects, as it keeps SEO meta.

## 0.3.1 (2026-11-04)

- **The client portal is no longer part of the kit.** It moved to its own
  plugin, which runs only on thisgals.com; client sites don't carry it. The
  kit's code is the same as 0.2.5 apart from the version number.
- 0.3.0, which added the portal as a module, was never released or installed
  on any site, so there is nothing to clean up on upgrade: no options, role,
  page or upload folder from it exist anywhere but a local test copy.
- New: this changelog.

## 0.3.0 (not released)

- Client portal module. Moved out in 0.3.1 before release.

## 0.2.5 (2026-10-30)

- Analytics: a conflict notice clears once the page it names renders without
  the conflict, instead of lasting until the settings were saved.

## 0.2.4 (2026-10-26)

- Schema: an archive's `@id` and `url` no longer carry the visitor's query
  string.
- Analytics: Beehive's Tag Manager container, with its data layer variables.
- Analytics: the `tgdsk_analytics_print` filter, so another plugin can keep
  the tags off its own pages.

## 0.2.3 (2026-10-26)

- Schema: business types from schema.org; blog posts can be credited to the
  business instead of the author.

## 0.2.2 (2026-10-25)

- Schema: an article and its author on blog posts; nothing on search pages;
  the conflict notice clears.

## 0.2.1 (2026-10-25)

- Schema: extra JSON properties for the business and for a page's own node.

## 0.2.0 (2026-10-25)

- Schema module.

## 0.1.x (2026-10-25)

- First release: the Jarvis connector, the analytics tag and SEO meta, and
  updates from this repository's GitHub releases, checked against SHA-256.

# Changelog

## 0.6.0 (2026-11-12)

Tap events, consent mode, pagination links, extra sitemap URLs and a
security module. Every new feature is off until it is switched on, because
each one changes what a site sends or counts; with them off, a site's pages
are byte-identical to 0.5.

- **Phone and email tap events** (Analytics): one delegated listener, inline
  after the tags, sends an event for each tap on a `tel:` or `mailto:` link,
  to the GA4 tag and onto the container's data layer, with the number or
  address (`link`) and `page_path` only. The event names are settings. It
  never delays the link.
- **Consent mode** (Analytics): Google's consent default (ad_storage,
  ad_user_data, ad_personalization and analytics_storage denied), with
  `wait_for_update` and optional regions, printed before the kit's GA4 tag
  and container, into the data layer each reads. The kit only sets the
  default; the site's cookie banner sends the update.
- **Pagination links** (SEO): `rel="prev"` and `rel="next"` on paginated
  archives (blog, terms, authors, dates, post type archives) and posts split
  with page breaks; never on search results, never a second set. Later pages
  already canonicalised to themselves, and still do.
- **Extra sitemap URLs** (SEO): a list of pages on this site WordPress
  doesn't know, in a sitemap of their own (`wp-sitemap-extra-1.xml`, in the
  index only while the list isn't empty). Same host, absolute URLs; a
  duplicate, a page WordPress knows, or anything that doesn't answer 200 when
  saved is refused. Readable and writable at `/tgdsk/v1/seo/sitemap-extra`,
  guarded by the list's version.
- **The sitemap leaves out anything whose canonical is another URL**, posts
  and terms alike (reason `canonical_elsewhere`).
- **WooCommerce's cart, checkout and account pages leave the sitemap** on
  their own (reason `shop_private`), and the SEO route reports their robots
  as noindex, as the page prints it.
- **Author archives never show an email address or a login** as the name:
  the title, the social titles, the schema's archive name and core's author
  feed link. The site's name stands in.
- **Security module**, four parts each switched on separately: XML-RPC off
  (403 for everyone, no pingback header or RSD link); user enumeration closed
  (`?author=N` a 404 for visitors, the users REST routes signed-in only, no
  users sitemap); security headers (nosniff, a referrer policy, same-origin
  framing, a permissions policy, and HSTS as its own opt-in), never sent twice;
  and login attempt limiting (5 failures in 15 minutes lock an address for 30,
  all settings), by the connection's own address, with one failure message
  for every wrong username or password. Application passwords and REST are
  never counted or locked out. Lockouts are listed, by a hash only, and
  cleared on the module's tab.

## 0.5.1 (2026-11-11)

WooCommerce support, author values that are never an email address, and two
carry-overs from 0.5.0. WooCommerce is platform support, like the page
builders: everything below runs only while WooCommerce is active, and adds
nothing on a site without it.

- **Products.** `og:type` is `product`, with `product:price:amount` and
  `product:price:currency` (a variable product's lowest price; nothing at
  all for a product that shows no price), `og:availability` and
  `product:availability` from the stock status, `product:brand` from
  WooCommerce's brand taxonomy and `product:retailer_item_id` from the SKU
  when there are any. No `article:*` tags on a product.
- **Product structured data.** The schema graph adds a Product (name,
  description, images, SKU, brand, and an Offer with price, currency,
  availability, URL and the business as seller; no price for a product
  without one) unless another Product is already on the page.
- **The shop page** takes its title, description, robots, canonical and
  social values from the shop page's own SEO values, then the product
  archive's template. Its later pages canonicalise to themselves and every
  page links its neighbours with `rel="prev"` and `rel="next"`.
- **A product category's share image** is its own thumbnail first.
- **robots.txt** disallows the cart, checkout and account pages (read from
  WooCommerce's page settings) and add-to-cart URLs, added to custom
  contents rather than replacing any. A switch on the SEO tab, on by default.
  Those pages stay noindex as WooCommerce marks them, and where robots
  values disagree, noindex and nofollow win: one tag never says both.
- **WooCommerce's own structured data isn't another graph.** The schema
  module prints its graph beside it; on single pages WooCommerce's
  breadcrumb list gives way to the graph's, which follows the same trail
  through the product's main category (archives keep WooCommerce's, as the
  graph prints none there). One BreadcrumbList per page.
- **The Schema tab lists JSON-LD from elsewhere**: where it is (a must-use
  plugin, Avada's code fields, Elementor Custom Code, a page), its types,
  and what it does to the kit's graph.
- **The business can have several types** (`OnlineStore, LocalBusiness`).
- **Author values**: `article:author`, the `%%name%%` macro and the
  schema's Person never print an email address or a login name. Then
  `article:author` is left out and the article credits the business.
- **Pages hidden by a "replaces a published page" redirect leave the
  sitemap** (sitemap reason `redirected`) and IndexNow doesn't send them.
- **The redirects route pages honestly**: `page` and `per_page` (100 by
  default, at most 500), and every answer says `total` and `pages` (also as
  `X-WP-Total` and `X-WP-TotalPages`).

## 0.5.0 (2026-11-11)

The kit stands on its own: the importers and the checks for other plugins
are gone, the connector is TGD Connect, and redirects work the way they
should rather than the way one plugin did them.

- **The importers are gone**: SEO, schema, redirects, analytics and the
  IndexNow key. What they brought in (SEO meta, schema settings, redirects,
  the IndexNow key) is the kit's own data and stays exactly as it is; their
  state, reports and dry runs are removed on upgrade.
- **The modules no longer step aside for other plugins.** SEO, schema,
  sitemaps, IndexNow, analytics and redirects print and send whatever else is
  active. The schema module still stays out next to another JSON-LD graph,
  and the analytics module still leaves its tag or container off a page that
  already loads the same one.
- **The tag check**, a Site Health test (also on the Analytics tab): fetches
  the home page as a logged-out visitor and reports a second copy of the
  kit's GA4 tag or container, any other Google tag or container, or the
  kit's tag missing. It runs only when asked.
- **The GA4 tag's data layer and command are the kit's own**:
  `tgdskDataLayer` and `tgdsk_ga()`. GA receives exactly what it did; this is
  the one change in what visitors' pages carry.
- **TGD Connect** is the connector's name everywhere a person sees it. The
  module id, the `tgdsk/v1` namespace and every route are unchanged.
- **Redirects can replace a published page, on purpose.** A redirect whose
  source is published content is refused unless it is marked "Replaces a
  published page" (`replaces_published` through the route); the error says
  so. For a regex the check runs against every published address it matches.
  The Redirects list names what each marked redirect hides. **Attachment
  pages never count as published content.**
- **A page published later at a redirect's address is shown**: an unmarked
  redirect whose source has become published content is skipped at request
  time and flagged on the Redirects tab. Matching moved from `init` to
  `wp_loaded`, so every post type is registered when that is checked; the
  check runs only when a redirect matches.
- **Automatic redirects** when a published post, page, public custom type or
  term changes address (slug, parent, anything in its permalink), with its
  child pages and child terms. Never from published content, never a loop, a
  redirect that pointed at the old address is repointed, origin "Automatic".
  The editor, quick edit, REST and WP-CLI alike. On by default, including on
  upgrade; a switch on the Redirects tab.
- **Chains and loops** among the site's own redirects listed on the Redirects
  tab, with "Point straight at the end" per chain and for all of them. Regex
  redirects with a backreference are left out and counted. Saving a redirect
  that would close a loop through other exact redirects is refused.
- **CSV import and export**, with a dry run that answers exactly as the import
  will, formula-looking cells escaped on export and refused as addresses on
  import, and the common column names other tools use accepted.
- **Fixed: a regex redirect passing the query on sent it twice**
  (`?a=1&a=1`) when its pattern didn't consume the query.
- **Stored origins** that named a plugin or the old connector read `import`
  and `connect` after the upgrade ("Imported", "TGD Connect" on the screen).
- **Health in the status route** (`kit.health`): the last IndexNow
  submission, redirect and 404 counts, the automatic-redirect switch, the tag
  check's last result, and the problems modules record. The list of known
  page cache plugins is gone from the SEO route's notes; it says when
  WordPress's page cache switch is on instead.
- **Upgrades run on the first request after a new version arrives**, however
  it arrived (stored as `tgdsk_version`).

## 0.4.4 (2026-11-11)

On an Avada site, Portfolio items were missing from the sitemap for
logged-out visitors, search engines included, while an administrator saw
them listed. The cause wasn't the excluded-posts list that 0.4.3 made
visible. The sitemap only mapped types with an admin screen, and read that
from `show_ui` at request time. Avada's role manager makes `show_ui` per
user for Portfolio and FAQs: true for an administrator, false for a
logged-out visitor and for a REST request signed in with an application
password.

- **The sitemap is the same whoever asks.** A type or taxonomy has an admin
  screen when `show_ui` is on, or when it was registered with one before
  Avada's role manager made it per user. Avada's builder taxonomies, which
  have no admin screen for anyone, stay out as before. On an Avada site with
  Portfolio or FAQ items published, their sitemaps now appear unless the type
  is ticked under "Leave out post types".
- **The SEO route's `in_sitemap` describes what a logged-out visitor gets**,
  for the REST user and an administrator alike. The `type_not_mapped` text
  reads "registered with no admin screen" and says the kit decides it the
  same way for every visitor.

## 0.4.3 (2026-11-11)

The first live switch of a site's SEO to the kit left a post type out of
the sitemap after it had been unticked under "Leave out post types", most
likely because the import had carried a list of left-out post IDs nothing
showed. That list is now visible and editable.

- **Posts left out of the sitemap are listed on the SEO tab**, under
  Sitemap: title (linked to the editor), type, status and ID, each with a
  box to put it back. An ID whose post no longer exists shows as missing and
  can be removed. The list saves on its own form and writes only that list.
- **"Leave out of the sitemap" in each post's SEO box**, with a line saying
  whether the post is in the sitemap now and, if not, why.
- **The SEO route reports the sitemap.** `GET /tgdsk/v1/seo/post/<id>`
  answers `in_sitemap` and `sitemap_reason` (`type_excluded`,
  `post_excluded`, `noindex`, `type_not_mapped`, `sitemaps_off`,
  `not_published` or `other`), worked out with core's own sitemap query.
  `values.sitemap_exclude` is written like any other field, guarded by the
  version. Every post's `version` changes once, because it now covers
  `sitemap_exclude`.
- **The import report covered the sitemap** (removed in 0.5.0): the posts it
  carried into the list, and what it couldn't carry (left-out URLs and extra
  URLs).
- **`renders` said when it wasn't what's printed**, while another SEO plugin
  was active (removed in 0.5.0 with the check for it).

## 0.4.2 (2026-11-06)

Tested on an Elementor site (Hello Elementor, Elementor 4.3.6, Elementor Pro
4.3.3).

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
  types is published, updated while published, or trashed, and serves
  `/{key}.txt`. Kept an existing key, so the engines keep trusting it; makes
  a key only on a site with none. URLs are collected during the request and
  sent by a one-off cron event, so a save never waits. **Never sends from a
  non-production site** (environment type not `production`, or a `.local`,
  `.test` or `.localhost` host), and says so on its screen. A history of the
  last 100 pings (90 days) with response codes, on its screen and at
  `GET /tgdsk/v1/indexnow`.
- **Redirects pass the query on by default.** A new redirect (screen, route)
  defaults to `pass`, so `/old/?fbclid=...` still redirects, with its query.
  The imports switched an `exact` redirect whose source has no query to
  `pass` (on by default) and reported how many. A source that names a query
  is left as it is.
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
  an Elementor page that has none, and a page cache to purge before checking
  the head.

## 0.4.0 (2026-11-06, not released)

- **Connector write routes.** `GET` and `POST /tgdsk/v1/seo/post/<id>` and
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
  hit counts and the last hit. Imports (copies, never deletes, safe to rerun,
  reports what it couldn't carry). Never redirects wp-admin, login, REST,
  XML-RPC, cron, or a URL that is published content, and says why on save.
  Routes: `/tgdsk/v1/redirects` and `/redirects/<id>` (no delete; switch one
  off).
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
- Analytics: a Tag Manager container, with its data layer variables.
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

- First release: the connector, the analytics tag and SEO meta, and updates
  from this repository's GitHub releases, checked against SHA-256.

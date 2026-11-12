# TGD Site Kit

A WordPress plugin from [This Gal's Design](https://thisgals.com): the on-site
SEO and site-health pieces we put on the sites we look after, in one plugin,
with each part switched on per site.

**Every module is off when the plugin is activated.** Activating it changes
nothing on the front end until a module is switched on under
Settings → TGD Site Kit (administrators only).

## Modules

| Module | What it does |
|---|---|
| TGD Connect | REST routes for administrators, used by This Gal's Design's site tools. `GET /wp-json/tgdsk/v1/status`: WordPress, PHP, theme and plugin versions, which updates are waiting, auto-update settings, which kit modules are on, and how they are doing (`kit.health`). `GET`/`POST /tgdsk/v1/seo/post/<id>` and `/seo/term/<id>`: one object's SEO and schema values, written only against the version last read, answered with what the page will print. With the redirects, 404 log and IndexNow modules on, their routes too. |
| Analytics tag | Prints the GA4 tag and a Google Tag Manager container (with data layer variables). Either may be set alone. Leaves out the tag or the container on a page that already loads the same one, and a Site Health test reports any second copy or other Google tag on the home page. |
| SEO meta | Titles, meta descriptions, canonical, robots, Open Graph and Twitter cards, per post, per term and as site defaults; WordPress's own sitemap, with the common sitemap addresses (`/sitemap.xml` and the rest) redirected to it and the posts it leaves out listed on the SEO tab and in each post's SEO box; robots.txt. |
| Schema | One JSON-LD `@graph` per page: the business (LocalBusiness or a subtype, with address, hours, areas served and profiles), the website, a typed page node with breadcrumbs, an article on blog posts credited to the post's author or to the business (BlogPosting by default; a site upgraded from 0.2.1 stays off until a type is picked), services on the pages that carry them, extra nodes per page, and extra properties as JSON for the business (sitewide) and for a page's own node. Prints nothing while a must-use plugin or Avada's code fields carry JSON-LD, and leaves a page's own breadcrumbs or page node to the theme when it already prints one. The business may have several types (`OnlineStore, LocalBusiness`). The Schema tab lists JSON-LD from elsewhere, with its types and what it does to the kit's graph. |
| Redirects | Exact and regex redirects (301, 302, 307, 308, 410), query handling, hit counts. Automatic redirects when a published address changes, chains and loops listed with a fix, CSV import and export. Never redirects WordPress's own paths; redirects a published page only when the redirect says it replaces one. |
| 404 log | Front-end 404s by path, with the last referrer, count and dates, for turning real broken links into redirects. No IP addresses or user agents; bot probes ignored; storage capped. |
| IndexNow | Tells Bing and the other IndexNow engines when a post or page is published, updated or trashed, and serves the key file. Never sends from a local or staging site. |
| Breadcrumbs | A `[tgdsk_breadcrumbs]` shortcode and a block that print the same trail as the schema module's breadcrumbs. Theme and builder agnostic (Avada elements, Elementor's Shortcode widget, widgets, blocks). |
| Security | Four parts, each switched on separately: XML-RPC off, user enumeration closed, security headers (with HSTS as its own opt-in), and login attempt limiting. None of them touches REST or application passwords. |

**Every feature added since 0.6.0 is off until it is switched on** (tap
events, consent mode, pagination links, extra sitemap URLs, each security
part), because each one changes what a site sends or counts.

### WooCommerce

The kit supports WooCommerce the way it supports the page builders. All of
this runs only while WooCommerce is active; a site without it gets nothing
new.

- **Products**: `og:type` `product`, with price and currency (a variable
  product's lowest price; none at all for a product that shows no price),
  availability, brand (WooCommerce's brand taxonomy) and SKU
  (`product:retailer_item_id`), and no `article:*` tags. The schema graph
  adds a Product with an Offer, unless another Product is already on the
  page.
- **The shop page** uses the shop page's own SEO values, then the product
  archive's template; later pages canonicalise to themselves, and each page
  links its neighbours (`rel="prev"`, `rel="next"`).
- **Product categories** behave like other taxonomy archives, and a
  category's share image is its own thumbnail first.
- **robots.txt** keeps crawlers out of the cart, checkout and account pages
  (read from WooCommerce's settings, so a renamed page is still covered) and
  add-to-cart links, added to custom contents rather than replacing them; a
  switch on the SEO tab, on by default. WooCommerce already marks those
  pages noindex, and the kit never contradicts it.
- **Structured data**: WooCommerce's own product and breadcrumb data is
  printed beside the kit's graph, not counted as another graph; its
  breadcrumb list gives way to the kit's, which follows the same trail
  through the product's main category, so a page has one.

Switching a module off stops its output and keeps its settings. Uninstalling
deletes the plugin's own options and the 404 log, and leaves post, term and
user meta and the redirects table alone, because SEO values and redirects
are part of a site's content.

### The write routes

Every write is guarded and validated:

- **SEO** (`POST /tgdsk/v1/seo/post/<id>`, `/seo/term/<id>`): send
  `values` (field => value; `null` removes one) with `expected_version`
  from a `GET`, or `expected` with each field's current value. A change
  made since is refused with 409 and nothing is written. Fields: `title`,
  `description`, `canonical`, `noindex`, `nofollow`, `opengraph`,
  `twitter`; for posts also `index`, `follow`, `robots_adv`,
  `schema_type`, `schema_brand`, `schema_page`, `schema_extra`,
  `sitemap_exclude` and, on blog posts, `schema_article`; for terms
  `override_noindex` and `override_nofollow`. The answer carries the stored
  values, the new version, which fields the cleaning changed (`adjusted`),
  and `renders`: the title, description, canonical, robots and social tags
  the page will print, and whose tags those are (`printed_by`: `kit` or
  `none`).
- **Author values**: `article:author`, the `%%name%%` macro and the schema's
  Person use the author's display name only when it is neither an email
  address nor the login name. Otherwise `article:author` is left out and the
  article credits the business.
- **Redirects** (`GET /tgdsk/v1/redirects?page=&per_page=`, 100 a page by
  default and at most 500, in ID order; every answer says `total` and
  `pages`, also as `X-WP-Total` and `X-WP-TotalPages`, so a caller can tell it
  hasn't seen them all. `POST /tgdsk/v1/redirects`, `/redirects/<id>`): a change
  needs `expected_modified_gmt` from a read. There is no delete; set
  `enabled` to false. A new redirect's `query_mode` defaults to `pass`: the
  query is ignored for matching and handed on to the target, so a link with
  `?fbclid=` or `?utm_source=` still redirects. `replaces_published: true`
  lets a redirect hide a published page on purpose (see below); the answer's
  `redirect.hides` lists what it hides. Redirects made through the route are
  listed as "TGD Connect".

`robots_adv` takes the directives Google and Bing document: noarchive,
nocache, nosnippet, noimageindex, notranslate, indexifembedded,
`max-snippet:N`, `max-image-preview:none|standard|large`,
`max-video-preview:N` and `unavailable_after:YYYY-MM-DD` (or an ISO date and
time). They are stored as entered; anything else is refused, in the SEO box
and through the route.

`renders.description_source` says where the description comes from (`own`,
`template` or `generated`). On a page built with Elementor a generated
description comes from the page's current layout, not from the plain-text
copy Elementor keeps in post_content (which a REST write to the layout leaves
stale), rendered the way Elementor's own editor save renders it; `renders.notes`
says so, and says when a page cache is on (`WP_CACHE`) so the page should be
purged before checking the live head. Elementor's own caches don't hold the
head, so a route write shows on the next request.

### The status route's health

`kit.health` in `GET /tgdsk/v1/status` reads only what the modules already
record; it never fetches anything. A module that is off answers `null`.

- `sitemap_ping`: always `null`. The kit doesn't ping search engines about
  the sitemap (Google and Bing retired their ping endpoints in 2023); the
  sitemap is found through robots.txt and Search Console.
- `indexnow`: the last submission (time, HTTP code, URL count, note).
- `redirects`: counts by origin, whether automatic redirects are on, and how
  many redirects were skipped because their source is published now.
- `not_found`: paths logged and their hits.
- `analytics_tag_check`: the tag check's last result.
- `errors`: problems a module has recorded and still shows on its tab (a
  tag or graph left out of a page next to another one, a failed IndexNow
  submission).

### The sitemap

**Extra URLs** (on the SEO tab, and at `GET`/`POST /tgdsk/v1/seo/sitemap-extra`
with `urls` and `expected_version`): pages on this site WordPress doesn't
know about, one absolute URL each, in a sitemap of their own
(`wp-sitemap-extra-1.xml`, listed in `/wp-sitemap.xml` only while the list
isn't empty). Another host, a `#fragment`, a duplicate, or a page WordPress
already knows is refused, and so is a URL that doesn't answer 200 when it is
saved (checked once, then, never when the sitemap is read; when the check
itself can't be made, the URL is kept and the save says so). Through the
route a list with any refused URL writes nothing.

**Pagination links** (General, off by default): `rel="prev"` and
`rel="next"` on paginated archives (the blog, categories, tags, authors,
dates, post type archives) and on posts split with page breaks; never on
search results. Each later page canonicalises to itself.

The SEO module's sitemap is WordPress's own (`/wp-sitemap.xml`). Besides
whole post types and taxonomies, it can leave out single posts. They are
listed under Sitemap on the SEO tab, with title, type, status and an edit
link, each with a box to put it back; an ID whose post was deleted shows as
missing and can be removed. A post's SEO box has the same switch ("Leave out
of the sitemap") and says whether the post is in the sitemap now, and why
not.

The sitemap only lists post types and taxonomies that have an admin screen,
so a theme's internal types (Avada's layout and library taxonomies) stay out.
Since 0.4.4 that is judged the same for every visitor: Avada's role manager
gives Portfolio and FAQs an admin screen only for a signed-in user allowed to
edit them, and the kit uses the value they were registered with before the
role manager stepped in. To leave either out, tick it under "Leave out post
types".

`GET /tgdsk/v1/seo/post/<id>` answers `in_sitemap` (whether WordPress's
sitemap lists the post now, asked of core's own sitemap query with every
plugin's filters) and, when it doesn't, `sitemap_reason`: `type_excluded`
(its type is ticked under "Leave out post types"), `post_excluded` (the post
is on the list), `noindex`, `type_not_mapped` (the type isn't in the sitemap:
not public, registered with no admin screen, or left out by WordPress or a
plugin), `canonical_elsewhere` (its own canonical is another URL; terms follow
the same rule), `shop_private` (WooCommerce's cart, checkout or account
page), `redirected` (a redirect marked "Replaces a published page" hides
it: such pages leave the sitemap, and IndexNow doesn't send them),
`sitemaps_off` (WordPress's sitemap is off: another plugin can switch it off,
and so does "Discourage search engines"), `not_published`, or `other`. `values.sitemap_exclude` is the post's place on the list, written
like any other field.

No route writes post content, users or plugins, and the only setting a route
writes is a post's place on the sitemap's list of posts left out.

### Redirects

- **A published page is never redirected by accident.** A redirect whose
  source is published content (a post, a page, a term archive, a post type
  archive, the front page) is refused unless it is marked **"Replaces a
  published page"**. With the mark it saves, and the Redirects list names
  what it hides (title and type; for a regex, how many and which). Attachment
  pages never count as published content.
- **A page published later at a redirect's address is shown.** An unmarked
  redirect whose source has become published content since it was saved is
  skipped when a visitor asks for that address, and the Redirects list says
  so. The check runs only when a redirect matches.
- **Automatic redirects.** When a published post, page or public custom type's
  address changes (its slug, its parent, anything in its permalink), the old
  address gets a 301 to the new one; child pages whose address changes with
  it get one too, and so do public taxonomy terms and their child terms.
  However the change was made: the editor, quick edit, REST, WP-CLI. Never
  from an address that is published content now, never a loop (renaming back
  removes the older automatic redirect instead), and a redirect that pointed
  at the old address is pointed at the new one, so a rename never leaves a
  chain. Listed as "Automatic". A switch on the Redirects tab, on by default.
- **Chains and loops.** The Redirects tab lists every chain among the site's
  own redirects (A to B, B to C) and every loop, with "Point straight at the
  end" for each chain and one button for all of them. Only the redirects in
  the chain are rewritten. Regex redirects with a backreference (`$1`) in the
  target are left out, and the tab says how many there are. Saving a redirect
  that would close a loop through other exact redirects is refused.
- **CSV.** Export writes every redirect (source, target, code, match,
  query, case_sensitive, trailing_slash, replaces_published, enabled, origin,
  note) as UTF-8, with cells that start like a spreadsheet formula escaped.
  Import needs a header row, matches columns by name (the common names other
  tools use are accepted too: from and to, old and new URL, status and so
  on), and shows a dry run first: every row it would add, skip or refuse, and
  why. Each row is checked as a redirect added by hand is; a duplicate keeps
  the first. The kit's own export imports back exactly.

### Breadcrumbs

`[tgdsk_breadcrumbs]`, or `[tgdsk_breadcrumbs separator="/" current="no"]`
for one place. The trail is home, the page's parents, the page; term and
post type archives get one too, the front page, search and 404 pages none.
The home label is a site setting only, because the schema graph's
BreadcrumbList uses it as well.

### Keeping the analytics tags off a plugin's own pages

Another plugin can stop the GA4 tag and the Tag Manager container printing on
its pages (a staff app, a kiosk screen) with the `tgdsk_analytics_print`
filter. It defaults to `true`; return `false` and neither prints on that
request, on the front end or in wp-admin. With no callback attached, nothing
changes.

```php
add_filter(
	'tgdsk_analytics_print',
	function ( $print ) {
		return $print && ! my_plugin_is_staff_screen();
	}
);
```

Role exclusion doesn't cover this case: the same people use the staff screens
and browse the public site.

### Tap events and consent mode

Both on the Analytics tab, both off by default, and switching either on is a
measurement change for the site.

- **Tap events**: a tap on a `tel:` or `mailto:` link sends one event, under
  the names set on the tab (so a site keeps the names it already uses), to
  the GA4 tag and onto the container's data layer, with two parameters:
  `link` (the number or address, without the scheme or a `?subject=`) and
  `page_path`. The listener never stops or delays the link. A container that
  also forwards these to the same GA4 property counts each tap twice.
- **Consent mode**: Google's consent default, with ad_storage, ad_user_data,
  ad_personalization and analytics_storage denied, `wait_for_update` in
  milliseconds and an optional list of regions (blank: everywhere), printed
  before the kit's tag and container into the data layer each reads. The kit
  sets only the default; the site's cookie banner must send the update.

### Security

Each part on the Security tab is off until it is ticked.

- **XML-RPC off**: `xmlrpc.php` answers 403 for everyone, pingbacks
  included, and the pingback header and the RSD link leave the head.
- **User enumeration**: `?author=N` is a 404 for a visitor, `/wp/v2/users`
  and `/wp/v2/users/<id>` need a signed-in user (an application password
  counts), and the users sitemap is left out. Author archives at their own
  address keep working.
- **Security headers**: `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options:
  SAMEORIGIN` and a `Permissions-Policy` with camera, microphone and
  geolocation off; HSTS separately, with its max-age as a setting. No
  Content-Security-Policy. **On Apache** (since 0.6.1) they also go into the
  root `.htaccess`, in one block between `# BEGIN TGD Site Kit security
  headers` and `# END ...`, so pages a cache serves without WordPress carry
  them too. Saving the tab writes, rewrites or removes the block (switching
  the module off or deactivating the plugin removes it), then checks with
  one request to the home page what the server sends; the tab and the status
  route say where the headers come from and when that was checked. Each
  header goes out once. The block isn't written when the server isn't Apache,
  the file isn't writable, or the site is in a multisite network: WordPress
  sends them alone, and for nginx the tab shows the lines to add. A copy of
  `.htaccess` from before the first write is kept in the option
  `tgdsk_security_htaccess_backup`.
- **Login attempts**: failed password logins through the login form are
  counted by the connection's own address (never a forwarded header): 5 in
  15 minutes lock it for 30, all three settings. A wrong username and a wrong
  password get the same message. Application passwords (REST) are never
  counted or locked out, and neither is XML-RPC, so turn XML-RPC off as well.
  Lockouts are listed on the tab by a hash of the address, never the address,
  and can be cleared there.

### The tag check

Tools → Site Health runs an "Analytics tags on the home page" test, and the
Analytics tab has a button for the same check: it fetches the home page as a
logged-out visitor and reports a second copy of the kit's GA4 tag or
container, any other Google tag or container, and the kit's own tag missing
where it should print. It runs only when asked, never on a visitor's
request. The GA4 tag uses its own data layer (`tgdskDataLayer`) and command
(`tgdsk_ga`), so it never collides with a Tag Manager container's.

## Requirements

WordPress 6.5 or later, PHP 8.1 or later. No other plugins are required.

## Installing

Download `tgd-site-kit.zip` from the latest release and upload it under
Plugins → Add New → Upload Plugin.

## Updates

Once installed, the kit updates like any plugin from wordpress.org: new
releases appear under Dashboard → Updates, and WordPress's own auto-update
toggle on the Plugins screen works for it.

- The kit checks this repository's latest GitHub release, without any token,
  at most every 12 hours (an hour after a failed check). It only offers final
  releases, never drafts or prereleases.
- Every release carries `tgd-site-kit.zip` and `tgd-site-kit.zip.sha256`.
  Before WordPress installs an update, the kit checks the zip against that
  SHA-256 and refuses it on any mismatch, so a swapped or corrupted asset is
  never installed.
- If GitHub can't be reached, nothing happens: no update is offered and no
  notice is shown.
- A copy with a `.git` folder (a development checkout) is never offered
  updates.
- Stored data that a new version reads differently is upgraded on the first
  request after the update, however the new version arrived (the updater, a
  manual upload, a copy dropped in place).

## Releasing

From a clean working tree on `main`:

1. Set the new version in both places in `tgd-site-kit.php` (the `Version:`
   header and the `VERSION` constant) and add it to `CHANGELOG.md`. Commit.
2. Build: `php tools/build-release.php vX.Y.Z`. It refuses to build if the
   tree has uncommitted changes or the versions don't match the tag, and
   writes `build/tgd-site-kit.zip` and `build/tgd-site-kit.zip.sha256`.
3. Tag and push: `git tag vX.Y.Z` and `git push origin main vX.Y.Z`.
4. Publish:

   ```
   gh release create vX.Y.Z build/tgd-site-kit.zip build/tgd-site-kit.zip.sha256 --repo thisgals/tgd-site-kit --title "X.Y.Z" --notes "What changed"
   ```

Sites see the release within 12 hours.

## License

GPL-2.0-or-later. See `LICENSE`.

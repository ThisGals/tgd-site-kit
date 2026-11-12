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
| Jarvis connector | REST routes for administrators. `GET /wp-json/tgdsk/v1/status`: WordPress, PHP, theme and plugin versions, which updates are waiting, auto-update settings, and which kit modules are on. `GET`/`POST /tgdsk/v1/seo/post/<id>` and `/seo/term/<id>`: one object's SEO and schema values, written only against the version last read, answered with what the page will print. With the redirects, 404 log and IndexNow modules on, their routes too. |
| Analytics tag | Prints the GA4 tag and a Google Tag Manager container (with Beehive's data layer variables), the same markup Beehive Analytics prints, with an import of Beehive's settings. Either may be set alone. Prints nothing while Beehive or Site Kit by Google is active, and leaves out the tag or the container on a page that already loads the same one. |
| SEO meta | Titles, meta descriptions, canonical, robots, Open Graph and Twitter cards, per post, per term and as site defaults; WordPress's own sitemap, with SmartCrawl's sitemap URLs redirected to it and the posts it leaves out listed on the SEO tab and in each post's SEO box; robots.txt. Imports from SmartCrawl and reproduces its output, so a site can switch without its head tags changing. Prints nothing while SmartCrawl is active. |
| Schema | One JSON-LD `@graph` per page: the business (LocalBusiness or a subtype, with address, hours, areas served and profiles), the website, a typed page node with breadcrumbs, an article on blog posts credited to the post's author or to the business (BlogPosting by default; a site upgraded from 0.2.1 stays off until a type is picked), services on the pages that carry them, extra nodes per page, and extra properties as JSON for the business (sitewide) and for a page's own node. Imports SmartCrawl's schema settings and a hand-written schema plugin's per-page fields. Prints nothing while SmartCrawl's schema or another JSON-LD graph is in charge, and leaves a page's own breadcrumbs or page node to the theme when it already prints one. |

| Redirects | Exact and regex redirects (301, 302, 307, 308, 410), query handling, hit counts. Imports from Redirection and SmartCrawl. Never redirects WordPress's own paths or a URL that is published content. Sends nothing while Redirection is active or SmartCrawl's redirects are on. |
| 404 log | Front-end 404s by path, with the last referrer, count and dates, for turning real broken links into redirects. No IP addresses or user agents; bot probes ignored; storage capped. |
| IndexNow | Tells Bing and the other IndexNow engines when a post or page is published, updated or trashed, and serves the key file. Uses SmartCrawl's key. Never sends from a local or staging site; silent while SmartCrawl's instant indexing is on. |
| Breadcrumbs | A `[tgdsk_breadcrumbs]` shortcode and a block that print the same trail as the schema module's breadcrumbs. Theme and builder agnostic (Avada elements, Elementor's Shortcode widget, widgets, blocks). |

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
  `sitemap_exclude` and, on blog posts, `schema_article`; for terms `override_noindex` and
  `override_nofollow`. The answer carries the stored values, the new
  version, which fields the cleaning changed (`adjusted`), and `renders`:
  the title, description, canonical, robots and social tags the page will
  print, and whose tags those are (`printed_by`: `kit`, `smartcrawl` or
  `none`). While SmartCrawl is active those are the kit's values, not
  SmartCrawl's, and `renders.notes` says so.
- **Redirects** (`POST /tgdsk/v1/redirects`, `/redirects/<id>`): a change
  needs `expected_modified_gmt` from a read. There is no delete; set
  `enabled` to false. A new redirect's `query_mode` defaults to `pass`: the
  query is ignored for matching and handed on to the target, so a link with
  `?fbclid=` or `?utm_source=` still redirects.

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
says so, and names any page cache plugin to purge before checking the live
head. Elementor's own caches don't hold the head, so a route write shows on
the next request.

### The sitemap

The SEO module's sitemap is WordPress's own (`/wp-sitemap.xml`). Besides
whole post types and taxonomies, it can leave out single posts: the import
copies SmartCrawl's ignored posts there. They are listed under Sitemap on the
SEO tab, with title, type, status and an edit link, each with a box to put it
back; an ID whose post was deleted shows as missing and can be removed. A
post's SEO box has the same switch ("Leave out of the sitemap") and says
whether the post is in the sitemap now, and why not.

Like SmartCrawl, the sitemap only lists post types and taxonomies that have
an admin screen, so a theme's internal types (Avada's layout and library
taxonomies) stay out. Since 0.4.4 that is judged the same for every visitor:
Avada's role manager gives Portfolio and FAQs an admin screen only for a
signed-in user allowed to edit them, and the kit uses the value they were
registered with before the role manager stepped in. Before 0.4.4, Portfolio
and FAQs were left out of the sitemap for logged-out visitors (search
engines included) while an administrator saw them listed. To leave either
out, tick it under "Leave out post types".

`GET /tgdsk/v1/seo/post/<id>` answers `in_sitemap` (whether WordPress's
sitemap lists the post now, asked of core's own sitemap query with every
plugin's filters) and, when it doesn't, `sitemap_reason`: `type_excluded`
(its type is ticked under "Leave out post types"), `post_excluded` (the post
is on the list), `noindex`, `type_not_mapped` (the type isn't in the sitemap:
not public, registered with no admin screen, or left out by WordPress or a
plugin),
`sitemaps_off` (WordPress's sitemap is off: SmartCrawl switches it off while
active, and so does "Discourage search engines"), `not_published`, or
`other`. `values.sitemap_exclude` is the post's place on the list, written
like any other field.

The import report lists the posts it carried by title, and counts what it
can't carry: SmartCrawl's ignored URLs and extra URLs, which WordPress's
sitemap has no place for.

No route writes post content, users or plugins, and the only setting a route
writes is a post's place on the sitemap's list of posts left out.

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

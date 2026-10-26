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
| Jarvis connector | A read-only REST route, `GET /wp-json/tgdsk/v1/status`, for administrators: WordPress, PHP, theme and plugin versions, which updates are waiting, auto-update settings, and which kit modules are on. |
| Analytics tag | Prints the GA4 tag in the head, the same tag Beehive Analytics prints, with an import of Beehive's settings. Prints nothing while Beehive, Site Kit by Google, or another tag for the same measurement ID is on the page. |
| SEO meta | Titles, meta descriptions, canonical, robots, Open Graph and Twitter cards, per post, per term and as site defaults; WordPress's own sitemap, with SmartCrawl's sitemap URLs redirected to it; robots.txt. Imports from SmartCrawl and reproduces its output, so a site can switch without its head tags changing. Prints nothing while SmartCrawl is active. |
| Schema | One JSON-LD `@graph` per page: the business (LocalBusiness or a subtype, with address, hours, areas served and profiles), the website, a typed page node with breadcrumbs, an article and its author on blog posts (BlogPosting by default; a site upgraded from 0.2.1 stays off until a type is picked), services on the pages that carry them, extra nodes per page, and extra properties as JSON for the business (sitewide) and for a page's own node. Imports SmartCrawl's schema settings and a hand-written schema plugin's per-page fields. Prints nothing while SmartCrawl's schema or another JSON-LD graph is in charge, and leaves a page's own breadcrumbs or page node to the theme when it already prints one. |

Switching a module off stops its output and keeps its settings. Uninstalling
deletes the plugin's own options and leaves post, term and user meta alone,
because SEO values are part of a site's content.

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

1. Set the new version in both places in `tgd-site-kit.php`: the `Version:`
   header and the `VERSION` constant. Commit.
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

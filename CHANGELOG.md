# Changelog

## 0.4.0 (2026-11-06)

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

# Changelog

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

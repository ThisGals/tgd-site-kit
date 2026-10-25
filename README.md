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

Switching a module off stops its output and keeps its settings. Uninstalling
deletes the plugin's own options and leaves post, term and user meta alone,
because SEO values are part of a site's content.

## Requirements

WordPress 6.5 or later, PHP 8.1 or later. No other plugins are required.

## Installing

Download `tgd-site-kit.zip` from the latest release and upload it under
Plugins → Add New → Upload Plugin.

## License

GPL-2.0-or-later. See `LICENSE`.

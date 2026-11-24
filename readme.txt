=== 404 Rescue ===
Contributors: glenramos0406
Tags: 404, redirects, broken links, seo, monitoring
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: https://paypal.me/glennramos0406

Records missing pages, suggests a page on your site, and lets you send visitors there.

== Description ==

404 Rescue watches for real missing pages and gives you a simple place to fix them.

When someone opens an address that does not exist, the plugin keeps one row for that address and counts later visits. It looks through your published posts and pages for a likely destination. A suggestion is only a hint. Visitors are not redirected until you save a rule.

You can add a redirect by typing the old address and the new one, turn it on or off, and import or export a CSV file. Redirects are checked for loops, for an address that points at itself, and for destinations that are not normal web addresses.

The plugin stores data in your own database. It does not call an outside service.

= What is stored =

* The missing path, without the query string.
* How many times it was visited, and the first and last time.
* The referring page, without its query string.
* A shortened browser string, so you can tell a person from a bot.

IP addresses are not stored.

= What is not recorded =

Visits to wp-admin, the REST API, AJAX, cron, the login screen, and common files such as images and CSS are ignored. You can add more addresses to ignore. A line of just `*` is rejected so one pattern cannot hide the whole site.

== Installation ==

1. Upload the `404-rescue` folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate 404 Rescue.
3. Open 404 Rescue in the admin menu.
4. Visit a made-up address on the site, then refresh the dashboard. The visit should be listed once, and a second visit should increase the count rather than add another row.
5. Open that row and choose Create redirect. Save it, then visit the old address again. You should land on the new page.

Deactivating the plugin keeps your logs and redirects. Data is removed only if you check "Delete all 404 Rescue data if I delete this plugin" and then delete the plugin.

== Frequently Asked Questions ==

= Will a suggestion redirect visitors by itself? =

No. A suggestion is a guess from titles and addresses already on your site. Open the page and save a redirect only if it is the right one.

= Does this replace an SEO plugin? =

No. It does not change titles, sitemaps, or schema. If another plugin also redirects the same address, 404 Rescue runs first.

= Why did a missing page not get logged? =

A full-page cache can answer a 404 without running WordPress. Exclude 404 responses from the cache if you want every visit recorded. Redirects also run in PHP, so a cached copy of the old address will keep serving the old response until that cache entry expires.

The log also ignores admin screens, REST and AJAX requests, cron, the login screen, and static files. Addresses you listed under Settings are ignored too. When the log reaches its maximum, new addresses wait until space is freed. Repeat visits to an address already in the log are still counted.

= Are email reports sent? =

No. The email setting is saved for a later version. This version does not send mail.

== Screenshots ==

1. Dashboard with visit counts and the last seven days.
2. The list of broken addresses, with a suggestion and a link to create a redirect.
3. The redirect form.
4. Settings for logging, retention, and data removal.

== Changelog ==

= 1.0.0 =
* First release. Logs missing pages, suggests a destination on your site, and manages redirects.

== Upgrade Notice ==

= 1.0.0 =
First release.

== Privacy ==

404 Rescue does not send site content or visitor details to an external server. Broken paths, visit counts, a query-free referrer, and a shortened browser string are stored in the local WordPress database. IP addresses are not stored. Redirect targets are whatever an administrator saves.

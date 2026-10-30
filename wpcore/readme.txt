=== WPCore Plugin Manager ===
Contributors: stueynet
Tags: site management, multiple sites, plugin manager, plugin collections, bulk install
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See your WordPress sites on WPCore.com with their versions, plugins and themes, and install the plugins of the collections assigned to each site.

== Description ==

WPCore.com lists all your WordPress sites on one page. Connect a site with this plugin, and its site page on WPCore.com shows the WordPress, PHP, database and web server versions, the plugins and themes with their versions, and the updates waiting.

On WPCore.com you also make collections. A collection is a list of plugins. Assign collections to a site on its site page. The WPCore screen on the site lists the plugins of the assigned collections and installs them.

= Features =

* One list of all your sites on WPCore.com.
* For each site: the WordPress, PHP, database and web server versions, and the plugins and themes with their versions and available updates.
* Collections of plugins from the WordPress.org directory, and custom plugins uploaded to WPCore.com as ZIP files.
* The WPCore screen installs, or installs and activates, the plugins of the assigned collections, and shows the result for each plugin.
* The plugin sends WPCore.com an update after a plugin or theme change. WPCore.com also requests an update from each site on a schedule.
* WP-CLI commands list and install the plugins of the assigned collections.

= Plans =

WPCore.com has a free plan and a Pro plan. The plan sets the number of sites, collections and plugins in each collection. Custom plugins need the Pro plan. The [WPCore.com help page](https://wpcore.com/help) lists the limits.

= Collection keys =

A site that is not connected lists the collections whose keys are saved on the WPCore screen. A later version of the plugin removes collection keys. Connect the site and assign its collections on WPCore.com instead. Connecting a site switches it from the saved keys to the assigned collections. Disconnecting the site switches it back to the saved keys.

= WP-CLI =

`wp wpcore list` lists the plugins of the collections assigned to the site and their status on the site. `wp wpcore install` installs them. On a site that is not connected, both commands take collection keys.

= Source code =

The plugin includes the uncompressed source of the admin screen JavaScript and styles in `assets/src/`. `build/` holds the compiled files.

= External service =

This plugin connects to the WPCore API at `https://wpcore.com/api/`.

When you save a connection key from the Sites page of wpcore.com, the plugin sends a site report to `https://wpcore.com/api/sites/`, and the response holds the collections assigned to the site. The plugin also sends the report when the WPCore screen needs the collections and the copy it holds is 5 minutes old, and when WP-CLI installs or lists them. WPCore.com also requests the report from the site on a schedule, at most once an hour. The report contains the WordPress version and available core update, the site title, the home URL, the admin URL, the language, the timezone, the environment type, whether the site is a multisite, the automatic update settings, whether the site uses HTTPS, whether it discourages search engines, the debug, file editing and WP-Cron settings, the time of the last change to a published post, the PHP version, the PHP memory limit, time limit and upload size limit, whether the site uses a persistent object cache, the database server and version, and the web server. For each installed plugin and theme, it contains the name, version and status. For each must-use plugin and drop-in, it contains the name and version. For each plugin and theme, it also contains whether WordPress.org hosts it, whether it updates automatically, and the version and requirements of an available update. For each theme, it also contains the parent theme and whether it is a block theme. The plugin sends no report until you save a connection key, and stops when you disconnect the site or deactivate the plugin. While the site is connected, the plugin also sends the report after a plugin or theme change, at most once a minute.

On a site that is not connected, the plugin reads the collections whose keys you add on the WPCore screen. The request contains the collection keys and the plugin version.

The [WPCore terms](https://wpcore.com/terms) and [privacy policy](https://wpcore.com/privacy) apply.

== Installation ==

1. In the WordPress admin, go to Plugins > Add New and search for "WPCore".
2. Install and activate WPCore Plugin Manager.
3. At [wpcore.com](https://wpcore.com), open Sites, click Add a site and enter the site's name and address.
4. On the site page, click Copy key.
5. In the WordPress admin, go to WPCore, paste the key into the Site connection box and click Connect. The site page on wpcore.com fills in within a few seconds.
6. On wpcore.com, create a collection and add plugins to it.
7. On the site page, open the Collections tab and assign the collection.
8. On the WPCore screen, click Install and activate missing, or select the plugins and click Install.

== Frequently Asked Questions ==

= Can WPCore.com change my site? =

No. WPCore.com reads the list of what is installed on the site and tells the plugin which collections are assigned to it. It cannot install, update, remove or change anything on the site. Plugins are installed only when you click Install on the WPCore screen.

= Who can request a report from my site? =

The plugin answers only requests signed with the site's connection key. The key is not a WordPress password and cannot be used to log in. To replace a key, click Reset key on the site page on wpcore.com and paste the new key into the WPCore screen.

= Does the plugin send data before I connect the site? =

No. The plugin sends no site report until you save a connection key.

= What happens when I disconnect the site or deactivate the plugin? =

The plugin tells WPCore.com that the site is disconnected and deletes the connection key. WPCore.com keeps the last report and stops requesting reports from the site. To connect the site again, paste the key again. To remove the site and its details from WPCore.com, delete the site on its site page.

= Can I use someone else's public collection? =

Clone it on wpcore.com, then assign your copy to the site. A connected site gets only collections that you own.

== Screenshots ==

1. The Sites page on wpcore.com lists each site's status, WordPress and PHP versions, plugin count and waiting updates.
2. A site page on wpcore.com shows the site's versions, and its plugins and themes with available updates.
3. The WPCore screen of a connected site lists the plugins of the collections assigned to it on wpcore.com.
4. Install and activate the missing plugins of a collection. Each plugin shows its result.

== Changelog ==

= 2.1.0 =
* Connect the site to WPCore.com in the new Site connection box. WPCore.com then shows the site's versions, plugins and themes, and the updates waiting.
* A connected site lists the collections assigned to it on WPCore.com instead of the saved collection keys.
* The plugin updates WPCore.com after you install, update, activate or remove a plugin or theme.
* With two or more collections, the WPCore screen shows an All tab and one tab per collection.
* WP-CLI commands on a connected site use the assigned collections.
* Deactivating the plugin disconnects the site from WPCore.com.
* Collection keys still work on a site that is not connected. A later version removes them.
* Removed the export links.

= 2.0.1 =
* The plugin includes the uncompressed source of the admin screen JavaScript and styles in `assets/src/`.

= 2.0.0 =
* Rebuilt the plugin.
* Removed the TGM Plugin Activation library.
* Each collection is cached for 12 hours. A key that matches no collection is cached for 1 hour.
* A saved key that matches no collection is listed as not found.
* Requests to wpcore.com verify the HTTPS certificate.
* Deleting the plugin removes its data from every site in a multisite network.
* Plugins install with the WordPress core plugin installer. Installed plugins are not downloaded again.
* New WPCore screen: add and remove collection keys, select plugins, install or install and activate them, and see the result for each plugin.
* Export links use plugin folder names, and the active plugins export includes network-activated plugins.

= 1.9.2 =
* Fixed a bug with PHP 8.

= 1.9.1 =
* Removed calls to screen_icon.

= 1.9.0 =
* Added WP-CLI support.

== Upgrade Notice ==

= 2.1.0 =
Connect the site to WPCore.com to see its versions, plugins and themes there and to assign collections to it. Your saved collection keys are kept. The plugin sends no site data until you save a connection key.

= 2.0.0 =
New WPCore screen in the same menu place. Your saved collection keys are kept.

=== WPCore Plugin Manager ===
Contributors: stueynet
Tags: plugin manager, plugin collections, bulk install, install plugins, wp-cli
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Install the plugins from your WPCore.com collections on any WordPress site.

== Description ==

WPCore stores lists of plugins called collections. You create collections at [wpcore.com](https://wpcore.com). Each collection has a key. Add the key to this plugin, and the plugin installs and activates the plugins in that collection.

Collections can contain plugins from the WordPress.org directory and custom plugins uploaded to wpcore.com as ZIP files.

= WP-CLI =

`wp wpcore install <key>` installs the plugins in a collection from the command line. `wp wpcore list <key>` lists the plugins in a collection and their status on the site.

= Source code =

The plugin includes the uncompressed source of the admin screen JavaScript and styles in `assets/src/`. `build/` holds the compiled files.

= External service =

This plugin connects to the WPCore API at `https://wpcore.com/api/` to read the collections whose keys you add. The request contains the collection keys and the plugin version. No other site data is sent. The [WPCore terms](https://wpcore.com/terms) and [privacy policy](https://wpcore.com/privacy) apply.

== Installation ==

1. In the WordPress admin, go to Plugins > Add New and search for "WPCore".
2. Install and activate WPCore Plugin Manager.
3. Create a collection at [wpcore.com](https://wpcore.com/collections/create) and add plugins to it.
4. Copy the collection key.
5. In the WordPress admin, go to WPCore and add the key.
6. Select the plugins to install.

== Screenshots ==

1. Create collections of plugins at wpcore.com.
2. The WPCore screen lists the plugins in each saved collection and shows which are installed and active.
3. Install and activate the missing plugins of a collection. Each plugin shows its result.

== Changelog ==

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

= 2.0.0 =
New WPCore screen in the same menu place. Your saved collection keys are kept.

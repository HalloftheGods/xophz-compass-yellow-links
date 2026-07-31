=== Xophz Yellow Links ===
Contributors: hallofthegods
Donate link: https://hallofthegods.com/
Tags: directory, yellow-links, gemini, AI, links
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 26.7.21
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Standalone WordPress backend and router for the Yellow Links web app.

== Description ==

Xophz Yellow Links provides the server-side architecture, custom content models, Gemini AI intelligence endpoints, and federated networking for the Yellow Links web app in the COMPASS ecosystem.

Key Features:
* Custom Post Type (`yellow_link`) and taxonomy (`yellow_link_category`) with REST API support.
* REST API endpoints for Gemini 3.5 Flash AI link analysis, category/tag suggestions, and safety assessments.
* Sister Sites Federation to share and aggregate link directories across multiple site installations.
* Frontend router serving the compiled Vue/Vite web app bundle or proxying to dev server.

== Installation ==

1. Upload `xophz-compass-yellow-links` to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure your Deployment Slug and Sister Sites under 'Settings > Yellow Links'.

== Frequently Asked Questions ==

= How do I configure Gemini AI features? =
Set the `GEMINI_API_KEY` environment variable in your server or Docker container environment.

= How does Sister Sites Federation work? =
Enter one URL per line under Settings > Yellow Links for other Yellow Links installations. The plugin will fetch and merge directory entries with automatic transient caching.

== Changelog ==

= 26.7.21 =
* Initial standalone release with REST API endpoints, custom post type, Gemini AI link analysis, and federated network routing.

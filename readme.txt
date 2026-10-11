=== AppNatively ===
Contributors: mdalaminbey, tanjimhasan, crafium
Tags: mobile app, app builder, native app, woocommerce mobile app, headless
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WordPress site into a native iOS and Android app. Syncs WooCommerce, FluentCart, SureCart, form and directory plugins.

== Description ==

AppNatively is a powerful, modern plugin for WordPress that bridges your website with the AppNatively mobile app builder. It automatically generates high-performance REST API endpoints for your WordPress data, enabling you to build premium, fast, and fully-synchronized native mobile applications.

Whether you run an online store, collect leads through forms, or publish a directory of listings, AppNatively detects the plugins you already have active and integrates deeply into your setup to power mobile cart operations, product catalogs, order tracking, form submissions, listings, and customer accounts natively.

=== Key Features ===
*   **AppNatively Integration:** Connect your site once, then design and customize your mobile app in [AppNatively Studio](https://appnatively.com/studio) with your live WordPress content.
*   **You stay in control:** A settings screen under **AppNatively** in your dashboard lets you switch the API off entirely, rotate the key that authorizes Studio, and sign out every app user at once — without deactivating the plugin.
*   **Automatic Plugin Detection:** AppNatively scans your site for supported plugins and lists only the integrations you have active, so you simply pick which one powers each category in your app — e-commerce, forms, and directory.
*   **Unified E-Commerce API:** Standardized endpoints for products, categories, shopping carts, and order details.
*   **WooCommerce, FluentCart & SureCart Support:** Full support for listing products, managing cart actions (add, update, remove, clear), and placing/retrieving orders.
*   **Form Integrations:** Render and submit your existing forms natively inside the app.
*   **Directory & Listing Integrations:** Bring listing directories to mobile with native browsing and detail screens.
*   **Extensible:** Developers can register additional integrations through the `craf_appna_integrated_plugins` filter.

=== Supported Integrations ===

**E-Commerce**

*   WooCommerce
*   Fluent Cart
*   SureCart

**Forms**

*   FormGent
*   Fluent Forms
*   Contact Form 7
*   WPForms
*   Ninja Forms
*   Formidable
*   Forminator
*   Everest Forms
*   SureForms
*   WeForms
*   HappyForms
*   Gutena Forms
*   Bit Form

**Directories**

*   Directorist
*   GeoDirectory
*   HivePress
*   Business Directory Plugin
*   Classified Listing
*   aDirectory

=== External Services ===

This plugin does not send your data anywhere on its own. It publishes REST endpoints on your own site that your mobile app and [AppNatively Studio](https://appnatively.com/studio) read from. You choose when to connect Studio by giving it the connection key from the settings screen, and you can revoke that key at any time.

== Installation ==

=== Minimum Requirements ===
*   WordPress 6.5 or higher
*   PHP 7.4 or higher
*   Pretty permalinks enabled (Settings &rarr; Permalinks), required by the REST API

=== Automatic Installation ===
1.  Log in to your WordPress dashboard and go to **Plugins &rarr; Add New**.
2.  Search for **AppNatively**.
3.  Click **Install Now**, then **Activate**.

=== Manual Installation ===
1.  Download the plugin `.zip` file.
2.  Go to **Plugins &rarr; Add New &rarr; Upload Plugin**, choose the file, and click **Install Now**.
3.  Click **Activate Plugin**.

Alternatively, unzip the file and upload the `appnatively` folder to `/wp-content/plugins/` via FTP, then activate it from the **Plugins** screen.

=== After Activation ===
1.  Make sure the plugins you want to use in your app (WooCommerce, your form plugin, your directory plugin, and so on) are installed and active on your site.
2.  In your WordPress dashboard, open **AppNatively** and copy your **connection key**.
3.  Visit [AppNatively Studio](https://appnatively.com/studio) and sign in.
4.  Open your app and go to **Settings &rarr; Website Connection**.
5.  Choose **WordPress** as the platform, enter your **Website URL** (for example, `https://yoursite.com`), and paste in the connection key.
6.  Click **Check Connection**. When the status badge shows **Connected**, click **Save Configuration**.
7.  In the **WordPress Plugins** card, pick which active plugin should power each category (e-commerce, form, directory), then click **Save Configuration**.
8.  Design your app in AppNatively Studio and publish it to iOS and Android.

If you activate a supported plugin after connecting, reload the **Website Connection** page so the new integration appears in the plugin list.

== Frequently Asked Questions ==

= Which e-commerce systems are supported? =
AppNatively supports WooCommerce, Fluent Cart, and SureCart.

= Which form plugins are supported? =
FormGent, Fluent Forms, Contact Form 7, WPForms, Ninja Forms, Formidable, Forminator, Everest Forms, SureForms, WeForms, HappyForms, Gutena Forms, and Bit Form.

= Which directory plugins are supported? =
Directorist, GeoDirectory, HivePress, Business Directory Plugin, Classified Listing, and aDirectory.

= How do I connect my site to AppNatively Studio? =
Open **AppNatively** in your dashboard and copy the connection key, then go to [AppNatively Studio](https://appnatively.com/studio), open your app, and visit **Settings &rarr; Website Connection**. Select **WordPress**, enter your site URL, paste the key, click **Check Connection**, and save.

= What is the connection key for? =
It authorizes AppNatively Studio to read which of your plugins are active, so it can offer you the right integrations. Without it that list is not exposed to anyone. Treat it like a password. You can issue a new one at any time from the settings screen — Studio will need the new key pasted in before it can read your site again.

= How do I turn the API off? =
Open **AppNatively** in your dashboard and uncheck **Answer requests from the mobile app**. Every endpoint stops responding immediately and your settings are kept, so you can switch it back on without reconfiguring anything.

= Someone has an app session I want to end. What do I do? =
Two options. Changing an account's password signs that one account out of the app everywhere. To end every session on the site at once, use **Sign out all app users** on the settings screen.

= Do I need to configure the integrations? =
Barely. AppNatively detects the supported plugins that are active on your site automatically. All you do is choose which one to use for each category on the **Website Connection** screen — handy when, for example, you have more than one form plugin installed.

= The plugin list is empty or my plugin is missing. What should I do? =
Confirm the connection first — the plugin list only loads once your site URL has been verified and Studio has a valid connection key. If the connection is fine, make sure the plugin is active on your site and that it appears in the supported integrations list above, then reload the **Website Connection** page.

= Can I add support for another plugin? =
Yes. Use the `craf_appna_integrated_plugins` filter to register your own plugin with a slug, category, label, and (if the plugin folder and main file names differ) a file path. Add its slug to the matching category with `craf_appna_ecommerce_integrations`, `craf_appna_form_integrations`, or `craf_appna_directory_integrations` so requests naming it are accepted.

= Where can I find the plugin repository? =
The source code and public repository for this plugin is available on GitHub at [AppNatively](https://github.com/appnatively/appnatively).

== Changelog ==
= 0.0.9 - 11 Oct 2026 =
* Support bearer authentication for Studio connection keys while retaining legacy header support.

= 0.0.8 - 27 Sep 2026 =
* Custom Post Type Support

= 0.0.7 - 19 Sep 2026 =
* Guard integration boot with is_loaded checks

= 0.0.6 - 16 Sep 2026 =
* Update form integrations data structure

= 0.0.5 - 12 Sep 2026 =
* Add multi-image support for directory listings

= 0.0.4 - 11 Sep 2026 =
* Add aDirectory directory integration

= 0.0.3 - 03 Sep 2026 =
* Added a clear introduction explaining how the AppNatively WordPress plugin connects with the AppNatively platform.
* Added direct links to AppNatively.com from the plugin settings page.

= 0.0.2 - 31 Aug 2026 =
* Add coupons, discounts, reviews, related products rest api

= 0.0.1 - 25 Aug 2026 =
* Initial release.

=== EzImport for WooCommerce ===
Tags: woocommerce, import, xml, csv, products
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import WooCommerce products from CSV, Excel, or XML with field mapping, images, and scheduled updates.

== Description ==

EzImport imports WooCommerce products from uploaded CSV, XLS, XLSX, and XML files or from a remote URL. Preview the source, map columns or XML elements to product fields, and choose which fields to update when matching products already exist.

Features include:

* Drag-and-drop mapping for product title, description, SKU, prices, stock, dimensions, categories, and images.
* Unique identifiers for updating existing products.
* Saved import profiles and per-run statistics.
* Resumable batch processing for large imports.
* Cron URLs for scheduled URL imports and change detection.

WooCommerce is required. For support and source code, visit https://github.com/danaki/wpezimport.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the EzImport ZIP through Plugins > Add New > Upload Plugin, or install it from the WordPress plugin directory when available.
3. Activate EzImport for WooCommerce.
4. Open EzImport in the admin menu to create an import.

== Frequently Asked Questions ==

= Can I import from a remote URL? =

Yes. Enter a URL to a supported source file when creating an import. Saved URL imports can be updated with the cron URLs shown on the Scheduling Options page.

= How are existing products matched? =

Choose a unique identifier in the mapping step. On later runs, the identifier is used to find an existing product, and the Update? settings determine which mapped fields may change.

== Changelog ==

= 1.0.0 =

* Initial release with CSV, XLS, XLSX, and XML product imports, field mapping, images, batch processing, and scheduling.

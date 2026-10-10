# EzImport

A free WordPress plugin that imports WooCommerce products from Excel files (CSV, XLS, XLSX) or XML feeds — via file upload or a remote URL — with drag-and-drop field mapping, automatic image importing and cron-based scheduled updates.

**Website:** [wpezimport.com](https://wpezimport.com)

## Features

- **Multiple formats** — CSV, XLS, XLSX (via PhpSpreadsheet) and XML
- **Two sources** — upload a file from your computer or pull a feed from a remote URL
- **Step-by-step wizard** — upload → pick data → map fields → confirm → run
- **File preview** — see your data before anything is imported
- **Smart XML support** — pick the repeating item element from a visual XML tree, browse real product samples, and build field values with XPath patterns like `{price}/@{currency}`
- **Drag-and-drop mapping** — drag file columns or XML elements onto product fields
- **Full WooCommerce field coverage** — title, description, brand, SKU, GTIN/UPC/EAN/ISBN, regular & sale price, stock quantity, weight (kg/g), dimensions L×W×H (m/cm/mm), categories, up to 4 images per product
- **Update existing products** — a unique identifier (e.g. SKU) matches feed items to existing products; per-field *Update?* toggles control exactly which fields get overwritten
- **Automatic image import** — images are downloaded and attached; the first becomes the featured image, the rest go to the product gallery. Downloaded images are deduplicated across runs
- **Saved import profiles** — manage, edit, re-run or delete import configurations from one list
- **Scheduled imports** — cron-ready trigger/process/cancel URLs with a secret key; change detection via ETag / Last-Modified / content hash so feeds are re-imported only when they actually change
- **Batch processing** — resumable batches sized to your server's time limit: products first, images second. No timeouts on large catalogs
- **Import statistics** — per-run history with created / updated / error counts
- **Import options** — default post status (publish/draft/pending), default author, skip header row, "only match existing categories" mode

## Requirements

- WordPress 5.0 or higher
- **WooCommerce** installed and activated (imports target WooCommerce products)
- PHP 8.1 or higher

## Installation

1. Install the release ZIP through **Plugins → Add New → Upload Plugin**, or extract it into `wp-content/plugins/`.
2. Install and activate WooCommerce.
3. Activate EzImport under **Plugins → Installed Plugins**.
4. Open **EzImport** in the WordPress admin menu and create your first import.

The GitHub source checkout omits `vendor/`. Run `composer install --no-dev` in a source checkout before activating it; the release ZIP includes the production dependencies.

## Building a directory release

On Windows, run `./tools/build-release.ps1` from this repository. It creates `dist/ezimport-1.0.0.zip` with production dependencies and excludes tests and development packages. The directory release uses the WordPress.org slug `ezimport`; the source repository and internal function prefix retain `wpezimport`.

## Usage

1. **Add a source** — upload a CSV/XLS/XLSX/XML file or paste a feed URL
2. **Pick the data** — for XML feeds, select the repeating element that represents one product; for spreadsheets, columns are detected automatically
3. **Map the fields** — drag columns or XML elements onto WooCommerce product fields, choose a unique identifier, and set which fields to update on re-import
4. **Run it** — start the import immediately with live progress, or save the profile for later

Saved imports appear under **EzImport → Manage Imports**, where you can run, edit, schedule or delete them, and view per-run statistics.

## Scheduled imports (cron)

URL-based imports can run unattended via three endpoints, shown under **Manage Imports → Schedule**:

| URL | Recommended schedule | What it does |
| --- | --- | --- |
| Trigger | once a day | Downloads the feed, detects changes (ETag / Last-Modified / content hash) and re-arms the import if needed |
| Process | every 2 minutes | Processes the next batch within the time limit, resuming where it left off |
| Cancel | manual | Stops a running import; progress is preserved |

Example crontab:

```cron
0 2 * * *   /usr/bin/wget -q -O /dev/null "https://yoursite.com/?wpezimport_action=trigger&import_id=1&import_key=SECRET"
*/2 * * * * /usr/bin/wget -q -O /dev/null "https://yoursite.com/?wpezimport_action=process&import_id=1&import_key=SECRET"
```

Each import has its own secret key, which you can regenerate at any time from the Scheduling Options page.

## Dependencies

- [PhpSpreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) — reading CSV/XLS/XLSX files

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

This plugin is licensed under the GPL v2 or later.

## Author

Yuri Pimenov

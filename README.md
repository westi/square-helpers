# Square Helpers

Scripts and utilities for Square API integration.

## Seasonal Sales Reports

These scripts generate season-focused CSVs for the show period from **December 1 through March 31**.
Season names use the year the season ends (example: `--season=2026` means Dec 2025 to Mar 2026).

### Seasonal report setup

1. Copy `.env.example` to `.env`.
2. Set:
   - `SQUARE_ACCESS_TOKEN`
   - `SQUARE_LOCATION_ID`
   - `SQUARE_CAST_PARTY_VARIATION_IDS`
   - `SQUARE_SOUVENIR_PROGRAM_VARIATION_IDS`
   - `SQUARE_VIP_BAG_VARIATION_IDS`
   - `SQUARE_DVD_VARIATION_IDS`
   - Optional: `SQUARE_DVD_BUNDLE_VARIATION_IDS`

Each product variable accepts one or more Square variation IDs (comma-separated).
For DVDs, the report supports both `SQUARE_DVD_VARIATION_IDS` and optional `SQUARE_DVD_BUNDLE_VARIATION_IDS`; both sets are included in totals.

### Quick start

```bash
./run_cast_souvenir_vip_reports.sh
```

Or individually:

```bash
php report_cast_party_sales.php
php report_souvenir_program_sales.php
php report_vip_bag_sales.php
php report_dvd_sales.php
```

By default each script writes a CSV to `./reports/`:

- `cast_party_sales_season_<YYYY>.csv`
- `souvenir_program_sales_season_<YYYY>.csv`
- `vip_bag_sales_season_<YYYY>.csv`
- `dvd_sales_season_<YYYY>.csv`

### Seasonal options

- `--season=YYYY` Override the ending year used for the Dec-Mar window.
- `--location-id=ID1,ID2` Override `SQUARE_LOCATION_ID`.
- `--sandbox` Use Square sandbox API.
- `--stdout` Print CSV to stdout instead of writing `./reports/...`.
- `--debug` Print redacted Square API request/response debug logs to STDERR.
- `--help` Show usage.

### CSV columns

- Cast Party / Souvenir / VIP:
  - `first_name,last_name,quantity,order_links` (Square dashboard URLs per purchaser; multiple orders are semicolon-separated in the cell)
- DVD:
  - `full_name,email,phone,dvd_single_qty,dvd_bundle_qty,quantity,address_line_1,address_line_2,locality,administrative_district_level_1,postal_code,country,address_source`

DVD mailing address preference is:
1) order shipping recipient address, then
2) customer profile address fallback.

## list_product_purchasers.php

Lists purchasers of specific Square products within a date range and outputs their details (including mailing address when available) for shipping. Useful for shipping items to customers who purchased in person.

### Requirements

- PHP 7.4 or later (no Composer or external dependencies)
- Square API access token with `ORDERS_READ`, `CUSTOMERS_READ`, and `PAYMENTS_READ` permissions (Payments used as fallback when order has no contact info)

### Setup

1. **Get Square API credentials**
   - Go to [Square Developer Dashboard](https://developer.squareup.com/apps).
   - Create or open an application.
   - Get a **Personal Access Token** (or use OAuth) for the environment you need (Production or Sandbox).
   - For Sandbox testing, use the Sandbox credentials and pass `--sandbox` or set `SQUARE_SANDBOX=1`.

2. **Get your location ID(s)**
   - In the Square Developer Dashboard: open your app → Locations, or use the [Locations API](https://developer.squareup.com/reference/square/locations-api/list-locations) (e.g. `GET /v2/locations`) to list locations and copy the `id` values.
   - In Square Point of Sale: Square Dashboard → Account & settings → Business → Locations.

3. **Get catalog item variation IDs** (for `--products`)
   - Square Dashboard → Item Library → open an item → open the variation (e.g. size/color) → the variation ID is in the URL or item details.
   - Or use the [Catalog API](https://developer.squareup.com/reference/square/catalog-api) (e.g. RetrieveCatalogObject or SearchCatalogObjects) to get `CatalogItemVariation` IDs. Line items in orders use the **variation** ID (`OrderLineItem.catalog_object_id`), not the parent item ID.

4. **Configure environment**
   - Copy `.env.example` to `.env` and set:
     - `SQUARE_ACCESS_TOKEN` – your Square API access token
     - `SQUARE_LOCATION_ID` – one or more location IDs (comma-separated, max 10)
   - Or export the same variables in your shell. The script loads `.env` from the current working directory if present.

### Usage

```bash
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 --products=VAR_ID1,VAR_ID2
```

**Required options**

- `--start=DATE` – Start of date range (YYYY-MM-DD or full RFC 3339).
- `--end=DATE` – End of date range (YYYY-MM-DD or full RFC 3339).

**Optional**

- `--products=IDS` – Comma-separated catalog **item variation** IDs. When omitted, all orders in the date range are included (all purchasers); when provided, only orders containing at least one of these products are listed.
- `--location-id=ID` – Override `SQUARE_LOCATION_ID` (comma-separated for multiple, max 10).
- `--output=csv|json|report` – Output format (default: `csv`). Use `report` for a human-readable text report grouped by purchaser.
- `--sandbox` – Use Square sandbox base URL.
- `--state=COMPLETED` – Filter orders by state (e.g. `COMPLETED` to exclude drafts/canceled).
- `--debug` – Print redacted Square API request/response debug logs to STDERR.
- `--help` – Show help and exit.

**Examples**

```bash
# All purchasers in date range (no product filter)
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 > purchasers.csv

# Only orders containing specific products
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 --products=ABC123,DEF456 > purchasers.csv

# JSON output
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 --products=ABC123 --output=json

# Human-readable report (grouped by purchaser, with order links)
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 --output=report

# Only completed orders, sandbox
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 --products=ABC123 --state=COMPLETED --sandbox
```

### Output

- **CSV** (default): one row per order. Columns include `order_id`, `order_date`, `customer_id`, `first_name`, `last_name`, `email`, `phone`, address fields (`address_line_1`, `address_line_2`, `locality`, `administrative_district_level_1`, `postal_code`, `country`), `product_name_or_id`, `quantity`, and `has_address` (Y/N).
- **JSON**: same fields as an array of objects.
- **report**: Human-readable text report, one section per purchaser. Each section shows: name, email, phone; multiline address (or “(No address on file)”); a summary of items purchased (item name and total quantity); and a list of links to view each of their orders in the Square dashboard. Set `SQUARE_ORDER_URL_BASE` if your dashboard URL differs (e.g. for sandbox: `https://squareupsandbox.com/dashboard/orders/overview`).

Address is taken from the order’s shipment fulfillment recipient when present; otherwise from the customer profile when the order has a `customer_id`. If neither is available, address fields are empty and `has_address` is N.

### Debug mode

Use `--debug` on any script to print request diagnostics to STDERR while leaving CSV/JSON/report output intact on STDOUT.

```bash
php report_cast_party_sales.php --debug
php list_product_purchasers.php --start=2025-01-01 --end=2025-01-31 --debug
```

Debug output includes method, API path, attempt, status, elapsed time, and redacted request/response payloads.

# Xophz Yellow Links

> **Category:** Command Deck · **Version:** 26.7.21 · **Text Domain:** `xophz-compass-yellow-links`

Standalone WordPress backend and router for the **Yellow Links** web application within the COMPASS ecosystem.

---

## Overview

**Xophz Yellow Links** provides the server-side architecture, custom content models, Gemini AI intelligence endpoints, and federated networking for the Yellow Links web app. It acts as both a standalone WordPress plugin and a custom single-page application (SPA) router.

---

## Core Capabilities

- **Custom Post Type & Meta Schema** – Registers `yellow_link` custom post type, `yellow_link_category` taxonomy, and structured metadata for link indexing and safety scoring.
- **Gemini AI Integration** – REST API endpoints powered by Gemini 3.5 Flash for automated link categorization, tag extraction, description summaries, ad generation, and security/safety assessment.
- **Sister Sites Federation** – Distributed directory networking that allows multiple Yellow Links installations to exchange and aggregate link indices with local transient caching.
- **Dynamic Frontend SPA Router** – Intercepts custom slug requests (e.g. `/yellow-links`) to serve the compiled Vue/Vite frontend bundle or proxy to a local Vite development server.
- **Admin Management Panel** – WP-Admin options page (`Settings > Yellow Links`) to configure deployment slugs and sister site federation URLs.

---

## Requirements

- **WordPress:** 5.8+
- **PHP:** 7.4+ (PHP 8.0+ recommended)
- **Gemini key:** Add the Google API key under **Settings > Connectors** (Google) for AI link analysis features. The key is sent to Google in the `x-goog-api-key` header and is never placed in a URL.

---

## REST API Reference

All endpoints are registered under the `yellow-links/v1` REST namespace:

| Endpoint | Method | Description | Parameters |
|---|---|---|---|
| `/yellow-links/v1/gemini/analyze` | `POST` | Analyzes a URL using Gemini AI to return a description, category, tags, and safety verdict. | `url` (required), `title`, `userDescription` |
| `/yellow-links/v1/gemini/suggest-ad` | `POST` | Generates a punchy, uppercase advertisement headline, pitch, and CTA button label. | `businessName` (required), `topic` (required) |
| `/yellow-links/v1/network` | `GET` | Fetches local links merged with federated sister site link directories (cached via WP transient). | None |

### AI Analysis Response Schema

```json
{
  "description": "Clean 1-2 sentence summary of the website.",
  "category": "Tech & Dev",
  "tags": ["OPEN-SOURCE", "TOOLING", "CLI"],
  "safetyStatus": "safe",
  "safetyReason": "Verified as a safe public developer utility."
}
```

---

## Data Architecture & PHP Class Map

| Class | File | Purpose |
|---|---|---|
| `Xophz_Compass_Yellow_Links` | `xophz-compass-yellow-links.php` | Main plugin bootstrapper, rewrite rules, admin settings, and SPA template loader |
| `Yellow_Links_CPT` | `includes/class-yellow-links-cpt.php` | CPT (`yellow_link`), taxonomy (`yellow_link_category`), and post meta registration |
| `Yellow_Links_API` | `includes/class-yellow-links-api.php` | REST API endpoint handlers and Gemini AI client integration |
| `Yellow_Links_Connectors` | `includes/class-yellow-links-connectors.php` | Single resolver for every credential (Gemini, Stripe, Plausible) over the WordPress Connectors API |
| `Yellow_Links_Write_Handlers` | `includes/trait-yellow-links-write-handlers.php` | Handlers for submit, vote, click and comment, used by `Yellow_Links_API` |
| `Yellow_Links_Rate_Limit` | `includes/class-yellow-links-rate-limit.php` | Per-user and per-IP (`REMOTE_ADDR`) fixed-window counters kept in transients |

### Who can call what (`yellow-links/v1`)

| Access | Routes |
| :-- | :-- |
| Public read | `GET /network`, `GET /links`, `GET /export/geojson`, `GET /me` |
| Public write, counted per address | `POST /links/{id}/click`: one count per address per link per hour, and 300 counted clicks per address per hour |
| Logged-in only | `POST /links` (10 per user and 30 per address per hour), `POST /links/{id}/vote` and `POST /comments/{id}/vote` (one vote per user per target, 120 per hour), `POST /links/{id}/comment` (10 per hour), `POST /monetization/buy-slot` (5 per user and 10 per address per hour), `POST /gemini/analyze`, `POST /gemini/suggest-ad` (20 Gemini calls per user per hour) |
| Admin (`manage_options`) | `POST /monetization/approve-slot`, `POST /monetization/reject-slot`, `PUT /links/{id}/status`, `DELETE /links/{id}` |

Notes:

* A submitted link keeps only an `http` or `https` address. The trust tier (the Government, Non-Profit and Business badges) is set by an admin only: a link from anyone else is a `community` link whatever the request says. The comment author is the logged-in user's display name.
* Votes, clicks and comments only apply to published `yellow_link` posts.
* `buy-slot` opens a Stripe PaymentIntent with manual capture for the caller's own link (an admin may buy for any link). It never marks a slot paid: a slot goes active in `approve-slot`, and only when Stripe confirms the capture. The price is the caller's `price_cents` between 500 and 500000, unless the site fixes it with the `yellow_links_slot_price_cents` filter or the `xophz_compass_yellow_links_slot_price_cents` option (cents). A payment already waiting for approval is not replaced.
* Counters key on `REMOTE_ADDR`. Behind a proxy, make the web server set `REMOTE_ADDR` from the proxy (or map the visitor address with the `yellow_links_client_ip` filter), or every visitor shares one counter.

### Post Meta Fields (`yellow_link`)

- `yl_url`: Target URL for the directory entry.
- `yl_tags`: JSON string array of tags associated with the link.
- `yl_safetyStatus`: Safety assessment status (`safe`, `warning`, or `unsafe`).
- `yl_safetyReason`: Objective explanation for the safety rating.
- `yl_rating`: Aggregate user rating score.

---

## Installation & Setup

1. Upload `xophz-compass-yellow-links` to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Configure settings under **Settings > Yellow Links**:
   - **Deployment Slug**: Set custom endpoint URL (defaults to `yellow-links`).
   - **Sister Sites (Federation)**: Enter sister site URLs (one per line) to aggregate network links.
4. Add the Google (Gemini) key under **Settings > Connectors** if using AI link analysis features. For paid ad slots, add the Stripe secret key there too.

---

## Frontend Integration

The plugin looks for built frontend assets at `public/dist/index.html` or within `apps/yellow-links/dist/index.html`. During development (`WP_DEBUG` or `WP_ENV=development`), it automatically proxies requests to the local Vite dev server on port `8088`.

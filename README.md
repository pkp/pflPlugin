# Publication Facts Label (PFL) Plugin for Open Journal Systems

[![OJS 3.5+](https://img.shields.io/badge/OJS-3.5%2B-blue)](https://pkp.sfu.ca/software/ojs/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Version](https://img.shields.io/badge/version-2.0.0.0-green)](#version-history)

This plugin integrates the [Publication Facts Label](https://github.com/pkp/pfl) into Open Journal Systems (OJS). The PFL is a standardized, reader-facing summary of a journal's integrity characteristics — acceptance rates, peer reviewer counts, competing-interest disclosure rates, funding disclosure, indexing, and more — displayed on every article landing page.

> **Installation:** The plugin is available through the OJS Plugin Gallery. It is not recommended to download directly from GitHub for production use.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Journal Settings](#journal-settings)
  - [Index Listings](#index-listings)
  - [Professional Organization Memberships](#professional-organization-memberships)
  - [Custom Indexes](#custom-indexes)
- [Site Admin Dashboard](#site-admin-dashboard)
- [Cache Management](#cache-management)
- [What is Displayed](#what-is-displayed)
- [Changes in v2.0.0.0](#changes-in-v2000)
- [Pending Features](#pending-features)
- [Dependencies](#dependencies)
- [Performance & Shared Hosting](#performance--shared-hosting)
- [Security](#security)
- [Version History](#version-history)

---

## Features

### Core (since v1.0)
- Per-article Publication Facts Label displayed on the article landing page
- Acceptance rate, peer reviewer count, days to publication
- Competing-interest disclosure rate
- Funding disclosure (requires Funding plugin)
- Data-availability statement detection
- Journal indexing badges (DOAJ, Google Scholar, MEDLINE, Latindex, Scopus, Web of Science)
- Academic society affiliation
- Publisher name and URL

### Added in v2.0.0.0
- **Professional Organization Memberships** — Display COPE, IILD, and/or a custom organization membership badge on the label
- **Additional Custom Indexes** — Two user-defined index slots with name, acronym, and URL
- **Cache Invalidation Control** — Journal managers can clear the 24-hour statistics cache from the plugin settings page
- **Site Admin Dashboard** — Accessible to site administrators; shows all journals and their PFL configuration status (enabled, indexes configured, org memberships, cached stats, date-start filter, academic society)
- **English Locale Fallback** — If a locale translation JSON file is missing, the label gracefully falls back to English (Issue #41)
- **Submission Date Null Safety** — Articles with a null `dateSubmitted` are no longer incorrectly excluded by the date-start filter

---

## Requirements

| Requirement | Minimum |
|---|---|
| OJS | 3.5.0 |
| PHP | 8.3 |
| Database | MySQL 5.7.4+ / PostgreSQL 10+ |
| [Funding Plugin](https://github.com/ajnyga/funding) | Optional — enables funding disclosure row |

---

## Installation

1. Log in to OJS as a Site Administrator.
2. Navigate to **Administration → Plugin Gallery**.
3. Search for "Publication Facts Label" and install it.
4. Go to **Settings → Website → Plugins → Generic Plugins** and enable the plugin.
5. Click **Settings** next to the plugin to configure it per journal.

---

## Configuration

### Journal Settings

| Setting | Description |
|---|---|
| **Society name or acronym** | Name or acronym of the academic society that owns/sponsors the journal (shown on the label) |
| **Society URL** | Link from the society badge to the society's website |
| **Start Date** | Exclude submissions received before this date from all statistics. Use if the journal did not use OJS's editorial workflow before a certain point. |

### Index Listings

The plugin supports up to **8 index badges** on the label:

**Automatically verified** (checked against the respective API when you save settings):
- DOAJ (Directory of Open Access Journals)
- Google Scholar
- MEDLINE
- Latindex

**Manually configured** (paste the journal's URL in the index):
- Scopus
- Web of Science
- **Custom Index 1** — any index with a name, optional acronym, and URL
- **Custom Index 2** — any index with a name, optional acronym, and URL

> For automatic indexes, the plugin queries the respective API using the journal's Online ISSN. Ensure this is set correctly under **Journal Settings → Masthead**.

### Professional Organization Memberships

Journals that are members of publishing ethics organizations can display a "Member" badge. Configure in plugin settings:

| Organization | Setting |
|---|---|
| **COPE** (Committee on Publication Ethics) | Paste your journal's COPE member page URL |
| **IILD** | Paste your journal's IILD member page URL |
| **Custom Organization** | Enter a name, optional acronym, and URL |

### Custom Indexes

Under **Index Listings → Custom Indexes**, you may add up to two additional index entries:
- **Index name** — full name of the index (e.g., "EBSCOhost Academic Search")
- **Acronym** — short label shown on the badge (e.g., "EBSCO")
- **URL** — direct link to the journal's listing in that index

---

## Site Admin Dashboard

Site administrators see a **PFL Dashboard** action in the plugin list. This opens a modal showing all journals and their PFL status:

| Column | Description |
|---|---|
| Journal | Journal title and URL path |
| PFL On | Whether the plugin is enabled for that journal |
| Indexes | Total number of index badges configured |
| Orgs | Number of organization memberships configured |
| Society | Configured academic society name |
| Start Date | Date-start filter, if set |
| Stats Cached | Whether statistics are currently in cache |

---

## Cache Management

Statistics are cached per-journal for **24 hours** using OJS's built-in cache layer (file, database, or Redis depending on your OJS configuration).

**To clear the cache manually:**
1. Go to **Settings → Website → Plugins → Generic Plugins → Publication Facts Label**.
2. Click **Clear Statistics Cache**.
3. The cache for the current journal is cleared immediately. Fresh statistics will be fetched on the next article page view.

> This is useful after bulk-importing submissions or correcting data, when you want the label to reflect the latest numbers without waiting 24 hours.

---

## What is Displayed

Each article landing page shows a Publication Facts Label with the following rows:

| Row | Source |
|---|---|
| **Reviewers** | Count of completed reviews for this article / journal average |
| **CI Statements** | Whether authors declared competing interests / journal % |
| **Data Availability** | Whether a data-availability statement is present / journal % |
| **Funding** | Whether funding was declared / journal % (requires Funding Plugin) |
| **Acceptance Rate** | Acceptance % for this journal |
| **Days to Publication** | Days from submission to publication for this article / journal average |
| **Indexed** | Up to 8 index badges |
| **Member** | Organization membership badges (COPE, IILD, custom) |
| **Society** | Academic society name |
| **Publisher** | Publisher name |

---

## Changes in v2.0.0.0

### Breaking Changes
- **Minimum OJS version raised to 3.5.0** (3.4.x no longer supported)
- **Minimum PHP version raised to 8.3**

### Security Fixes
- Fixed column-reference injection risk in `getCompetingInterestsSubmissionCount()`: the join condition previously used `DB::raw('a.author_id')` inside a `where()` clause; now correctly uses `$join->on()`.
- Added null guard for `$request->getContext()` before first use in `displayArticlePfl()`.

### Bug Fixes
- **Missing `$dateStart` filter** in `getCompetingInterestsSubmissionCount()` — statistics were not filtered by the configured start date, causing inflated CI disclosure percentages.
- **Null `dateSubmitted` crash** — articles with a null submission date no longer cause a false exclusion (the date-start filter now requires a non-null `dateSubmitted` before comparing).
- **`$request->getJournal()`** removed API call replaced with `$request->getContext()` (OJS 3.5 compatibility).
- **`STATUS_PUBLISHED`** bare constant replaced with `PKPSubmission::STATUS_PUBLISHED`.
- **`DateTime` constructor** null-guarded against missing `datePublished`/`dateSubmitted`.
- **`authorCiFilter()`** — normalized author collection to a zero-indexed array compatible with both OJS 3.4 (plain array) and OJS 3.5 (`Illuminate\Support\Collection`).

### Performance / DB Improvements
- Migrated all 3 raw-SQL functions (`getReviewerAverage`, `getDaysToPublicationAverage`, `getReviewableSubmissionCount`) to the Laravel `DB::table()` query builder — no more string-concatenated SQL, proper parameter binding throughout.
- All database functions now wrapped in `try/catch` — a query failure on shared hosting returns sensible defaults (0 / null) instead of crashing the article page.
- Removed unused `use PKP\linkAction\request\RedirectAction` import.

### New Features
- Professional Organization Memberships (Issue #53)
- Custom Indexes, up to 8 total (Issue #32)
- Cache Invalidation Control
- Site Admin Dashboard (Issue #47)
- English locale fallback (Issue #41)

---

## Changes in v2.1.0.0

### New Features
- **Dashboard CSV Export** (Issue #47) — Site admins can download the full dashboard table as a `.csv` file directly from the PFL Dashboard modal. Includes journal name, path, PFL enabled status, index count, org count, academic society, date-start filter, and stats-cache status.
- **Per-journal cache invalidation from the dashboard** — Each row in the Site Admin Dashboard now has a "Clear" button. Clicking it immediately clears the statistics cache for that journal via AJAX, updating the cache indicator in-place without closing the modal.
- **Accessibility improvements** — The `<section>` wrapper around the Publication Facts Label on article pages now carries `role="region"`, `aria-label`, `aria-live="polite"`, and `aria-atomic="false"`, making the dynamically-loaded label widget accessible to screen readers.

---

## Pending Features

The following features are tracked in the issue queue and have not yet been implemented:

| Feature | Issue | Reason not yet implemented |
|---|---|---|
| Open Peer Review Indicator — highlight reviewer count with links to published reviews when the Open Peer Review plugin is active | #46 | No canonical OPR plugin API in OJS 3.5; data model uncertain |
| ORCID Two-Way Verification — let editors and board members verify ORCID and push journal role to their ORCID profile | #45 | Requires ORCID OAuth credentials, member API access, complex OAuth2 flow; must be configured per-server |
| DOAJ REST API Integration — expose PFL data via OJS REST API endpoint | #40 | Requires PKP `APIHandler` registration and OJS routing integration; non-trivial framework work |
| Per-Section PFL Statistics — scope statistics to specific journal sections | — | Needs settings form changes and refactoring of all DB queries to accept a section filter |
| PDF/HTML Article Embedding — inject PFL into JATS/HTML views and galley PDFs | #36 | PDF manipulation requires Snappy/wkhtmltopdf; HTML galley uses a separate hook chain |
| Preprint Support — show a modified label for preprint sections | — | OJS 3.x has no native preprint section type; would need custom section flag and modified widget data |
| Full WCAG 2.1 AA compliance for the label widget | — | The web component internals are in the `pfl` submodule (not this plugin's code); wrapper-level ARIA added in v2.1.0.0 |

Pull requests are welcome. See [PKP Forum](https://forum.pkp.sfu.ca/) for discussion.

---

## Dependencies

| Dependency | Type | Notes |
|---|---|---|
| OJS 3.5+ (Laravel/Illuminate) | Required | Plugin uses `DB::table()`, `Cache::remember()`, `Hook::add()` |
| [pfl](https://github.com/pkp/pfl) submodule | Required | Vue.js web component + locale JSON files |
| [Funding Plugin](https://github.com/ajnyga/funding) | Optional | Enables funding disclosure row; detected automatically |

### Submodule

The `pfl/` directory is a Git submodule pointing to [pkp/pfl](https://github.com/pkp/pfl). When installing from Git:
```bash
git clone --recurse-submodules https://github.com/singhphd/pflPlugin.git
```
Or, if already cloned:
```bash
git submodule update --init
```

---

## Performance & Shared Hosting

The plugin is designed to be safe on shared hosting and small VPS servers:

- **24-hour statistics cache** — the heavy database queries (reviewer averages, acceptance rates, days to publication) run at most once per journal per 24 hours. Cached values are returned instantly on subsequent article page loads.
- **All DB queries wrapped in try/catch** — if a query fails (timeout, lock, missing table), the label renders with safe defaults (0%) rather than crashing the page.
- **Query builder, not raw SQL** — all queries use bound parameters via `DB::table()`, preventing SQL injection and ensuring compatibility with both MySQL and PostgreSQL.
- **No unbounded queries** — every COUNT/AVG query filters by `context_id` and joins only the necessary tables.
- **Cache manual clear** — journal managers can reset the cache on-demand without waiting 24 hours (e.g., after importing data).

If your hosting environment has aggressive query time limits (e.g., 2–3 seconds), the first article page load after cache expiry may be slower. Subsequent loads will be fast. Consider pre-warming the cache by visiting one article page after clearing.

---

## Security

- All form submissions are validated with `FormValidatorPost` (POST-only) and `FormValidatorCSRF` (CSRF token).
- All external HTTP calls (DOAJ, Latindex, MEDLINE validation; PKP statistics API) are wrapped in `try/catch`. A network failure never surfaces to the end user.
- The Site Admin Dashboard is guarded by `ROLE_ID_SITE_ADMIN` role check in addition to OJS's plugin management access control.
- Database joins use proper column-reference syntax (`$join->on()`) — no raw column names passed to `where()`.
- Plugin settings are stored in OJS's `plugin_settings` table per journal, not in flat files.

---

## Version History

| Version | Date | Notes |
|---|---|---|
| **2.1.0.0** | 2026-04-16 | Dashboard CSV export, per-journal cache clear, ARIA accessibility |
| **2.0.0.0** | 2026-04-16 | PHP 8.3 / OJS 3.5 compatibility rewrite; org memberships, custom indexes, cache management, admin dashboard |
| 1.3.0.0 | 2025-11-07 | Prior stable release |
| 1.0.x | 2023 | Initial release |

---

*For support, please use the [PKP Community Forum](https://forum.pkp.sfu.ca/).*
*Plugin developed with contributions from Simon Fraser University, John Willinsky, and the PKP community.*

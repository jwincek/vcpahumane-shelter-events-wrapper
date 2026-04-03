# Shelter Events Wrapper

A WordPress plugin that wraps [The Events Calendar](https://theeventscalendar.com/) with a staff-friendly interface for managing recurring animal shelter programs — BINGO nights, spay/neuter clinics, adoption events, and more. Programs are a custom post type that shelter staff manage from the WordPress admin; the plugin automatically generates individual TEC event instances on a daily cron schedule.

Built on the same layered architecture as [vcpahumane-petstablished-sync](https://github.com/jwincek/vcpahumane-petstablished-sync).

## Requirements

- WordPress 6.9+
- PHP 8.1+
- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) (free version)

## Installation

1. Download or clone into `wp-content/plugins/shelter-events-wrapper/`.
2. Activate **The Events Calendar** first, then activate **Shelter Events Wrapper**.
3. On activation, the plugin imports two starter programs (BINGO Night and Feline Spay/Neuter Clinic) from the seed data in `config/events.json`.
4. Navigate to **Events → All Programs** to manage programs, or **Events → Generate Events** to trigger event creation.

---

## Staff Guide

This section is written for non-technical shelter staff who manage the event calendar day-to-day.

### Creating a New Program

1. In the WordPress admin, go to **Events → All Programs → Add New Program**.
2. Enter a name (e.g. "Puppy Yoga") and a description in the main editor. The description will appear on each generated calendar event.
3. Scroll down to the **Event Schedule Settings** panel and fill in the sections:

**Schedule**
- Click the day-of-week pills to select which days this program runs (e.g. click **Tuesday** and **Saturday** for BINGO).
- Set the start and end times.
- Choose the timezone (defaults to America/New_York).

**Venue**
- Enter the venue name, street address, city, state, and ZIP. If a venue with that exact name already exists in The Events Calendar, it will be reused automatically.

**Organizer**
- Enter the organizer's name, phone, email, and website. Like venues, existing organizers are reused by name.

**Event Links**
- **Website / Booking URL** — This is written to each generated event's "Event Website" field in The Events Calendar. Visitors see it as a link on the event detail page. Use it for booking pages like SuperSaaS, Eventbrite, or a registration form.
- **Facebook Page URL** — Stored as metadata on each event. Useful for linking to a Facebook group or event page for the program.

**Pricing & Logistics**
- Set the cost (enter 0 for free events), capacity, age restriction, contact email, and whether appointments are required.
- Check **Variable pricing** if the program has multiple price points (e.g. admission + per-card fees). This displays "Varies" instead of a fixed amount on the calendar.

**Event Display**
- Enter comma-separated tags (e.g. `bingo, fundraiser, community`) that will be applied to each generated event.
- Check **Featured** to mark generated events as featured in The Events Calendar.

**Active Toggle**
- The green/amber banner at the top controls whether the daily cron generates events for this program. Uncheck **Active** to pause a program without deleting it.

4. Click **Publish** (or **Update** if editing).

### Generating Events

Events are generated automatically once per day by WP-Cron, covering the next 8 weeks by default. To generate events immediately:

1. Go to **Events → Generate Events**.
2. Select a specific program or leave it on "All Active Programs."
3. Set how many weeks ahead to generate.
4. Optionally check **Dry run** to preview without creating events.
5. Click **Generate Now**.

Events that already exist (based on program + date) are skipped automatically — you can run generation as many times as you like without creating duplicates.

### Updating a Program

When you update a program and click **Update**, all **future** events linked to that program are automatically updated to reflect the changes. This includes the event title, description, times, venue, organizer, cost, website URL, tags, and all other fields.

**Past events** are left untouched by default (they're historical records). If you need to update past events too — for example, to correct a contact email — check the **"Also update past events when saving changes"** checkbox in the status banner before saving. This checkbox resets each time, so you have to consciously opt in each time you want past events modified.

After saving, a green notice will tell you how many events were updated (e.g. "12 existing events were updated to match this program.").

### Cancelling a Single Event

If a specific event instance needs to be cancelled (e.g. BINGO is cancelled for one Tuesday due to weather):

1. Find the event in **Events → Events**.
2. The event's title will be prefixed with `[CANCELLED]` and a cancellation badge will appear in the event list block.

Note: Cancelling a single event does not affect the program or other event instances.

### Replacing an Event with a Special Event

When a regular program event needs to be swapped for a one-off (e.g. regular Saturday BINGO is replaced by Novelty BINGO):

1. **From the Events list:** Find the event in **Events → Events**. Hover over it and click the **Replace** link in the row actions.
2. **From the Generate page:** Go to **Events → Generate Events**, find the event in the **Upcoming Generated Events** table, and click **Replace**.

Either way, the plugin will:
- Cancel the original event (marked as `[CANCELLED]`).
- Create a new **draft** replacement event pre-populated with the original's date, time, venue, and organizer.
- Open the replacement in **The Events Calendar's block editor**, where you can customize the title, description, images, pricing, tickets, and anything else.

Once you're happy with the replacement, click **Publish**. The replacement appears on the calendar in the same time slot. The cancelled original is hidden from visitors automatically.

The **Upcoming Generated Events** table on the Generate page shows the status of each event: Active, Cancelled, Replaced (with a link to edit the replacement), or Blackout.

### Variable Pricing

For programs with multiple price points (e.g. BINGO with admission + per-card fees, or spay/neuter with different packages):

1. Edit the program and check the **Variable pricing** checkbox in the Pricing & Logistics section.
2. The cost field is kept at 0 — The Events Calendar will display **"Varies"** instead of "Free" on the single event page, calendar views, and the event list block.

This is a display-level flag. When you're ready to add structured pricing (e.g. with Event Tickets), the variable pricing flag can be retired in favor of real ticket types.

### Blackout Dates

Blackout dates prevent events from being generated on specific days (holidays, closures, etc.).

**Global blackout dates** (all programs):

1. Go to **Events → Generate Events** and scroll to the **Global Blackout Dates** section.
2. Enter dates in `YYYY-MM-DD` format, one per line (e.g. `2026-12-25`).
3. Click **Save Blackout Dates**.

**Per-program blackout dates:**

1. Edit the program and scroll to the **Blackout dates** textarea in the Schedule section.
2. Enter dates the same way — one per line, `YYYY-MM-DD` format.
3. Click **Update**.

Per-program dates are checked *in addition to* global dates. Both lists are merged during generation.

**Important:** Blackout dates only prevent future generation. If events were already generated before a blackout date was added, they will remain on the calendar. You can cancel or replace them manually. The Upcoming Generated Events table flags these with a purple **Blackout** badge so you can spot them easily.

### Pausing a Program

To temporarily stop generating events for a program (e.g. during a seasonal break):

1. Go to **Events → All Programs** and edit the program.
2. Uncheck the **Active** checkbox in the status banner.
3. Click **Update**.

No new events will be generated until you re-check Active. Existing future events remain on the calendar unless you delete them manually.

### Getting Help

Go to **Events → Help** to view this guide directly in the WordPress dashboard.

---

## Developer Guide

### Architecture

```
config/              → Seed data (programs, venues, organizers) + generation settings
includes/core/       → Config loader, Program CPT, Taxonomy registry, Event generator,
                       Event syncer, Program importer
includes/abilities/  → WP 6.9 Abilities API callbacks
includes/            → Admin page, Help page, Blocks, REST routes
blocks/              → Server-rendered Gutenberg block
templates/           → Block theme templates
assets/              → Editor JS, front-end CSS, admin CSS, metabox CSS
.github/workflows/   → CI linting
```

Business logic lives in **abilities**, the **Event Generator**, and the **Event Syncer** — thin, testable operations. The admin UI, REST endpoints, and blocks are thin consumers that delegate to these. The `Program_CPT` class provides `get_active_programs()` which returns a normalized array that the generator, syncer, abilities, REST, and admin page all consume.

### How Event Generation Works

The generator queries all published, active `shelter_program` posts via `Program_CPT::get_active_programs()`, walks each program's recurrence days over the lookahead period, and calls `tribe_events()->set_args()->create()` for each date. Duplicate prevention uses a deterministic SHA-256 hash (`program-slug + date`) stored as `_shelter_generated_hash` post meta. Dates that appear in either the global blackout list (`shelter_events_blackout_dates` option) or the program's own `blackout_dates` field are skipped silently.

Each generated event receives:
- A title like "BINGO Night — Tuesday, April 15, 2025"
- TEC event meta: start/end times, timezone, cost, currency, Event Website URL (`_EventURL`)
- Linked TEC Venue and Organizer posts (found by exact title match or created with `publish` status)
- Custom meta: `_shelter_program_slug`, `_shelter_program_post_id`, `_shelter_facebook_url`, capacity, contact info
- Taxonomy term from `shelter_program_cat`
- Tags and featured status

### How Event Sync Works

`Event_Syncer` hooks into `save_post_shelter_program` at priority 20 (after `save_meta` at priority 10). It queries all TEC events with a matching `_shelter_program_post_id`, then updates each event's title, description, times, venue, organizer, cost, URLs, tags, featured status, and custom meta. Future events are always updated; past events are updated only if the `shelter_sync_include_past` checkbox was checked. Events that have been replaced (i.e. have `_shelter_replaced_by` meta) are skipped — they are frozen in their cancelled state and the replacement event is managed independently.

Venue and organizer resolution uses `$wpdb` exact title queries against `wp_posts` (not `get_posts()` which doesn't support title filtering) to find existing entries. If a draft is found, it's promoted to `publish`. New entries are created with `'status' => 'publish'`.

### Seed Data and Migration

On first activation, `config/events.json` is imported into CPT posts via `Program_Importer`. This is a one-time operation guarded by an option flag (`shelter_events_programs_imported`). After import, the JSON file is seed data only and the CPT is the live source of truth. The generation settings (lookahead weeks, dedup meta key) still come from JSON since they're infrastructure config.

### REST API

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/shelter-events/v1/programs` | GET | Public | List all active programs (from CPT) |
| `/shelter-events/v1/upcoming?program=bingo` | GET | Public | Upcoming events, optionally filtered by program slug |
| `/shelter-events/v1/generate` | POST | Admin | Trigger event generation |
| `/shelter-events/v1/cancel` | POST | Editor | Cancel a specific event instance |
| `/shelter-events/v1/replace` | POST | Editor | Cancel an event and create a draft replacement |

### Gutenberg Block

The **Shelter Event List** block (`shelter-events/event-list`) can be placed on any page or post:
- Filter by program (populated from CPT via REST API)
- Three layouts: list, card grid, compact
- Toggle cost and venue display
- Server-side rendering (no JS on the front end)

### Local Development

```bash
npm install
composer install
npm start          # Starts wp-env with TEC pre-installed
npm run stop
```

### Linting

```bash
composer lint       # PHPCS
composer lint:fix   # PHPCBF auto-fix
npm run lint:js     # JS lint
npm run lint:css    # CSS lint
```

### Hooks & Filters

| Hook | Type | Description |
|---|---|---|
| `shelter_events_event_created` | Action | Fires after each event instance is generated. Receives `$event_id`, `$slug`, `$program`, `$date`. |
| `shelter_events_event_synced` | Action | Fires after an existing event is synced with updated program data. Receives `$event_id`, `$program_post_id`, `$program`. |
| `shelter_events_program_imported` | Action | Fires after a program is imported from JSON seed data. Receives `$post_id`, `$slug`, `$program`. |

### Changelog

#### 2.2.0
- **Event replacement** — "Replace" action on shelter-generated events (TEC list row action + Generate page). Cancels the original and creates a draft replacement that opens in TEC's block editor for full creative control. Cancelled-and-replaced events are hidden from front-end displays.
- **Variable pricing** — "Variable pricing" checkbox on programs. Displays "Varies" across all TEC cost surfaces (single event, calendar views, event list block, REST API) via the `tribe_get_cost` filter.
- **Blackout dates** — Global blackout dates (Events → Generate Events) and per-program blackout dates (program metabox). Dates in either list are skipped during event generation. Pre-existing events on blackout dates are flagged in the admin UI.
- **REST endpoint** — `POST /shelter-events/v1/replace` to cancel + create replacement programmatically.
- **Abilities API** — `shelter_replace_event` ability registered for WP 6.9+.

#### 2.1.0
- **Event sync on program save** — all future events are auto-updated when a program changes. Staff can opt in to updating past events per-save.
- **Event Links** — programs now have Website/Booking URL and Facebook URL fields. The website URL is written to TEC's native `_EventURL` (Event Website) field on each generated event.
- **Help page** — `Events → Help` renders the README as a staff guide directly in the dashboard.
- **Venue/Organizer dedup fix** — resolved duplicate creation caused by `get_posts()` not supporting title queries; now uses `$wpdb` exact title match.
- **Venue/Organizer publish status** — new entries are created as `publish`; existing drafts are auto-promoted.
- **AJAX 502 fix** — block registration and admin class are skipped during AJAX requests; `plugins_loaded` priority bumped to 20.
- Added `shelter_events_event_synced` action hook.

#### 2.0.0
- **Programs are now a custom post type** (`shelter_program`) managed in the WordPress admin.
- Added dedicated "Event Schedule Settings" metabox with day-of-week chip selectors, venue/organizer fields, pricing, and active/paused toggle.
- Added `Program_Importer` for one-time JSON → CPT migration on activation.
- Added custom admin columns (days, time, cost, active status).
- Renamed taxonomy to `shelter_program_cat`.
- Added `shelter_events_program_imported` action hook.

#### 1.0.0
- Initial release with JSON config-driven programs, TEC ORM event generation, WP-Cron scheduling, REST API, Gutenberg block, and WP 6.9 Abilities API support.

## License

GPL-2.0-or-later.

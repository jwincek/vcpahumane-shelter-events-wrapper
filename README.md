# Shelter Events Wrapper

A WordPress plugin that wraps [The Events Calendar](https://theeventscalendar.com/) with a staff-friendly interface for managing recurring animal shelter programs — BINGO nights, spay/neuter clinics, adoption events, and more. Programs are a custom post type that shelter staff manage from the WordPress admin; the plugin automatically generates individual TEC event instances on a daily cron schedule.

Built on the same layered architecture as [vcpahumane-petstablished-sync](https://github.com/jwincek/vcpahumane-petstablished-sync).

## Requirements

- WordPress 6.7+
- PHP 8.1+
- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) (free version)

## Installation

1. Download or clone into `wp-content/plugins/shelter-events-wrapper/`.
2. Activate **The Events Calendar** first, then activate **Shelter Events Wrapper**.
3. On activation, the plugin imports two starter programs (BINGO Night and Feline Spay/Neuter Clinic) from the seed data in `config/events.json`.
4. Navigate to **Events → All Programs** to manage programs, or **Events → Generate Events** to trigger event creation.

## How It Works

### Programs as a Custom Post Type

Each recurring program (BINGO, Clinic, Adoption Saturday, etc.) is a `shelter_program` post that staff create and edit in the WordPress admin. The post editor includes a **dedicated settings panel** below the content area where staff configure:

- **Schedule** — pill-style day-of-week selectors (click Tuesday + Saturday for BINGO), start/end times, and timezone.
- **Venue** — name, street address, city, state, and ZIP.
- **Organizer** — name, phone, email, and website.
- **Pricing & Logistics** — cost, currency, capacity, age restriction, contact email, and whether appointments are required.
- **Event Display** — tags applied to generated events and a featured-event toggle.
- **Active toggle** — a green/amber status banner at the top. Unchecking "Active" pauses event generation for that program without deleting it.

The program list table in the admin shows days, time, cost, and active status at a glance.

### Event Generation

A daily WP-Cron job queries all published, active `shelter_program` posts and creates individual TEC event instances for each scheduled occurrence over the next N weeks (default: 8). Each generated event gets:

- A title like "BINGO Night — Tuesday, April 15, 2025"
- Proper start/end times and timezone from the program
- A linked TEC Venue and Organizer (created automatically if they don't exist yet)
- Custom meta (`_shelter_program_slug`, `_shelter_program_post_id`, capacity, contact info)
- Assignment to the `shelter_program_cat` taxonomy
- Tags and featured status from the program config

Duplicate prevention uses a deterministic SHA-256 hash (`program-slug + date`) stored as post meta — re-running generation is always safe and idempotent.

### Adding a New Program

1. Go to **Events → All Programs → Add New Program**.
2. Enter the program name and description in the editor.
3. In the **Event Schedule Settings** panel below, click the days it runs, set the times, fill in venue and pricing details.
4. Make sure the **Active** checkbox is checked.
5. Publish the program.
6. Go to **Events → Generate Events** and click **Generate Now**, or wait for the daily cron.

No JSON editing, no code changes, no deploys.

### Seed Data and Migration

On first activation, `config/events.json` is imported into CPT posts via the `Program_Importer`. This is a one-time operation — after import, the JSON file is seed data only and the CPT is the live source of truth. The generation settings (lookahead weeks, dedup meta key) still come from JSON since they're infrastructure config rather than content.

## Architecture

```
config/              → Seed data (programs, venues, organizers) + generation settings
includes/core/       → Config loader, Program CPT, Taxonomy registry, Event generator, Importer
includes/abilities/  → WP 6.9 Abilities API callbacks
includes/            → Admin page, Blocks, REST routes
blocks/              → Server-rendered Gutenberg block
templates/           → Block theme templates
assets/              → Editor JS, front-end CSS, admin CSS, metabox CSS
.github/workflows/   → CI linting
```

Business logic lives in **abilities** and the **Event Generator** — thin, testable operations with JSON Schema validation. The admin UI, REST endpoints, and blocks are thin consumers that delegate to these. The `Program_CPT` class provides `get_active_programs()` which returns a normalized array that the generator, abilities, REST, and admin page all consume.

## REST API

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/shelter-events/v1/programs` | GET | Public | List all active programs (from CPT) |
| `/shelter-events/v1/upcoming?program=bingo` | GET | Public | Upcoming events, optionally filtered by program slug |
| `/shelter-events/v1/generate` | POST | Admin | Trigger event generation |
| `/shelter-events/v1/cancel` | POST | Editor | Cancel a specific event instance |

## Gutenberg Block

The **Shelter Event List** block (`shelter-events/event-list`) can be placed on any page or post. It supports:

- Filtering by program (populated from CPT via the REST API)
- Three layouts: list, card grid, compact
- Toggling cost and venue display
- Server-side rendering (no JS on the front end)

## Local Development

```bash
npm install
composer install
npm start          # Starts wp-env with TEC pre-installed
npm run stop
```

## Linting

```bash
composer lint       # PHPCS
composer lint:fix   # PHPCBF auto-fix
npm run lint:js     # JS lint
npm run lint:css    # CSS lint
```

## Hooks & Filters

| Hook | Type | Description |
|---|---|---|
| `shelter_events_event_created` | Action | Fires after each event instance is generated. Receives `$event_id`, `$slug`, `$program`, `$date`. |
| `shelter_events_program_imported` | Action | Fires after a program is imported from JSON seed data. Receives `$post_id`, `$slug`, `$program`. |

## Changelog

### 2.0.0

- **Programs are now a custom post type** (`shelter_program`) managed in the WordPress admin.
- Added a dedicated "Event Schedule Settings" metabox below the editor with day-of-week chip selectors, venue/organizer fields, pricing, and an active/paused toggle.
- Added `Program_Importer` to auto-import JSON seed data into CPT posts on first activation.
- Added custom admin columns (days, time, cost, active status) to the programs list table.
- Renamed taxonomy from `shelter_program` to `shelter_program_cat` to avoid slug collision with the CPT.
- Event Generator, Abilities Provider, REST API, and Admin page now read from the CPT instead of JSON config.
- Generated events store a `_shelter_program_post_id` meta field linking back to the parent program.
- Added `shelter_events_program_imported` action hook.

### 1.0.0

- Initial release with JSON config-driven programs, TEC ORM event generation, WP-Cron scheduling, REST API, Gutenberg block, and WP 6.9 Abilities API support.

## License

GPL-2.0-or-later.

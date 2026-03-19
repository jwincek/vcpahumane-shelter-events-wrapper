# Shelter Events Wrapper

Config-driven wrapper for [The Events Calendar](https://theeventscalendar.com/) that lets an animal shelter manage recurring programs — BINGO nights, spay/neuter clinics, adoption events, and more — from a single JSON file. Built on the same layered architecture as [vcpahumane-petstablished-sync](https://github.com/jwincek/vcpahumane-petstablished-sync).

## Requirements

- WordPress 6.7+
- PHP 8.1+
- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/) (free version)

## Installation

1. Download or clone into `wp-content/plugins/shelter-events-wrapper/`.
2. Activate **The Events Calendar** first, then activate **Shelter Events Wrapper**.
3. Navigate to **Events → Shelter Programs** in the admin.
4. Click **Generate Now** to create upcoming event instances.

## How It Works

### Config-Driven Architecture

All program schedules, venues, organizers, and generation settings live in `config/events.json`. To add a new recurring program, you edit one JSON file — no PHP changes required.

```
config/
├── events.json        → Program definitions, venues, organizers, generation settings
├── taxonomies.json    → Custom taxonomy for program categories
└── abilities.json     → WP 6.9 Abilities API registration
```

### Event Generation

A daily WP-Cron job reads the program configs and creates individual TEC event posts for each scheduled occurrence over the next N weeks (default: 8). Duplicate prevention uses a deterministic SHA-256 hash stored as post meta — re-running generation is always safe.

Each generated event gets:
- A title like "BINGO Night — Tuesday, April 15, 2025"
- Proper start/end times from the config
- A linked TEC Venue and Organizer (created automatically if they don't exist)
- Custom meta (`_shelter_program_slug`, capacity, contact info, etc.)
- Assignment to the `shelter_program` taxonomy

### Adding a New Program

Edit `config/events.json` and add a new entry under `programs`:

```json
{
  "programs": {
    "adoption-saturday": {
      "title": "Adoption Saturday",
      "description": "Meet adoptable pets every Saturday!",
      "category": "adoption",
      "recurrence": {
        "days": ["saturday"],
        "start_time": "10:00",
        "end_time": "15:00",
        "timezone": "America/New_York"
      },
      "venue_slug": "shelter-main-hall",
      "organizer_slug": "vcpa-humane",
      "cost": "0",
      "tags": ["adoption", "pets"],
      "event_cat": ["Adoption"],
      "meta": {
        "_shelter_program": "adoption-saturday",
        "_shelter_contact_email": "adopt@vcpahumane.org"
      }
    }
  }
}
```

Then click **Generate Now** or wait for the daily cron.

## Architecture

```
config/              → JSON definitions (programs, taxonomies, abilities)
includes/core/       → Config loader, Taxonomy registry, Event generator
includes/abilities/  → WP 6.9 Abilities API callbacks
includes/            → Admin page, Blocks, REST routes
blocks/              → Server-rendered Gutenberg block
templates/           → Block theme templates
assets/              → Editor JS, front-end CSS, admin CSS
.github/workflows/   → CI linting
```

Business logic lives in **abilities** and the **Event Generator** — thin, testable operations with JSON Schema validation. The admin UI, REST endpoints, and blocks are thin consumers that delegate to these.

## REST API

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/shelter-events/v1/programs` | GET | Public | List all configured programs |
| `/shelter-events/v1/upcoming?program=bingo` | GET | Public | Upcoming events, optionally filtered |
| `/shelter-events/v1/generate` | POST | Admin | Trigger event generation |
| `/shelter-events/v1/cancel` | POST | Editor | Cancel a specific event instance |

## Gutenberg Block

The **Shelter Event List** block (`shelter-events/event-list`) can be placed on any page or post. It supports:
- Filtering by program
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

## License

GPL-2.0-or-later.

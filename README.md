# 🌊 WAVE Stats Tracker

A water polo statistics tracking Progressive Web App (PWA) for the **Ottawa WAVE Aquatic Club**. Coaches and parents track live game stats on their phones, submit to a server, and admins reconcile submissions into an official game record with full statistical reports.

**Live:** [stats.ottawawaterpolo.com](https://stats.ottawawaterpolo.com)

---

## Features

### End-User Tracker (`stats-tracker.php`)
- **Live game tracking** — tap to record goals, assists, shots, saves, steals, blocks, exclusions, turnovers, swim-offs, and 5M penalties
- **Shot location map** — freeform tap on a pool diagram to mark where on the field the shot was taken
- **Net location map** — freeform tap on a head-on net view to mark exactly where the ball crossed the goal line (top corners, bottom corners, bunny)
- **Goal context** — record situation (man-up / even / man-down), play type (counter-attack / set-up), and shot expectedness
- **Assist picker** — select 1 or 2 assisting players; the goal scorer is automatically highlighted and excluded
- **Live game viewer (Watch tab)** — follow any live game in real time with score, period indicators, shot map, and event feed; viewers are notified when the game ends
- **Roster management** — import from PDF, add/edit players manually, cap number deduplication
- **My Games** — full box score, per-player stats, and report for every tracked game
- **Community Games** — browse and read reports for all officially approved games
- **Published Reports** — admin-pushed tournament and season reports readable by all users
- **Ask SkipShot** — natural language stats queries powered by Gemini AI
- **Offline-capable PWA** — installable on iOS and Android, service worker caching

### Admin Panel (`stats-admin.php`)
- **Dashboard** — sortable games table with submission counts and approval status; click any game to open a side drawer with full stats and report
- **Submission reconciliation** — accept one tracker's submission, average across multiple trackers, or enter stats manually
- **Roster management** — create and manage named rosters with age groups
- **Report generation** — generate game, tournament, or season reports; preview, print, or PDF export; publish to end users
- **Ask SkipShot (admin)** — Gemini NL query tool with access to all game data
- **Settings** — club name, brand colours, Gemini API key

### Report Engine (`stats-report.js`)
One shared file used by both admin and tracker — every report surface is identical:

| Section | Description |
|---|---|
| Results Table | W/L/T record with goal totals (multi-game only) |
| Team Box Score | Goals, shots, saves by period |
| Player Stats | Per-player table with G/Att % |
| Leaderboards | Top 5 per stat category |
| Play-by-Play | Chronological event log with running score |
| Team Comparison | Side-by-side bar chart for key metrics |
| Goalie Stats | Save percentage table |
| Shooting by Situation | Man-up / even / man-down conversion % |
| 5M Penalties | For and against breakdown |
| Shot Zone Summary | Zones with attempt count and conversion % |
| Shot Map (Field) | SVG pool overhead view of shot origins |
| Net Map | Two head-on nets — WAVE attacking and opponent attacking — with freeform dot placement |
| Game Flow | Goals-per-period bar chart |
| Top Performers | Best shooting %, playmaker, steals, blocks, KO earned, save % |
| Turnovers vs Steals | Team net possession + per-player table |

---

## Architecture

```
stats-tracker.php   ~5,700 lines   End-user PWA (React + Babel in-browser)
stats-admin.php     ~3,200 lines   Admin panel (PHP views + vanilla JS)
stats-api.php       ~1,370 lines   REST API + SQLite schema
stats-report.js       ~775 lines   Shared report engine (crunch + render)
service-worker.js                  PWA caching
manifest.json                      PWA manifest
data/                              ← gitignored
  stats.db                         SQLite database
  stats_admin_token.txt            Admin token (auto-generated)
```

### Database Tables

| Table | Purpose |
|---|---|
| `games` | All registered games |
| `submissions` | Raw tracker inputs (multiple per game) |
| `official_games` | Approved/reconciled record (one per game) |
| `live_games` | Live game state for the real-time viewer |
| `published_reports` | Admin-pushed reports for end users |
| `rosters` | Named player rosters with age groups |
| `team_names` | Team name autocomplete suggestions |
| `tournament_names` | Tournament name autocomplete suggestions |
| `settings` | Club branding and API keys |

---

## Installation

### Requirements
- PHP 8.0+
- SQLite3 (via PDO)
- Apache/Nginx or shared hosting (SiteGround compatible)

### Deploy
```bash
git clone https://github.com/your-org/wave-stats.git
```

Upload these files to your server root:
```
stats-tracker.php
stats-admin.php
stats-api.php
stats-report.js
service-worker.js
manifest.json
```

The `data/` directory and SQLite database are created automatically on first request. The admin token is written to `data/stats_admin_token.txt` — retrieve it via SSH or the database backup download endpoint.

### Admin Access
```
https://your-domain.com/stats-admin.php?t=YOUR_ADMIN_TOKEN
```

### Settings
In the admin panel → Settings, configure:
- **Club Name** — displayed throughout the app
- **Primary / Secondary Colour** — brand colours
- **Gemini API Key** — required for Ask SkipShot AI queries

---

## Event Data Model

Each tracked event stored in `events[]`:

```js
{
  id:           "uid",           // unique event ID
  stat:         "goal",          // see stat types below
  playerId:     "uid",           // WAVE player (null for opponent events)
  ts:           1234567890,      // Unix timestamp ms
  period:       "1Q",            // 1Q | 2Q | 3Q | 4Q | OT | SO
  shotLocation: { x, y },        // % coords on pool overhead map (field position)
  netLocation:  { x, y },        // % coords on head-on net face (goal line position)
  situation:    "man_up",        // man_up | even | man_down
  playType:     "counter",       // counter | setup  (goals only)
  shotExp:      "unexpected",    // expected | unexpected | unsure
  oppNum:       8,               // opponent cap number
}
```

### Stat Types

| Stat | Description |
|---|---|
| `goal` | WAVE goal scored |
| `assist` | WAVE assist |
| `shot` | WAVE shot (saved or missed) |
| `save` | WAVE goalie save |
| `steal` | WAVE steal |
| `block` | WAVE block |
| `turnover` | WAVE turnover |
| `kickout` | WAVE player excluded |
| `kickout_earned` | WAVE drew an exclusion |
| `swimoff` | Swim-off |
| `goals_against` | Goal conceded |
| `shot_against` | Shot on WAVE goal |
| `penalty_5m_goal_for` | 5M penalty scored by WAVE |
| `penalty_5m_miss_for` | 5M penalty missed by WAVE |
| `penalty_5m_goal_against` | 5M penalty conceded |
| `penalty_5m_block` | 5M penalty blocked by WAVE goalie |

---

## API Reference

### Public GET Endpoints

| Action | Description |
|---|---|
| `get_games` | All active games |
| `get_official_game?game_key=` | Official record + player data |
| `get_official_games` | All finalized games (community feed) |
| `get_rosters` | All rosters |
| `get_roster_players?roster_key=` | Players for one roster |
| `get_settings` | Club branding |
| `get_published_reports` | Published report stubs |
| `get_published_report?report_key=` | Full published report |
| `get_live_games` | Currently live games |
| `get_live_game?game_key=` | Full live game state |
| `ask_data_public` | Gemini NL query (public scope) |

### Public POST Endpoints

| Action | Description |
|---|---|
| `create_game` | Register a new game |
| `submit_stats` | Submit events + players |
| `sync_live_events` | Push live game state (called on every stat) |
| `update_roster` | Save imported roster |

### Admin POST Endpoints *(token required)*

| Action | Description |
|---|---|
| `select_submission` | Approve a submission as official |
| `average_submissions` | Reconcile by averaging across trackers |
| `close_game` | Finalize with manual score/events |
| `publish_report` | Push report to end users |
| `save_roster` | Create/update a roster |
| `delete_game` | Remove game and all submissions |
| `set_game_status` | Change game status |
| `save_settings` | Update club settings |
| `download_db` | Download SQLite backup |

---

## Development Notes

### Single Source of Truth for Reports
`stats-report.js` is loaded by both `stats-admin.php` and `stats-tracker.php` via `<script src="stats-report.js">`. Any change to report logic, sections, or rendering only needs to be made once here.

Key functions:
- `crunchReportData(games, title, subtitle, scope)` — builds the report data object from raw events
- `renderReportHTML(data)` — renders all sections to an HTML string
- `buildTrackerReport(games, db, title, subtitle, scope)` — tracker adapter (converts local state to admin input shape)
- `trackerGameToAdminShape(game, db)` — converts a single tracker game to the canonical input shape

### Player IDs
Player IDs are ephemeral UUIDs generated at game creation. The same ID must exist in both `game.events[].playerId` and the `players[]` array sent with submission. Mismatched IDs cause empty player stats in reports.

### Score Display
Always use `??` not `||` when displaying scores — a score of `0` is falsy and will display as `?` with `||`.

```js
// Wrong — shows '?' when score is 0
`${game.wave_score || '?'}`

// Correct
`${game.wave_score ?? '?'}`
```

### PHP View Chain (admin)
The admin panel uses a chain of `if/elseif` PHP blocks. Always verify the chain is intact after edits:
```bash
grep "^<?php if\|^<?php elseif\|^<?php endif" stats-admin.php
```

### Script Block Isolation (admin)
Each admin view has its own `<script>` block with its own `const TOKEN`. Never merge view script blocks — duplicate `const` declarations in the same scope will silently break both.

### Live Sync Architecture
The tracker calls `syncLiveEvents(game)` via a `useEffect` that watches `game.events.length`. This fires after React has committed the new state, ensuring the full authoritative event list is sent — not a stale closure snapshot.

---

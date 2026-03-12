# WAVE Stats Tracker 🌊

A self-hosted, mobile-first water polo stats tracking system. Built for clubs that want full control of their data — no subscriptions, no third-party platforms, just PHP files on your own server.

---

## What it does

- **Live game tracking** — tap to record goals, assists, shots, steals, blocks, kickouts, saves, 5M penalties, and more
- **Shot location map** — tap the pool diagram to mark where each shot came from
- **Multi-tracker support** — multiple people can track the same game simultaneously; coach submits, admin approves
- **Automated reports** — full stat reports with box scores, leaderboards, shot maps, game flow charts, and top performers; publish to end users with one click
- **AI analysis** — ask natural language questions about your stats ("Who had the most assists this season?") powered by Google Gemini
- **PWA** — installs on iOS and Android home screens, works offline between syncs
- **Hat trick detection** — 🎩 icon appears in the live feed when a player scores their 3rd goal
- **Admin dashboard** — approve/delete submissions, download PDF reports, manage rosters and tournaments

---

## Stack

- **Backend:** PHP 8+ with SQLite — zero dependencies, no Composer, no npm
- **Frontend:** React 18 via CDN — no build step
- **AI:** Google Gemini 2.5 Flash API (optional, free tier available)
- **Storage:** Single SQLite `.db` file — everything in one place

---

## Files

| File | Purpose |
|------|---------|
| `stats-tracker.php` | End-user PWA (players, coaches, parents) |
| `stats-admin.php` | Admin dashboard (approve stats, manage rosters, publish reports) |
| `stats-api.php` | JSON API backend shared by both |
| `service-worker.js` | PWA offline caching |
| `manifest.json` | PWA install manifest |

---

## Setup

### Requirements
- PHP 8.0+ with the `pdo_sqlite` extension enabled
- Any web server (Apache, Nginx, Caddy)
- A writable `data/` directory

### Installation

1. Upload all files to your web server
2. Create a `data/` directory beside the PHP files and make it writable:
   ```bash
   mkdir data && chmod 755 data
   ```
3. Visit `stats-tracker.php` in a browser — the SQLite database is created automatically on first load
4. Visit `stats-admin.php` — you will be prompted to set an admin password on first visit
5. Add your club name and colours in **Settings**

### Protect the data directory

The `data/` folder contains your database and admin token. Block direct web access to it.

**Apache** — add to `.htaccess`:
```apache
<Directory "data">
    Require all denied
</Directory>
```

**Nginx** — add to your server block:
```nginx
location /data/ { deny all; }
```

---

## Optional: AI features

1. Get a free API key at [aistudio.google.com](https://aistudio.google.com/app/apikey)
2. Paste it into **Settings → Gemini API Key** in the admin panel
3. The "Ask the Data" feature will appear in Reports and on the end-user stats screen

---

## Usage

### Trackers and coaches
1. Open `stats-tracker.php` on your phone and tap **Add to Home Screen** to install it as an app
2. Select your game from the list
3. Tap stat tiles to record events — the shot location map appears automatically for goals and shots
4. Submit when the game ends

### Admins
1. Open `stats-admin.php` with your admin token
2. Go to **Games** — pending submissions appear under each game
3. Tap **✓ Approve** on the correct submission to finalize stats
4. Tap **👁 View** to see the full report, **⬇ PDF** to download it, or **📣 Push** to publish it to end users

---

## Customisation

Colours are set in the admin panel under Settings:
- **Primary colour** — buttons, headers, nav (default: `#003087` navy)
- **Secondary colour** — accents and highlights (default: `#FFC72C` gold)

Club name and PWA icons (`wave-icon-192.png`, `wave-icon-512.png`) can also be replaced with your own branding.

---

## Security notes

- The `data/` directory contains your SQLite database — never expose it publicly (see setup above)
- The admin token lives in `data/stats_admin_token.txt` — treat it like a password
- The Gemini API key is stored server-side only and is never sent to end users

---

## Contributing

Pull requests are welcome. The app is intentionally contained in three PHP files to keep deployment dead simple — no build pipeline, no dependencies to manage. Please keep it that way.

---

## Licence

MIT — use it freely, adapt it for your club, just don't hold us liable if your goalie stats are wrong.

<?php
// ─── WAVE Stats API ───────────────────────────────────────────────────────────
// stats-api.php — place at root alongside the concussion tracker
// Database: data/stats.db   Admin token: data/stats_admin_token.txt

define('DB_PATH', __DIR__ . '/data/stats.db');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Catch PHP fatal errors and return JSON instead of blank 500
set_exception_handler(function(Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $err['message'], 'file' => basename($err['file']), 'line' => $err['line']]);
    }
});

// ─── Database ─────────────────────────────────────────────────────────────────
function getDB(): PDO {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        if (!mkdir($dataDir, 0755, true)) {
            throw new Exception('Cannot create data/ directory. Check server file permissions.');
        }
    }
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");

    $db->exec("CREATE TABLE IF NOT EXISTS team_names (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        age_group  TEXT DEFAULT '',
        gender     TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now')),
        UNIQUE(name, age_group, gender)
    )");
    // Migrations for existing DBs
    try { $db->exec("ALTER TABLE team_names ADD COLUMN gender TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE team_names ADD COLUMN age_group TEXT DEFAULT ''"); } catch(Exception $e) {}
    // If old DB had UNIQUE(name) only, old entries with blank age_group+gender may block new inserts.
    // Migrate: copy to temp, recreate with composite unique, copy back.
    $hasComposite = false;
    foreach ($db->query("PRAGMA index_list(team_names)")->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        if ($idx['unique']) {
            $cols = array_column($db->query("PRAGMA index_info({$idx['name']})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (count($cols) >= 3) { $hasComposite = true; break; }
        }
    }
    if (!$hasComposite) {
        // Rebuild table with composite unique constraint
        $db->exec("BEGIN");
        $db->exec("CREATE TABLE IF NOT EXISTS team_names_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
            age_group TEXT DEFAULT '', gender TEXT DEFAULT '',
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(name, age_group, gender))");
        $db->exec("INSERT OR IGNORE INTO team_names_new (id, name, age_group, gender, created_at)
            SELECT id, name, COALESCE(age_group,''), COALESCE(gender,''), created_at FROM team_names");
        $db->exec("DROP TABLE team_names");
        $db->exec("ALTER TABLE team_names_new RENAME TO team_names");
        $db->exec("COMMIT");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS tournament_names (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL UNIQUE,
        season     TEXT DEFAULT '',
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rosters (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        roster_key TEXT NOT NULL UNIQUE,
        name       TEXT NOT NULL,
        age_group  TEXT NOT NULL DEFAULT '',
        players    TEXT NOT NULL DEFAULT '[]',
        created_at TEXT DEFAULT (datetime('now')),
        updated_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS admin_tokens (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        token      TEXT NOT NULL UNIQUE,
        note       TEXT,
        created_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS games (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        game_key   TEXT NOT NULL UNIQUE,
        tournament TEXT DEFAULT '',
        season     TEXT DEFAULT '',
        game_date  TEXT NOT NULL,
        wave_team  TEXT NOT NULL,
        opponent   TEXT NOT NULL,
        age_group  TEXT DEFAULT '',
        players    TEXT NOT NULL DEFAULT '[]',
        status     TEXT DEFAULT 'active',
        created_at TEXT DEFAULT (datetime('now'))
    )");
    // Migration: older DBs may not have age_group on games
    try { $db->exec("ALTER TABLE games ADD COLUMN age_group TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE rosters ADD COLUMN tournament TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE games ADD COLUMN season TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE official_games ADD COLUMN events TEXT NOT NULL DEFAULT '[]'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE official_games ADD COLUMN method TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE submissions ADD COLUMN status TEXT DEFAULT 'pending'"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE published_reports ADD COLUMN season TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE published_reports ADD COLUMN subtitle TEXT DEFAULT ''"); } catch(Exception $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS submissions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        game_key     TEXT NOT NULL,
        tracker_name TEXT NOT NULL,
        is_coach     INTEGER DEFAULT 0,
        wave_score   INTEGER DEFAULT 0,
        opp_score    INTEGER DEFAULT 0,
        events       TEXT NOT NULL DEFAULT '[]',
        submitted_at TEXT DEFAULT (datetime('now')),
        status       TEXT DEFAULT 'pending'
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS official_games (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        game_key     TEXT NOT NULL UNIQUE,
        wave_score   INTEGER DEFAULT 0,
        opp_score    INTEGER DEFAULT 0,
        method       TEXT,
        events       TEXT NOT NULL DEFAULT '[]',
        finalized_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS published_reports (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        report_key   TEXT NOT NULL UNIQUE,
        type         TEXT NOT NULL,
        title        TEXT NOT NULL,
        subtitle     TEXT DEFAULT '',
        season       TEXT DEFAULT '',
        data         TEXT NOT NULL DEFAULT '{}',
        published_at TEXT DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key   TEXT NOT NULL PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('club_name','WAVE')");
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('primary_color','#003087')");
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('secondary_color','#FFC72C')");

    if ($db->query("SELECT COUNT(*) FROM admin_tokens")->fetchColumn() == 0) {
        $token = bin2hex(random_bytes(16));
        $db->prepare("INSERT INTO admin_tokens (token, note) VALUES (?,?)")
           ->execute([$token, 'Auto-generated']);
        @file_put_contents(__DIR__ . '/data/stats_admin_token.txt', $token . "\n");
    }
    return $db;
}

function requireAdmin(PDO $db): void {
    $token = $_GET['t'] ?? $GLOBALS['body']['token'] ?? '';
    $ok = $db->prepare("SELECT id FROM admin_tokens WHERE token = ?");
    $ok->execute([$token]);
    if (!$ok->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid admin token']);
        exit;
    }
}

function gameKey(string $date, string $waveTeam, string $opponent, string $tournament): string {
    return md5(date('Y-m-d', strtotime($date)) . '|' . strtolower(trim($waveTeam)) . '|' . strtolower(trim($opponent)) . '|' . strtolower(trim($tournament)));
}

try {
    $db = getDB();

    // ── GET ──────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';

        // Public: tracker fetches available games + rosters
        if ($action === 'get_games') {
            $rows = $db->query("
                SELECT game_key, tournament, season, game_date, wave_team, opponent, age_group, players, status
                FROM games WHERE status != 'closed'
                ORDER BY game_date DESC, created_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['players'] = json_decode($r['players'], true);
            echo json_encode(['ok' => true, 'games' => $rows]);
            exit;
        }

        // Public: team name suggestions for end-user game creation
        if ($action === 'get_team_names') {
            $rows = $db->query("SELECT id, name, age_group, gender FROM team_names ORDER BY name, age_group, gender")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'teams' => $rows]);
            exit;
        }

        // Public: tournament name suggestions for end-user game creation
        if ($action === 'get_tournament_names') {
            $rows = $db->query("SELECT name, season FROM tournament_names ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'tournaments' => $rows]);
            exit;
        }

        // Public: returns team names + age groups for the "pick opponent" dropdown
        // Does NOT return player lists — that requires a separate fetch per roster
        if ($action === 'get_rosters') {
            $rows = $db->query("
                SELECT roster_key, name, age_group, tournament,
                       json_array_length(players) as player_count,
                       updated_at
                FROM rosters ORDER BY age_group ASC, name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'rosters' => $rows]);
            exit;
        }

        // Public: returns full player list for one roster (called when user imports it)
        if ($action === 'get_roster_players') {
            $key = $_GET['roster_key'] ?? '';
            if (!$key) { echo json_encode(['ok' => false, 'error' => 'roster_key required']); exit; }
            $r = $db->prepare("SELECT name, age_group, tournament, players FROM rosters WHERE roster_key = ?");
            $r->execute([$key]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'error' => 'Roster not found']); exit; }
            $row['players'] = json_decode($row['players'], true);
            echo json_encode(['ok' => true, 'roster' => $row]);
            exit;
        }

        // Public: official approved record for a game (end-user Official Reports tab)
        if ($action === 'get_official_game') {
            $key = $_GET['game_key'] ?? '';
            if (!$key) { echo json_encode(['ok' => false, 'error' => 'game_key required']); exit; }
            $g = $db->prepare("SELECT game_date, wave_team, opponent, tournament, age_group, players FROM games WHERE game_key=?");
            $g->execute([$key]);
            $gameRow = $g->fetch(PDO::FETCH_ASSOC);
            if (!$gameRow) { echo json_encode(['ok' => false, 'error' => 'Game not found']); exit; }
            $gameRow['players'] = json_decode($gameRow['players'], true) ?? [];
            $off = $db->prepare("SELECT wave_score, opp_score, method, events, finalized_at FROM official_games WHERE game_key=?");
            $off->execute([$key]);
            $offRow = $off->fetch(PDO::FETCH_ASSOC);
            if (!$offRow) { echo json_encode(['ok' => false, 'error' => 'No official record yet']); exit; }
            $offRow['events'] = json_decode($offRow['events'], true) ?? [];
            echo json_encode(['ok' => true, 'game' => $gameRow, 'official' => $offRow]);
            exit;
        }

        // Public: branding settings
        if ($action === 'download_db') {
            requireAdmin($db);
            $file = DB_PATH;
            if (!file_exists($file)) throw new Exception('Database file not found.');
            $filename = 'stats-backup-' . date('Y-m-d') . '.db';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: no-store');
            readfile($file);
            exit;
        }

        if ($action === 'get_settings') {
            $rows = $db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                if ($r['key'] === 'gemini_api_key') continue; // never expose key publicly
                $out[$r['key']] = $r['value'];
            }
            // Expose whether AI is available without revealing the key
            $hasAi = (bool)$db->query("SELECT value FROM settings WHERE key='gemini_api_key'")->fetchColumn();
            $out['has_ai'] = $hasAi;
            echo json_encode(['ok' => true, 'settings' => $out]);
            exit;
        }

    if ($action === 'save_settings') {
            requireAdmin($db);
            $allowed = ['club_name','primary_color','secondary_color','gemini_api_key'];
            $data = $GLOBALS['body']['settings'] ?? [];
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)");
            foreach ($allowed as $k) {
                if (array_key_exists($k, $data)) $stmt->execute([$k, trim($data[$k])]);
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        // Public: all officially finalized games (community feed)
        if ($action === 'get_community_games') {
            $rows = $db->query("
                SELECT g.game_key, g.game_date, g.wave_team, g.opponent, g.tournament, g.age_group,
                       o.wave_score, o.opp_score, o.finalized_at
                FROM official_games o
                JOIN games g ON g.game_key = o.game_key
                ORDER BY g.game_date DESC, o.finalized_at DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'games' => $rows]);
            exit;
        }

        // Public: published reports (tournament summaries, season stats, player reports)
        if ($action === 'get_published_reports') {
            $rows = $db->query("SELECT id, report_key, type, title, subtitle, season, published_at FROM published_reports ORDER BY published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'reports' => $rows]);
            exit;
        }

        // Public: fetch a single published report's full data
        if ($action === 'get_published_report') {
            $key = $_GET['report_key'] ?? '';
            if (!$key) { echo json_encode(['ok' => false, 'error' => 'report_key required']); exit; }
            $r = $db->prepare("SELECT * FROM published_reports WHERE report_key = ?");
            $r->execute([$key]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['ok' => false, 'error' => 'Report not found']); exit; }
            $row['data'] = json_decode($row['data'], true);
            echo json_encode(['ok' => true, 'report' => $row]);
            exit;
        }

        // Public: natural language query against all approved game data (no token required)
        requireAdmin($db);

        if ($action === 'list_games') {
            $rows = $db->query("
                SELECT g.*, COUNT(s.id) as submission_count,
                    GROUP_CONCAT(s.tracker_name || ':' || s.is_coach, '|') as trackers
                FROM games g
                LEFT JOIN submissions s ON s.game_key = g.game_key
                GROUP BY g.game_key ORDER BY g.game_date DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $offMap = array_column(
                $db->query("SELECT game_key, method FROM official_games")->fetchAll(PDO::FETCH_ASSOC),
                'method', 'game_key'
            );
            foreach ($rows as &$r) {
                $r['players']   = json_decode($r['players'], true);
                $r['finalized'] = isset($offMap[$r['game_key']]);
                $r['method']    = $offMap[$r['game_key']] ?? null;
                $trackers = [];
                foreach (array_filter(explode('|', $r['trackers'] ?? '')) as $t) {
                    if (!str_contains($t, ':')) continue;
                    [$name, $isCoach] = explode(':', $t, 2);
                    $trackers[] = ['name' => $name, 'is_coach' => (bool)(int)$isCoach];
                }
                $r['trackers'] = $trackers;
            }
            echo json_encode(['ok' => true, 'games' => $rows]);

        } elseif ($action === 'get_submissions') {
            $key = $_GET['game_key'] ?? '';
            if (!$key) throw new Exception('game_key required');

            $game = $db->prepare("SELECT * FROM games WHERE game_key = ?");
            $game->execute([$key]);
            $gameRow = $game->fetch(PDO::FETCH_ASSOC);
            if ($gameRow) $gameRow['players'] = json_decode($gameRow['players'], true);

            // Build a player id→info map for display
            $playerMap = [];
            foreach (($gameRow['players'] ?? []) as $p) {
                $playerMap[$p['id']] = ['name' => $p['name'], 'number' => $p['number']];
            }

            $subs = $db->prepare("SELECT * FROM submissions WHERE game_key = ? ORDER BY is_coach DESC, submitted_at ASC");
            $subs->execute([$key]);
            $rows = $subs->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $events = json_decode($r['events'], true) ?? [];
                $r['is_coach'] = (bool)(int)$r['is_coach'];
                $r['event_count'] = count($events);
                // Build per-player stat counts
                $ps = [];
                foreach ($events as $ev) {
                    $pid  = $ev['playerId'] ?? '';
                    $stat = $ev['stat']     ?? '';
                    if ($pid && $stat) {
                        $ps[$pid][$stat] = ($ps[$pid][$stat] ?? 0) + 1;
                    }
                }
                $r['playerStats'] = $ps;
                unset($r['events']); // don't send full event list, just stats
            }
            unset($r);

            $off = $db->prepare("SELECT * FROM official_games WHERE game_key = ?");
            $off->execute([$key]);
            $offRow = $off->fetch(PDO::FETCH_ASSOC);
            if ($offRow) $offRow['events'] = json_decode($offRow['events'], true);

            echo json_encode(['ok' => true, 'game' => $gameRow, 'submissions' => $rows, 'official' => $offRow ?: null, 'players' => $playerMap]);
        } else {
            // Bare GET with no action — return API status (useful for testing)
            echo json_encode(['ok' => true, 'status' => 'WAVE Stats API running', 'actions' => ['get_games', 'list_games', 'get_submissions']]);
        }
        exit;
    }

    // ── POST ─────────────────────────────────────────────────────────────────
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $GLOBALS['body'] = $body;
    $action = $body['action'] ?? '';

    if ($action === 'get_game_detail') {
        requireAdmin($db);
        $key = $body['game_key'] ?? '';
        if (!$key) throw new Exception('game_key required');
        $game = $db->prepare("SELECT * FROM games WHERE game_key = ?");
        $game->execute([$key]);
        $gameRow = $game->fetch(PDO::FETCH_ASSOC);
        if ($gameRow) $gameRow['players'] = json_decode($gameRow['players'], true);
        $subs = $db->prepare("SELECT id, tracker_name, wave_score, opp_score, is_coach, submitted_at, events FROM submissions WHERE game_key = ? ORDER BY is_coach DESC, submitted_at ASC");
        $subs->execute([$key]);
        $subRows = $subs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($subRows as &$r) {
            $evts = json_decode($r['events'], true) ?? [];
            $r['event_count'] = count($evts);
            $r['is_coach']    = (bool)(int)$r['is_coach'];
            unset($r['events']);
        }
        unset($r);
        $off = $db->prepare("SELECT * FROM official_games WHERE game_key = ?");
        $off->execute([$key]);
        $offRow = $off->fetch(PDO::FETCH_ASSOC);
        if ($offRow) $offRow['events'] = json_decode($offRow['events'], true);
        echo json_encode(['ok' => true, 'game' => $gameRow, 'submissions' => $subRows,
            'official' => $offRow ?: null, 'finalized' => (bool)$offRow]);
        exit;
    }

    if ($action === 'save_settings') {
        requireAdmin($db);
        $allowed = ['club_name','primary_color','secondary_color','gemini_api_key'];
        $data = $body['settings'] ?? [];
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)");
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) $stmt->execute([$k, trim($data[$k])]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'ask_data_public') {
        $geminiKey = $db->query("SELECT value FROM settings WHERE key='gemini_api_key'")->fetchColumn();
        if (!$geminiKey) { echo json_encode(['ok' => false, 'error' => 'AI analysis is not available.']); exit; }

        $question = trim($body['question'] ?? '');
        if (!$question) throw new Exception('No question provided.');

        // Rate limiting: max 20 public AI requests per hour per IP
        $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ipHash  = hash('sha256', $ip);
        $window  = floor(time() / 3600);
        $rlKey   = "rl_public_ai_{$ipHash}_{$window}";
        $rlRow   = $db->prepare("SELECT value FROM settings WHERE key=?"); $rlRow->execute([$rlKey]);
        $rlCount = (int)($rlRow->fetchColumn() ?: 0);
        if ($rlCount >= 20) { echo json_encode(['ok' => false, 'error' => 'Too many requests. Please try again later.']); exit; }
        $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)")->execute([$rlKey, $rlCount + 1]);

        // Load all approved games
        $gRows = $db->query("SELECT g.*, og.wave_score as off_ws, og.opp_score as off_os
            FROM games g JOIN official_games og ON og.game_key=g.game_key
            ORDER BY g.game_date ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$gRows) { echo json_encode(['ok' => false, 'error' => 'No approved games available yet.']); exit; }

        $STAT_KEYS = ['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save','goals_against'];
        $summary = [];
        foreach ($gRows as $g) {
            $players = json_decode($g['players'] ?? '[]', true) ?: [];
            $subStmt = $db->prepare("SELECT events, wave_score, opp_score FROM submissions WHERE game_key=? ORDER BY is_coach DESC LIMIT 1");
            $subStmt->execute([$g['game_key']]);
            $sub  = $subStmt->fetch(PDO::FETCH_ASSOC);
            $evts = $sub ? (json_decode($sub['events'], true) ?: []) : [];

            $playerStats = [];
            foreach ($players as $p) {
                $row = ['name' => $p['name'] ?? ('Cap '.$p['number']), 'cap' => $p['number'] ?? '?', 'isGoalie' => !empty($p['isGoalie']), 'stats' => []];
                foreach ($STAT_KEYS as $sk) {
                    $c = count(array_filter($evts, fn($e) => ($e['playerId']??'') === ($p['id']??'') && ($e['stat']??'') === $sk));
                    if ($c > 0) $row['stats'][$sk] = $c;
                }
                if (!empty($row['stats'])) $playerStats[] = $row;
            }

            $forShots = array_filter($evts, fn($e) => in_array($e['stat']??'', ['goal','shot']));
            $situations = ['man_up'=>['shots'=>0,'goals'=>0],'even'=>['shots'=>0,'goals'=>0],'man_down'=>['shots'=>0,'goals'=>0]];
            foreach ($forShots as $e) {
                $sit = $e['situation'] ?? 'even';
                if (!isset($situations[$sit])) $situations[$sit] = ['shots'=>0,'goals'=>0];
                $situations[$sit]['shots']++;
                if ($e['stat']==='goal') $situations[$sit]['goals']++;
            }

            $summary[] = [
                'game'       => $g['wave_team'].' vs '.$g['opponent'],
                'date'       => substr($g['game_date']??'',0,10),
                'tournament' => $g['tournament'] ?? '',
                'ageGroup'   => $g['age_group']  ?? '',
                'score'      => ($g['off_ws'] ?? $sub['wave_score'] ?? '?').'-'.($g['off_os'] ?? $sub['opp_score'] ?? '?'),
                'players'    => $playerStats,
                'situations' => $situations,
                'totalShots' => count($forShots),
                'totalGoals' => count(array_filter($evts, fn($e) => ($e['stat']??'')==='goal')),
            ];
        }

        $clubName = $db->query("SELECT value FROM settings WHERE key='club_name'")->fetchColumn() ?: 'WAVE';
        $dataJson = json_encode($summary, JSON_PRETTY_PRINT);

        $systemPrompt = <<<PROMPT
You are a friendly water polo stats assistant for {$clubName} players, parents, and fans. Answer questions about the team's performance in plain, conversational English.

You MUST respond with a single valid JSON object and nothing else — no markdown, no backticks, no explanation outside the JSON.

The JSON must have exactly these fields:
- "answer": string — 1–3 sentences, plain English, encouraging and specific, include key numbers inline.
- "table": null OR an object with:
  - "headers": array of column name strings
  - "rows": array of arrays (each row is an array of cell values, strings or numbers)

Include a table when the question asks for rankings, comparisons, top/bottom lists, or per-player/per-game breakdowns. Omit the table (set to null) for simple one-fact questions.

Domain rules:
- "Goals" and "assists" refer to {$clubName} players. "Goals against" are opponent goals.
- "Kickout" = foul drawn against {$clubName} (man-down for us). "Kickout earned" = foul drawn by {$clubName} (man-up for us).
- "Man-up" = power play for {$clubName}. "Man-down" = penalty kill.
- Conversion rate = goals ÷ shots as a percentage.
- If data is insufficient, set answer to an honest explanation and table to null.
- Never mention JSON, data structures, or technical implementation.

Example response for "Who are the top 5 scorers?":
{"answer":"Sarah leads the team with 12 goals across 6 games, closely followed by Emma with 9. Both players have been consistent contributors throughout the season.","table":{"headers":["Rank","Player","Cap","Goals","Assists","Shots"],"rows":[[1,"Sarah",7,12,4,31],[2,"Emma",3,9,6,24],[3,"Olivia",11,7,2,19],[4,"Maya",5,6,8,18],[5,"Aisha",9,5,3,14]]}}
PROMPT;

        $userMessage = "Game data:\n{$dataJson}\n\nQuestion: {$question}";

        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiKey}";
        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
            'generationConfig'   => ['temperature' => 0.2, 'maxOutputTokens' => 800, 'responseMimeType' => 'application/json'],
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>30]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw) throw new Exception('No response from AI.');
        $resp = json_decode($raw, true);
        if ($code !== 200) throw new Exception($resp['error']['message'] ?? "AI error $code");
        $rawText = trim($resp['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if (!$rawText) throw new Exception('AI returned an empty answer.');

        $parsed = json_decode($rawText, true);
        if (!$parsed || !isset($parsed['answer'])) {
            echo json_encode(['ok' => true, 'answer' => $rawText, 'table' => null, 'games_used' => count($gRows)]);
            exit;
        }

        echo json_encode(['ok' => true, 'answer' => $parsed['answer'], 'table' => $parsed['table'] ?? null, 'games_used' => count($gRows)]);
        exit;
    }

    if ($action === 'create_game') {
        // Public endpoint — end users create their own games
        $d = $body['data'] ?? [];
        foreach (['game_date','wave_team','opponent'] as $req)
            if (empty($d[$req])) throw new Exception("$req is required");
        $key = gameKey($d['game_date'], $d['wave_team'], $d['opponent'], $d['tournament'] ?? '');
        $db->prepare("INSERT OR IGNORE INTO games (game_key,tournament,season,game_date,wave_team,opponent,age_group,players,status) VALUES(?,?,?,?,?,?,?,?,?)")
           ->execute([$key, $d['tournament']??'', $d['season']??'', date('Y-m-d',strtotime($d['game_date'])), trim($d['wave_team']), trim($d['opponent']), trim($d['age_group']??''), json_encode($d['players']??[]), 'active']);
        // If game already exists (INSERT OR IGNORE skipped), update players roster if provided
        if (!empty($d['players'])) {
            $db->prepare("UPDATE games SET players=?, age_group=? WHERE game_key=? AND status != 'closed'")
               ->execute([json_encode($d['players']), trim($d['age_group']??''), $key]);
        }
        echo json_encode(['ok' => true, 'game_key' => $key]);

    } elseif ($action === 'update_game') {
        requireAdmin($db);
        $key = $body['game_key'] ?? '';
        $d   = $body['data'] ?? [];
        $db->prepare("UPDATE games SET tournament=?,season=?,game_date=?,wave_team=?,opponent=?,status=? WHERE game_key=?")
           ->execute([$d['tournament']??'', $d['season']??'', $d['game_date']??'', $d['wave_team']??'', $d['opponent']??'', $d['status']??'scheduled', $key]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'update_roster') {
        requireAdmin($db);
        $db->prepare("UPDATE games SET players=? WHERE game_key=?")
           ->execute([json_encode($body['players']??[]), $body['game_key']??'']);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'set_game_status') {
        requireAdmin($db);
        $status = $body['status'] ?? '';
        if (!in_array($status, ['scheduled','active','closed'])) throw new Exception('Invalid status');
        $db->prepare("UPDATE games SET status=? WHERE game_key=?")->execute([$status, $body['game_key']??'']);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete_game') {
        requireAdmin($db);
        $key = $body['game_key'] ?? '';
        foreach (['games','submissions','official_games'] as $t)
            $db->prepare("DELETE FROM $t WHERE game_key=?")->execute([$key]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'submit_stats') {
        $d = $body['data'] ?? [];
        if (empty($d['tracker_name'])) throw new Exception('tracker_name required');
        if (empty($d['game_key']))     throw new Exception('game_key required');
        // If game doesn't exist on server (e.g. created locally), auto-register it so the submission still lands
        $db->prepare("INSERT OR IGNORE INTO games (game_key, tournament, season, game_date, wave_team, opponent, age_group, players, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')")
           ->execute([
               $d['game_key'],
               $d['tournament'] ?? '',
               $d['season']     ?? '',
               $d['game_date']  ?? date('Y-m-d'),
               $d['wave_team']  ?? 'WAVE',
               $d['opponent']   ?? 'Opponent',
               $d['age_group']  ?? '',
               json_encode($d['players'] ?? []),
           ]);
        $db->prepare("INSERT INTO submissions (game_key,tracker_name,is_coach,wave_score,opp_score,events) VALUES(?,?,?,?,?,?)")
           ->execute([$d['game_key'], trim($d['tracker_name']), (int)($d['is_coach']??0), (int)($d['wave_score']??0), (int)($d['opp_score']??0), json_encode($d['events']??[])]);
        echo json_encode(['ok' => true, 'message' => 'Stats submitted!']);

    } elseif ($action === 'close_game') {
        requireAdmin($db);
        $key    = $body['data']['game_key'] ?? '';
        $result = $body['data']['result']   ?? [];
        if (!$key) throw new Exception('game_key required');

        $waveScore = (int)($result['wave_score'] ?? 0);
        $oppScore  = (int)($result['opp_score']  ?? 0);
        $method    = $result['_method'] ?? 'manual';

        // Rebuild events from playerStats for storage (synthetic events)
        $syntheticEvents = [];
        foreach (($result['playerStats'] ?? []) as $pid => $stats) {
            foreach ($stats as $stat => $count) {
                $count = is_numeric($count) ? (int)round((float)$count) : 0;
                for ($i = 0; $i < $count; $i++) {
                    $syntheticEvents[] = [
                        'id'       => "off_{$pid}_{$stat}_{$i}",
                        'stat'     => $stat,
                        'playerId' => $pid,
                        'ts'       => time() * 1000,
                        'official' => true,
                    ];
                }
            }
        }

        $db->prepare("INSERT INTO official_games (game_key,wave_score,opp_score,method,events)
            VALUES(?,?,?,?,?)
            ON CONFLICT(game_key) DO UPDATE SET
                wave_score=excluded.wave_score, opp_score=excluded.opp_score,
                method=excluded.method, events=excluded.events,
                finalized_at=datetime('now')")
           ->execute([$key, $waveScore, $oppScore, $method, json_encode($syntheticEvents)]);

        // Mark game as closed
        $db->prepare("UPDATE games SET status='closed' WHERE game_key=?")->execute([$key]);
        $db->prepare("UPDATE submissions SET status='reviewed' WHERE game_key=?")->execute([$key]);

        echo json_encode(['ok' => true, 'message' => 'Game closed and stats recorded.']);

    } elseif ($action === 'select_submission') {
        requireAdmin($db);
        $subId = (int)($body['submission_id'] ?? 0);
        $sub = $db->prepare("SELECT * FROM submissions WHERE id = ?");
        $sub->execute([$subId]);
        $row = $sub->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Submission not found');
        $db->prepare("INSERT INTO official_games (game_key,wave_score,opp_score,method,events) VALUES(?,?,?,?,?) ON CONFLICT(game_key) DO UPDATE SET wave_score=excluded.wave_score,opp_score=excluded.opp_score,method=excluded.method,events=excluded.events,finalized_at=datetime('now')")
           ->execute([$row['game_key'], $row['wave_score'], $row['opp_score'], 'selected:'.$subId.':'.$row['tracker_name'], $row['events']]);
        $db->prepare("UPDATE submissions SET status='merged' WHERE game_key=?")->execute([$row['game_key']]);
        $db->prepare("UPDATE submissions SET status='official' WHERE id=?")->execute([$subId]);
        echo json_encode(['ok' => true, 'message' => 'Selected as official.']);

    } elseif ($action === 'delete_submission') {
        requireAdmin($db);
        $subId = (int)($body['submission_id'] ?? 0);
        $sub = $db->prepare("SELECT game_key FROM submissions WHERE id = ?");
        $sub->execute([$subId]);
        $row = $sub->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Submission not found');
        // If this was the approved submission, also remove the official record
        $off = $db->prepare("SELECT method FROM official_games WHERE game_key = ?");
        $off->execute([$row['game_key']]);
        $offRow = $off->fetch(PDO::FETCH_ASSOC);
        if ($offRow && str_contains($offRow['method'] ?? '', 'selected:'.$subId.':')) {
            $db->prepare("DELETE FROM official_games WHERE game_key = ?")->execute([$row['game_key']]);
            $db->prepare("UPDATE games SET status='open' WHERE game_key = ?")->execute([$row['game_key']]);
        }
        $db->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'average_submissions') {
        requireAdmin($db);
        $key  = $body['game_key'] ?? '';
        $subs = $db->prepare("SELECT events, wave_score, opp_score FROM submissions WHERE game_key = ?");
        $subs->execute([$key]);
        $rows = $subs->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) throw new Exception('No submissions found');
        $n        = count($rows);
        $allEvts  = array_map(fn($r) => json_decode($r['events'], true), $rows);
        $pids     = []; $stats = [];
        foreach ($allEvts as $evts) foreach ($evts as $e) { $pids[$e['playerId']]=1; $stats[$e['stat']]=1; }
        $avgEvts  = [];
        foreach (array_keys($pids) as $pid) {
            foreach (array_keys($stats) as $stat) {
                $counts=[]; $locs=[];
                foreach ($allEvts as $evts) {
                    $c=0;
                    foreach ($evts as $e) { if ($e['playerId']===$pid && $e['stat']===$stat) { $c++; if (!empty($e['shotLocation'])) $locs[]=$e['shotLocation']; } }
                    $counts[]=$c;
                }
                $rounded=(int)round(array_sum($counts)/$n);
                for ($i=0;$i<$rounded;$i++) $avgEvts[]=['id'=>"avg_{$pid}_{$stat}_{$i}",'stat'=>$stat,'playerId'=>$pid,'ts'=>time()*1000,'shotLocation'=>$locs[$i%max(1,count($locs))]??null,'averaged'=>true,'raw_counts'=>$counts];
            }
        }
        $aw=(int)round(array_sum(array_column($rows,'wave_score'))/$n);
        $ao=(int)round(array_sum(array_column($rows,'opp_score'))/$n);
        $db->prepare("INSERT INTO official_games (game_key,wave_score,opp_score,method,events) VALUES(?,?,?,?,?) ON CONFLICT(game_key) DO UPDATE SET wave_score=excluded.wave_score,opp_score=excluded.opp_score,method=excluded.method,events=excluded.events,finalized_at=datetime('now')")
           ->execute([$key,$aw,$ao,"averaged:{$n}",json_encode($avgEvts)]);
        $db->prepare("UPDATE submissions SET status='merged' WHERE game_key=?")->execute([$key]);
        echo json_encode(['ok'=>true,'message'=>"Averaged {$n} submissions. Score: {$aw}–{$ao}."]);

    } elseif ($action === 'list_rosters') {
        requireAdmin($db);
        $rawRows = $db->query("SELECT id, roster_key, name, age_group, updated_at, players FROM rosters ORDER BY age_group, name")->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_map(function($r) {
            $decoded = json_decode($r['players'], true);
            $r['player_count'] = is_array($decoded) ? count($decoded) : 0;
            unset($r['players']);
            return $r;
        }, $rawRows);
        echo json_encode(['ok' => true, 'rosters' => $rows]);

    } elseif ($action === 'get_roster') {
        requireAdmin($db);
        $key = $body['roster_key'] ?? '';
        $r = $db->prepare("SELECT * FROM rosters WHERE roster_key=?"); $r->execute([$key]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Roster not found');
        $row['players'] = json_decode($row['players'], true);
        echo json_encode(['ok' => true, 'roster' => $row]);

    } elseif ($action === 'save_roster') {
        requireAdmin($db);
        $name       = trim($body['name']       ?? '');
        $ageGroup   = trim($body['age_group']  ?? '');
        $tournament = trim($body['tournament'] ?? '');
        $players    = $body['players']         ?? [];
        $existKey   = $body['roster_key']      ?? '';
        if (!$name) throw new Exception('name required');
        $key = $existKey ?: strtolower(preg_replace('/[^a-z0-9]+/i', '_', $ageGroup . '_' . $name)) . '_' . substr(md5($name.$ageGroup), 0, 6);
        $db->prepare("INSERT INTO rosters (roster_key, name, age_group, tournament, players) VALUES (?,?,?,?,?)
            ON CONFLICT(roster_key) DO UPDATE SET name=excluded.name, age_group=excluded.age_group, tournament=excluded.tournament, players=excluded.players, updated_at=datetime('now')")
           ->execute([$key, $name, $ageGroup, $tournament, json_encode($players)]);
        echo json_encode(['ok' => true, 'roster_key' => $key, 'message' => 'Roster saved.']);

    } elseif ($action === 'save_rosters_bulk') {
        requireAdmin($db);
        $teams = $body['teams'] ?? [];
        if (!$teams) throw new Exception('No teams provided');
        $saved = [];
        $stmt  = $db->prepare("INSERT INTO rosters (roster_key, name, age_group, tournament, players) VALUES (?,?,?,?,?)
            ON CONFLICT(roster_key) DO UPDATE SET name=excluded.name, age_group=excluded.age_group, tournament=excluded.tournament, players=excluded.players, updated_at=datetime('now')");
        foreach ($teams as $t) {
            $name       = trim($t['name']       ?? '');
            $ageGroup   = trim($t['age_group']  ?? '');
            $tournament = trim($t['tournament'] ?? '');
            $players    = $t['players']         ?? [];
            if (!$name) continue;
            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $ageGroup . '_' . $name)) . '_' . substr(md5($name.$ageGroup), 0, 6);
            $stmt->execute([$key, $name, $ageGroup, $tournament, json_encode($players)]);
            $saved[] = ['roster_key' => $key, 'name' => $name, 'count' => count($players)];
        }
        echo json_encode(['ok' => true, 'saved' => $saved]);

    } elseif ($action === 'delete_roster') {
        requireAdmin($db);
        $key = $body['roster_key'] ?? '';
        $db->prepare("DELETE FROM rosters WHERE roster_key=?")->execute([$key]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete_all_rosters') {
        requireAdmin($db);
        $db->exec("DELETE FROM rosters");
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete_all_games') {
        requireAdmin($db);
        $db->exec("DELETE FROM games");
        $db->exec("DELETE FROM submissions");
        $db->exec("DELETE FROM official_games");
        echo json_encode(['ok' => true, 'message' => 'All game data deleted.']);

    } elseif ($action === 'ask_data') {
        requireAdmin($db);

        // Load Gemini key from settings
        $geminiKey = $db->query("SELECT value FROM settings WHERE key='gemini_api_key'")->fetchColumn();
        if (!$geminiKey) throw new Exception('No Gemini API key configured. Add one in Settings.');

        $question   = trim($body['question']   ?? '');
        $scope      = $body['scope']            ?? 'all';
        $gameKey    = $body['game_key']         ?? '';
        $tournament = $body['tournament']       ?? '';
        if (!$question) throw new Exception('No question provided.');

        // ── Build approved game list ──────────────────────────────────────────
        $where  = "og.game_key IS NOT NULL";
        $params = [];
        if ($scope === 'game'       && $gameKey)    { $where .= " AND g.game_key=?";    $params[] = $gameKey; }
        elseif ($scope === 'tournament' && $tournament) { $where .= " AND g.tournament=?"; $params[] = $tournament; }

        $stmt = $db->prepare("SELECT g.*, og.wave_score as off_ws, og.opp_score as off_os
            FROM games g JOIN official_games og ON og.game_key=g.game_key
            WHERE $where ORDER BY g.game_date ASC");
        $stmt->execute($params);
        $gRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$gRows) throw new Exception('No approved games found for this selection.');

        // ── Aggregate stats per game (avoid sending raw events — too large) ──
        $summary = [];
        $STAT_KEYS = ['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save','goals_against'];

        foreach ($gRows as $g) {
            $players = json_decode($g['players'] ?? '[]', true) ?: [];
            $subStmt = $db->prepare("SELECT events, wave_score, opp_score FROM submissions WHERE game_key=? ORDER BY is_coach DESC LIMIT 1");
            $subStmt->execute([$g['game_key']]);
            $sub = $subStmt->fetch(PDO::FETCH_ASSOC);
            $evts = $sub ? (json_decode($sub['events'], true) ?: []) : [];

            // Per-player totals
            $playerStats = [];
            foreach ($players as $p) {
                $row = [
                    'name'     => $p['name'] ?? ('Cap '.$p['number']),
                    'cap'      => $p['number'] ?? '?',
                    'isGoalie' => !empty($p['isGoalie']),
                    'stats'    => [],
                ];
                foreach ($STAT_KEYS as $sk) {
                    $count = count(array_filter($evts, fn($e) => ($e['playerId'] ?? '') === ($p['id'] ?? '') && ($e['stat'] ?? '') === $sk));
                    if ($count > 0) $row['stats'][$sk] = $count;
                }
                // Shot location zones for this player
                $zones = [];
                foreach ($evts as $e) {
                    if (($e['playerId'] ?? '') === ($p['id'] ?? '') && in_array($e['stat'] ?? '', ['goal','shot']) && isset($e['shotLocation']['x'])) {
                        $x = $e['shotLocation']['x']; $y = $e['shotLocation']['y'] ?? 50;
                        $zone = $x > 82 ? ($y < 35 ? '2m Wing Top' : ($y > 65 ? '2m Wing Bot' : '2m Front'))
                              : ($x > 65 ? ($y < 30 ? 'Right Wing' : ($y > 70 ? 'Left Wing' : 'Centre'))
                              : ($x > 45 ? 'Point' : 'Defensive Half'));
                        $zones[$zone] = ($zones[$zone] ?? 0) + 1;
                    }
                }
                if ($zones) $row['shotZones'] = $zones;
                if (!empty($row['stats']) || !empty($row['shotZones'])) $playerStats[] = $row;
            }

            // Situation breakdown
            $forShots = array_filter($evts, fn($e) => in_array($e['stat'] ?? '', ['goal','shot']));
            $situations = ['man_up' => ['shots'=>0,'goals'=>0], 'even' => ['shots'=>0,'goals'=>0], 'man_down' => ['shots'=>0,'goals'=>0]];
            foreach ($forShots as $e) {
                $sit = $e['situation'] ?? 'even';
                if (!isset($situations[$sit])) $situations[$sit] = ['shots'=>0,'goals'=>0];
                $situations[$sit]['shots']++;
                if ($e['stat'] === 'goal') $situations[$sit]['goals']++;
            }

            $summary[] = [
                'game'        => $g['wave_team'].' vs '.$g['opponent'],
                'date'        => substr($g['game_date'] ?? '', 0, 10),
                'tournament'  => $g['tournament'] ?? '',
                'ageGroup'    => $g['age_group'] ?? '',
                'score'       => ($g['off_ws'] ?? $sub['wave_score'] ?? '?').'-'.($g['off_os'] ?? $sub['opp_score'] ?? '?'),
                'players'     => $playerStats,
                'situations'  => $situations,
                'totalShots'  => count($forShots),
                'totalGoals'  => count(array_filter($evts, fn($e) => ($e['stat']??'') === 'goal')),
                'shotsAgainst'=> count(array_filter($evts, fn($e) => in_array($e['stat']??'',['goals_against','shot_against']))),
            ];
        }

        $clubName = $db->query("SELECT value FROM settings WHERE key='club_name'")->fetchColumn() ?: 'WAVE';
        $dataJson = json_encode($summary, JSON_PRETTY_PRINT);

        // ── Build Gemini prompt ───────────────────────────────────────────────
        $systemPrompt = <<<PROMPT
You are a water polo stats analyst for {$clubName}. You have been given structured game data in JSON format and must answer the coach's question accurately and concisely.

Rules:
- Answer in 2–5 sentences of natural language. Be direct and specific — include the actual numbers.
- If comparing players, rank them clearly.
- "Goals" and "assists" refer to {$clubName} players unless the question says "opponent" or "against".
- "Saves" and "goals against" are goalie stats.
- "Kickout" = a foul drawn against a {$clubName} player (man-down). "Kickout earned" = a foul drawn BY a {$clubName} player (man-up opportunity).
- Situation "man_up" = {$clubName} on power play (extra man). "man_down" = penalty kill.
- Conversion rate = goals / shots as a percentage.
- If the data does not contain enough information to answer, say so honestly rather than guessing.
- Do not mention JSON, data structures, or technical implementation details.
- Do not use markdown formatting — plain text only.
PROMPT;

        $userMessage = "Game data:\n{$dataJson}\n\nQuestion: {$question}";

        // ── Call Gemini API ───────────────────────────────────────────────────
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiKey}";
        $payload = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
            'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 512],
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw) throw new Exception('No response from Gemini API.');
        $resp = json_decode($raw, true);
        if ($code !== 200) {
            $errMsg = $resp['error']['message'] ?? "HTTP $code from Gemini";
            throw new Exception("Gemini error: $errMsg");
        }

        $answer = $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!$answer) throw new Exception('Gemini returned an empty answer.');

        echo json_encode(['ok' => true, 'answer' => trim($answer), 'games_used' => count($gRows)]);

    } elseif ($action === 'export_stats') {
        requireAdmin($db);
        $scope      = $body['scope']      ?? 'all';
        $gameKey    = $body['game_key']   ?? '';
        $season     = $body['season']     ?? '';
        $tournament = $body['tournament'] ?? '';

        // Build game list — only approved games (must have official_games record)
        $where  = "og.game_key IS NOT NULL";
        $params = [];
        if ($scope === 'game'       && $gameKey)    { $where .= " AND g.game_key=?";    $params[] = $gameKey; }
        elseif ($scope === 'tournament' && $tournament) { $where .= " AND g.tournament=?"; $params[] = $tournament; }
        elseif ($scope === 'season' && $season)     { $where .= " AND g.season=?";      $params[] = $season; }

        $games = $db->prepare("SELECT g.*, og.wave_score as off_ws, og.opp_score as off_os, og.method as off_method
            FROM games g LEFT JOIN official_games og ON og.game_key=g.game_key
            WHERE $where ORDER BY g.game_date ASC");
        $games->execute($params);
        $gRows = $games->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($gRows as $g) {
            $players = json_decode($g['players'], true);
            $subs = $db->prepare("SELECT * FROM submissions WHERE game_key=? ORDER BY is_coach DESC, submitted_at ASC");
            $subs->execute([$g['game_key']]);
            $subRows = $subs->fetchAll(PDO::FETCH_ASSOC);
            $offSub  = null;
            foreach ($subRows as &$s) {
                $s['events'] = json_decode($s['events'], true);
                if ($g['off_method'] && !$offSub) $offSub = $s;
            }
            $out[] = ['game' => $g, 'players' => $players, 'submissions' => $subRows, 'official' => $offSub];
        }
        echo json_encode(['ok' => true, 'data' => $out, 'scope' => $scope]);

    } elseif ($action === 'save_team_name') {
        requireAdmin($db);
        $name   = trim($body['name'] ?? '');
        $ag     = trim($body['age_group'] ?? '');
        $gender = trim($body['gender'] ?? '');
        if (!$name) throw new Exception('name required');
        // INSERT OR IGNORE so existing rows are never overwritten
        $db->prepare("INSERT OR IGNORE INTO team_names (name, age_group, gender) VALUES (?,?,?)")
           ->execute([$name, $ag, $gender]);
        $id = $db->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $id]);

    } elseif ($action === 'save_team_names_bulk') {
        requireAdmin($db);
        $entries = $body['entries'] ?? []; // [{name, age_group, gender}]
        $saved = 0;
        $stmt = $db->prepare("INSERT OR IGNORE INTO team_names (name, age_group, gender) VALUES (?,?,?)");
        foreach ($entries as $e) {
            $n = trim($e['name'] ?? '');
            $ag = trim($e['age_group'] ?? '');
            $g = trim($e['gender'] ?? '');
            if (!$n) continue;
            $stmt->execute([$n, $ag, $g]);
            $saved++;
        }
        echo json_encode(['ok' => true, 'saved' => $saved]);

    } elseif ($action === 'delete_team_name') {
        requireAdmin($db);
        $id = (int)($body['id'] ?? 0);
        if (!$id) throw new Exception('id required');
        $db->prepare("DELETE FROM team_names WHERE id=?")->execute([$id]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'save_tournament_name') {
        requireAdmin($db);
        $name   = trim($body['name'] ?? '');
        $season = trim($body['season'] ?? '');
        if (!$name) throw new Exception('name required');
        $db->prepare("INSERT INTO tournament_names (name, season) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET season=excluded.season")
           ->execute([$name, $season]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete_tournament_name') {
        requireAdmin($db);
        $db->prepare("DELETE FROM tournament_names WHERE name=?")->execute([trim($body['name'] ?? '')]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'update_tournament_name') {
        requireAdmin($db);
        $oldName = trim($body['old_name'] ?? '');
        $newName = trim($body['name'] ?? '');
        $season  = trim($body['season'] ?? '');
        if (!$oldName || !$newName) throw new Exception('name required');
        if ($oldName !== $newName) {
            // Rename: delete old, insert new
            $db->prepare("DELETE FROM tournament_names WHERE name=?")->execute([$oldName]);
            $db->prepare("INSERT INTO tournament_names (name, season) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET season=excluded.season")
               ->execute([$newName, $season]);
        } else {
            $db->prepare("UPDATE tournament_names SET season=? WHERE name=?")->execute([$season, $newName]);
        }
        echo json_encode(['ok' => true]);

    } elseif ($action === 'merge_games') {
        // Move all submissions from source game into target game, then delete source
        requireAdmin($db);
        $source = $body['source_key'] ?? '';
        $target = $body['target_key'] ?? '';
        if (!$source || !$target || $source === $target) throw new Exception('source_key and target_key required and must differ');
        $db->prepare("UPDATE submissions SET game_key=? WHERE game_key=?")->execute([$target, $source]);
        $db->prepare("DELETE FROM games WHERE game_key=?")->execute([$source]);
        $db->prepare("DELETE FROM official_games WHERE game_key=?")->execute([$source]);
        echo json_encode(['ok' => true, 'message' => 'Games merged.']);

    } elseif ($action === 'get_report_data') {
        requireAdmin($db);
        $scope      = $body['scope'] ?? '';       // 'game' | 'tournament' | 'season'
        $gameKey    = $body['game_key'] ?? '';
        $tournament = $body['tournament'] ?? '';
        $season     = $body['season'] ?? '';

        // Build query to fetch games + their official events + players
        if ($scope === 'game') {
            $rows = $db->prepare("SELECT g.game_key, g.game_date, g.wave_team, g.opponent, g.tournament, g.season, g.players,
                o.wave_score, o.opp_score, o.events as official_events
                FROM games g LEFT JOIN official_games o ON o.game_key = g.game_key
                WHERE g.game_key = ?");
            $rows->execute([$gameKey]);
        } elseif ($scope === 'tournament') {
            $rows = $db->prepare("SELECT g.game_key, g.game_date, g.wave_team, g.opponent, g.tournament, g.season, g.players,
                o.wave_score, o.opp_score, o.events as official_events
                FROM games g LEFT JOIN official_games o ON o.game_key = g.game_key
                WHERE g.tournament = ? ORDER BY g.game_date ASC");
            $rows->execute([$tournament]);
        } elseif ($scope === 'season') {
            $rows = $db->prepare("SELECT g.game_key, g.game_date, g.wave_team, g.opponent, g.tournament, g.season, g.players,
                o.wave_score, o.opp_score, o.events as official_events
                FROM games g LEFT JOIN official_games o ON o.game_key = g.game_key
                WHERE g.season = ? ORDER BY g.game_date ASC");
            $rows->execute([$season]);
        } else {
            throw new Exception('Invalid scope');
        }

        $games = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $g['players']         = json_decode($g['players'], true) ?? [];
            $g['official_events'] = json_decode($g['official_events'] ?? '[]', true) ?? [];
            $games[] = $g;
        }

        // Also fetch best submission events for games without official record
        foreach ($games as &$g) {
            if (empty($g['official_events'])) {
                $sub = $db->prepare("SELECT events FROM submissions WHERE game_key=? ORDER BY is_coach DESC, submitted_at ASC LIMIT 1");
                $sub->execute([$g['game_key']]);
                $row = $sub->fetch(PDO::FETCH_ASSOC);
                $g['official_events'] = $row ? (json_decode($row['events'], true) ?? []) : [];
                $g['events_source'] = 'submission';
            } else {
                $g['events_source'] = 'official';
            }
        }
        unset($g);

        // Get distinct tournaments and seasons for the scope selectors
        $tournaments = array_values(array_unique(array_column(
            $db->query("SELECT DISTINCT tournament FROM games WHERE tournament != '' ORDER BY tournament")->fetchAll(PDO::FETCH_ASSOC),
            'tournament'
        )));
        $seasons = array_values(array_unique(array_column(
            $db->query("SELECT DISTINCT season FROM games WHERE season != '' ORDER BY season DESC")->fetchAll(PDO::FETCH_ASSOC),
            'season'
        )));

        echo json_encode(['ok' => true, 'games' => $games, 'tournaments' => $tournaments, 'seasons' => $seasons]);

    } elseif ($action === 'publish_report') {
        requireAdmin($db);
        $d = $body['data'] ?? [];
        foreach (['type','title','data'] as $req)
            if (empty($d[$req])) throw new Exception("$req is required");
        $key = bin2hex(random_bytes(8));
        $db->prepare("INSERT INTO published_reports (report_key, type, title, subtitle, season, data) VALUES (?,?,?,?,?,?)")
           ->execute([$key, $d['type'], $d['title'], $d['subtitle'] ?? '', $d['season'] ?? '', json_encode($d['data'])]);
        echo json_encode(['ok' => true, 'report_key' => $key]);

    } elseif ($action === 'update_report') {
        requireAdmin($db);
        $d = $body['data'] ?? [];
        $key = $d['report_key'] ?? '';
        if (!$key) throw new Exception('report_key required');
        $db->prepare("UPDATE published_reports SET title=?, subtitle=?, data=? WHERE report_key=?")
           ->execute([$d['title'] ?? '', $d['subtitle'] ?? '', json_encode($d['data'] ?? []), $key]);
        echo json_encode(['ok' => true]);

    } elseif ($action === 'delete_report') {
        requireAdmin($db);
        $key = $body['report_key'] ?? '';
        if (!$key) throw new Exception('report_key required');
        $db->prepare("DELETE FROM published_reports WHERE report_key=?")->execute([$key]);
        echo json_encode(['ok' => true]);

    } else {
        throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

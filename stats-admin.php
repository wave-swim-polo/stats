<?php
// ─── WAVE Stats Admin ─────────────────────────────────────────────────────────
define('DB_PATH', __DIR__ . '/data/stats.db');

// ── Cache busting ─────────────────────────────────────────────────────────────
// Use file modification time as version — changes on every upload automatically
define('FILE_VER', filemtime(__FILE__));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
header('Vary: *');

// Helper: all internal navigation URLs include the file version
function vUrl(string $qs): string {
    return '?' . $qs . '&_v=' . FILE_VER;
}

function getDB(): PDO {
    if (!file_exists(DB_PATH)) die('<p style="font:16px sans-serif;padding:40px">Initialize the DB by visiting stats-api.php first.</p>');
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

$token = trim($_GET['t'] ?? '');
if (!$token) die('<p style="font:16px sans-serif;padding:40px;color:#ef4444">No token provided.</p>');
$db = getDB();
$chk = $db->prepare("SELECT id FROM admin_tokens WHERE token=?"); $chk->execute([$token]);
if (!$chk->fetch()) die('<p style="font:16px sans-serif;padding:40px;color:#ef4444">Invalid token.</p>');

$view = $_GET['view'] ?? 'games';  // games | review | rosters | roster_edit | teams | tournaments | reports | ask | settings
$selectedKey   = $_GET['g']  ?? '';
$selectedRKey  = $_GET['r']  ?? '';

// ── Load branding settings ───────────────────────────────────────────────────
$settings = ['club_name' => 'WAVE', 'primary_color' => '#003087', 'secondary_color' => '#FFC72C'];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('club_name','WAVE')");
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('primary_color','#003087')");
    $db->exec("INSERT OR IGNORE INTO settings (key,value) VALUES ('secondary_color','#FFC72C')");
    $rows = $db->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $settings[$r['key']] = $r['value'];
} catch(Exception $e) {}
$clubName   = htmlspecialchars($settings['club_name']);
$colorPri   = htmlspecialchars($settings['primary_color']);
$colorSec   = htmlspecialchars($settings['secondary_color']);

// ── Load published reports ────────────────────────────────────────────────────
$publishedReports = [];
try {
    $publishedReports = $db->query("SELECT * FROM published_reports ORDER BY published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($publishedReports as &$pr) $pr['data'] = json_decode($pr['data'], true) ?? [];
    unset($pr);
} catch (Exception $e) {} // table may not exist yet on old DBs

// Build set of game_keys that already have a published report
$pushedGameKeys = [];
foreach ($publishedReports as $pr) {
    $gk = $pr['data']['game_key'] ?? '';
    if ($gk) $pushedGameKeys[$gk] = date('M j, Y', strtotime($pr['published_at']));
}

// ── Load published reports ────────────────────────────────────────────────────
$games = $db->query("
    SELECT g.*, COUNT(s.id) as sub_count,
        GROUP_CONCAT(s.tracker_name||':'||s.is_coach, '|') as trackers
    FROM games g LEFT JOIN submissions s ON s.game_key=g.game_key
    GROUP BY g.game_key ORDER BY g.game_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
$offMap = array_column($db->query("SELECT game_key,method FROM official_games")->fetchAll(PDO::FETCH_ASSOC),'method','game_key');
$approvedGames       = array_filter($games, fn($g) => isset($offMap[$g['game_key']]));
$distinctTournaments = array_unique(array_filter(array_column($approvedGames, 'tournament')));
sort($distinctTournaments);

// Pre-load all submissions for dashboard (id, tracker_name, wave_score, opp_score, is_coach, event count)
$allSubs = [];
foreach ($db->query("
    SELECT id, game_key, tracker_name, wave_score, opp_score, is_coach,
           (SELECT COUNT(*) FROM json_each(s.events)) as event_count
    FROM submissions s ORDER BY is_coach DESC, submitted_at ASC
")->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $allSubs[$s['game_key']][] = $s;
}

// ── Load rosters ──────────────────────────────────────────────────────────────
$rosters = [];
try {
    $rawRosters = $db->query("SELECT roster_key, name, age_group, tournament, updated_at, players FROM rosters ORDER BY age_group, name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rawRosters as &$r) {
        $decoded = json_decode($r['players'], true);
        $r['player_count'] = is_array($decoded) ? count($decoded) : 0;
        unset($r['players']); // don't carry full player JSON in every page load
    }
    $rosters = $rawRosters;
} catch(Exception $e) { error_log('Roster load error: ' . $e->getMessage()); }

// ── Load selected game data ───────────────────────────────────────────────────
$selGame = null; $submissions = []; $officialGame = null; $allPlayers = [];
if ($selectedKey) {
    $r = $db->prepare("SELECT * FROM games WHERE game_key=?"); $r->execute([$selectedKey]);
    $selGame = $r->fetch(PDO::FETCH_ASSOC);
    if ($selGame) { $selGame['players'] = json_decode($selGame['players'], true); $allPlayers = $selGame['players']; usort($allPlayers, fn($a,$b)=>(int)$a['number']-(int)$b['number']); }
    $sr = $db->prepare("SELECT * FROM submissions WHERE game_key=? ORDER BY is_coach DESC, submitted_at ASC"); $sr->execute([$selectedKey]);
    $submissions = $sr->fetchAll(PDO::FETCH_ASSOC);
    foreach ($submissions as &$s) { $s['events']=json_decode($s['events'],true); $s['is_coach']=(bool)(int)$s['is_coach']; }
    $or2 = $db->prepare("SELECT * FROM official_games WHERE game_key=?"); $or2->execute([$selectedKey]);
    $off2 = $or2->fetch(PDO::FETCH_ASSOC);
    if ($off2) { $off2['events']=json_decode($off2['events'],true); $officialGame=$off2; }
}

// ── Load selected roster ──────────────────────────────────────────────────────
$selRoster = null;
if ($selectedRKey) {
    $rr = $db->prepare("SELECT * FROM rosters WHERE roster_key=?"); $rr->execute([$selectedRKey]);
    $selRoster = $rr->fetch(PDO::FETCH_ASSOC);
    if ($selRoster) { $selRoster['players'] = json_decode($selRoster['players'], true); usort($selRoster['players'], fn($a,$b)=>(int)$a['number']-(int)$b['number']); }
}

$STAT_LABELS = ['goal'=>'G','assist'=>'A','shot'=>'SH','steal'=>'ST','block'=>'BL','turnover'=>'TO','kickout'=>'KO','kickout_earned'=>'KOE','save'=>'SV','goals_against'=>'GA'];
function cntStat($evts,$pid,$stat){return count(array_filter($evts??[],fn($e)=>$e['playerId']===$pid&&$e['stat']===$stat));}
$activeCols=[];
foreach(array_keys($STAT_LABELS) as $sk) foreach($submissions as $s) foreach($allPlayers as $p) if(cntStat($s['events'],$p['id'],$sk)>0){$activeCols[]=$sk;break 2;}
$activeCols=array_unique($activeCols);

// Load team names and tournament names (create tables if they don't exist yet)
$teamNames = [];
$tournamentNames = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS team_names (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, age_group TEXT DEFAULT '', gender TEXT DEFAULT '', created_at TEXT DEFAULT (datetime('now')), UNIQUE(name, age_group, gender))");
    $db->exec("CREATE TABLE IF NOT EXISTS tournament_names (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, season TEXT DEFAULT '', created_at TEXT DEFAULT (datetime('now')))");
    try { $db->exec("ALTER TABLE team_names ADD COLUMN gender TEXT DEFAULT ''"); } catch(Exception $e) {}
    try { $db->exec("ALTER TABLE games ADD COLUMN age_group TEXT DEFAULT ''"); } catch(Exception $e) {}
    $teamNames = $db->query("SELECT id, name, age_group, gender FROM team_names ORDER BY name, age_group, gender")->fetchAll(PDO::FETCH_ASSOC);
    $tournamentNames = $db->query("SELECT name, season FROM tournament_names ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

$T = urlencode($token);

// Prevent browser caching so updates are always picked up
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title><?=$clubName?> Stats Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--navy:<?=$colorPri?>;--gold:<?=$colorSec?>;--bg:#f0f2f7;--sur:#fff;--bdr:#dde2ec;--txt:#1a2235;--muted:#6b7280;--danger:#ef4444;--ok:#16a34a;--ff:'DM Sans',sans-serif;--fm:'JetBrains Mono',monospace;--fd:'Bebas Neue',sans-serif}
body{font-family:var(--ff);background:var(--bg);color:var(--txt);min-height:100vh;font-size:14px}
a{color:inherit;text-decoration:none}

.hdr{background:var(--navy);border-bottom:3px solid var(--gold);display:flex;align-items:center;gap:16px;padding:0 20px;height:56px;position:sticky;top:0;z-index:100}
.hdr-title{font-family:var(--fd);font-size:26px;color:#fff;letter-spacing:1px;white-space:nowrap}
.hdr-nav{display:flex;gap:2px;margin-left:auto}
.hdr-nav a{padding:7px 12px;border-radius:7px;font-size:13px;font-weight:700;color:rgba(255,255,255,0.55);transition:all .15s;white-space:nowrap}
.hdr-nav a:hover{color:#fff;background:rgba(255,255,255,.1)}
.hdr-nav a.on{color:var(--gold);background:rgba(255,199,44,.15)}

.layout{display:block;min-height:calc(100vh - 59px)}
.side{background:var(--sur);border-right:1px solid var(--bdr);overflow-y:auto;position:sticky;top:59px;max-height:calc(100vh - 59px)}
.main{padding:22px;overflow-y:auto}

.side-hdr{padding:12px 14px 6px;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--bdr)}
.side-item{display:block;padding:11px 14px;border-bottom:1px solid var(--bdr);cursor:pointer;transition:background .1s}
.side-item:hover{background:#f5f7fc}
.side-item.on{background:rgba(0,48,135,.06);border-left:3px solid var(--navy)}
.side-item-name{font-weight:700;font-size:13px}
.side-item-meta{font-size:11px;color:var(--muted);margin-top:2px;display:flex;gap:5px;flex-wrap:wrap;align-items:center}

.pill{display:inline-flex;align-items:center;gap:2px;font-size:10px;font-weight:700;padding:2px 7px;border-radius:20px}
.pill-pend{background:rgba(245,158,11,.15);color:#b45309}
.pill-ok{background:rgba(22,163,74,.12);color:#166534}
.pill-cnt{background:rgba(0,48,135,.1);color:var(--navy)}
.pill-live{background:rgba(239,68,68,.1);color:var(--danger)}
.pill-sch{background:rgba(107,114,128,.1);color:var(--muted)}
.pill-coach{background:rgba(255,199,44,.2);color:#92650a}

.card{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;overflow:hidden;margin-bottom:16px}
.card-hdr{padding:11px 16px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fafbfd}
.card-ttl{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted)}
.card-body{padding:16px}

.fg{margin-bottom:13px}
.lbl{display:block;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);margin-bottom:4px}
.inp{width:100%;background:#f8f9fc;border:1px solid var(--bdr);border-radius:8px;color:var(--txt);font-family:var(--ff);font-size:14px;padding:8px 11px;outline:none;transition:border-color .15s}
.inp:focus{border-color:var(--navy);background:#fff}
.sel{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%236b7280' d='M5 7L0 2h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:8px;border:none;font-family:var(--ff);font-size:13px;font-weight:700;cursor:pointer;transition:all .12s;text-decoration:none}
.btn:active{transform:scale(.97)}
.btn-navy{background:var(--navy);color:#fff}.btn-navy:hover{filter:brightness(1.15)}
.btn-gold{background:var(--gold);color:#1a2235}.btn-gold:hover{filter:brightness(1.08)}
.btn-out{background:transparent;color:var(--navy);border:2px solid var(--navy)}
.btn-ghost{background:rgba(0,48,135,.07);color:var(--navy)}
.btn-danger{background:rgba(239,68,68,.08);color:var(--danger);border:1px solid rgba(239,68,68,.25)}
.btn-sm{padding:5px 10px;font-size:12px}
.btn-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}

.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{text-align:center;padding:7px 5px;font-size:9px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);border-bottom:2px solid var(--bdr);background:#f8f9fc;white-space:nowrap}
.tbl th:first-child{text-align:left}
.tbl td{text-align:center;padding:8px 5px;border-bottom:1px solid var(--bdr);font-family:var(--fm)}
.tbl td:first-child{text-align:left;font-family:var(--ff);font-weight:600}
.tbl tr:last-child td{border-bottom:none}
.tbl .v{color:var(--navy);font-weight:700}
.tbl .diff{background:rgba(245,158,11,.12);color:#b45309;font-weight:700}
.tbl .off{color:#166534;font-weight:700}

.score-n{font-family:var(--fd);font-size:44px;color:var(--navy);line-height:1}
.score-sep{font-family:var(--fd);font-size:28px;color:var(--bdr)}
.score-lbl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase}

.status-row{display:flex;gap:5px}
.status-btn{flex:1;padding:7px 4px;border-radius:7px;border:1px solid var(--bdr);background:#f8f9fc;color:var(--muted);font-size:11px;font-weight:700;cursor:pointer;text-align:center;transition:all .15s}
.status-btn.on{background:rgba(0,48,135,.08);border-color:var(--navy);color:var(--navy)}
.status-btn.on-live{background:rgba(239,68,68,.07);border-color:var(--danger);color:var(--danger)}
.status-btn.on-closed{background:rgba(107,114,128,.08);border-color:var(--muted);color:var(--muted)}

.banner{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:13px}
.banner-ok{background:rgba(22,163,74,.07);border:1px solid rgba(22,163,74,.25)}
.banner-warn{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25)}
.banner-info{background:rgba(0,48,135,.05);border:1px solid rgba(0,48,135,.15)}

.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#f0f2f7;color:var(--txt);margin:2px}
.chip-coach{background:rgba(255,199,44,.2);color:#92650a}

/* PDF team picker overlay */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px}
.overlay-box{background:var(--sur);border-radius:16px;width:100%;max-width:540px;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.overlay-hdr{padding:18px 20px 14px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between}
.overlay-ttl{font-family:var(--fd);font-size:22px;color:var(--navy);letter-spacing:.5px}

/* Player list in roster editor */
.player-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--bdr)}
.player-row:last-child{border-bottom:none}
.player-num{font-family:var(--fm);font-weight:700;color:var(--muted);width:32px;text-align:right;font-size:12px}
.player-name{flex:1;font-weight:600}
.player-pos{font-size:11px;color:var(--muted)}

#toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:600;display:none;z-index:999;box-shadow:0 4px 20px rgba(0,0,0,.3);border:2px solid var(--gold);white-space:nowrap}
.empty{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-ico{font-size:36px;margin-bottom:10px}

@media(max-width:680px){.layout{grid-template-columns:1fr}.side{max-height:180px;position:static;border-right:none;border-bottom:1px solid var(--bdr)}.grid2,.grid3{grid-template-columns:1fr}}
</style>
</head>
<script>
// Early globals — available to all per-view script blocks before the main script loads
const _ADMIN_TOKEN = '<?=addslashes($token)?>';
async function api(body) {
    try {
        const r = await fetch('stats-api.php?t=' + encodeURIComponent(_ADMIN_TOKEN) + '&_=' + Date.now(), {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...body, token: _ADMIN_TOKEN })
        });
        const text = await r.text();
        if (!r.ok) return { ok: false, error: 'HTTP ' + r.status + ': ' + text.slice(0, 200) };
        try { return JSON.parse(text); }
        catch(e) { return { ok: false, error: 'Bad JSON: ' + text.slice(0, 300) }; }
    } catch(err) {
        return { ok: false, error: 'Network error: ' + err.message };
    }
}
function toast(msg, dur=3000) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(t._t); t._t = setTimeout(() => t.style.display = 'none', dur);
}
function reload() {
    const url = location.href.split('&_=')[0].split('?_=')[0];
    location.href = url + (url.includes('?') ? '&' : '?') + '_=' + Date.now();
}
</script>

<script src="stats-report.js"></script>
<body>

<header class="hdr">
    <div class="hdr-title"><?=$clubName?> STATS</div>
    <nav class="hdr-nav">
        <a href="?t=<?=$T?>&view=games"       class="<?=in_array($view,['games','review'])?'on':''?>">🏊 Games</a>
        <a href="?t=<?=$T?>&view=rosters"     class="<?=in_array($view,['rosters','roster_edit'])?'on':''?>">📋 Rosters</a>
        <a href="?t=<?=$T?>&view=teams"       class="<?=$view==='teams'?'on':''?>">🏷 Opponents</a>
        <a href="?t=<?=$T?>&view=tournaments" class="<?=$view==='tournaments'?'on':''?>">🏆 Tournaments</a>
        <a href="?t=<?=$T?>&view=reports"     class="<?=$view==='reports'?'on':''?>">📣 Reports</a>
        <a href="?t=<?=$T?>&view=ask"         class="<?=$view==='ask'?'on':''?>">🤖 Ask SkipShot</a>
        <a href="?t=<?=$T?>&view=settings"     class="<?=$view==='settings'?'on':'' ?>">⚙️ Settings</a>
        <a href="https://stats.ottawawaterpolo.com/" target="_blank" style="margin-left:auto;opacity:.7">📱 Front-End ↗</a>
    </nav>
</header>

<div class="layout">

<!-- ── Main content ── -->
<main class="main">

<?php if ($view === 'rosters'): ?>
<script>
(function(){
  const T = '<?=addslashes($token)?>';
  async function _rApi(body) {
    const r = await fetch('stats-api.php?t='+encodeURIComponent(T)+'&_='+Date.now(), {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({...body, token: T})
    });
    return r.json();
  }
  window.deleteRoster = async function(key) {
    if (!confirm('Delete this roster? This cannot be undone.')) return;
    const j = await _rApi({action:'delete_roster', roster_key:key});
    if (j.ok) location.reload();
    else alert('Delete failed: '+(j.error||'Unknown error'));
  };
  window.deleteAllRosters = async function() {
    if (!confirm('Delete ALL rosters? This cannot be undone.')) return;
    if (!confirm('Are you sure? All rosters will be permanently removed.')) return;
    const j = await _rApi({action:'delete_all_rosters'});
    if (j.ok) location.href = '?t='+encodeURIComponent(T)+'&view=rosters';
    else alert('Delete failed: '+(j.error||'Unknown error'));
  };
  window.importPdfToRoster = function(input) {
    // Delegates to the hoisted importPdfToRoster in the main script
    if (typeof parsePdfToTeams === 'function') {
      parsePdfToTeams(input, function(teams) {
        if (!teams || !teams.length) { toast('⚠️ No players found in this PDF.'); return; }
        showTournamentPicker(teams);
      });
    } else {
      toast('⚠️ Page still loading — please try again.');
    }
  };
})();
</script>
<!-- ══ ROSTERS LIST ══ -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
        <div style="font-family:var(--fd);font-size:28px;color:var(--navy)">Rosters</div>
        <div style="font-size:13px;color:var(--muted)">Import once, reuse across games.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <button class="btn btn-navy" onclick="document.getElementById('roster-pdf-input').click()">📄 Import PDF</button>
        <?php if (!empty($rosters)): ?>
        <button class="btn btn-danger btn-sm" onclick="deleteAllRosters()">🗑 Delete All Rosters</button>
        <?php endif; ?>
    </div>
    <input type="file" id="roster-pdf-input" accept="application/pdf" style="display:none" onchange="importPdfToRoster(this)">
</div>
<?php if(empty($rosters)) { ?>
<div class="empty">
    <div class="empty-ico">📋</div>
    <div style="font-size:16px;font-weight:700;margin-bottom:8px">No rosters yet</div>
    <div style="margin-bottom:16px">Import a PDF to create your first roster.</div>
    <button class="btn btn-navy" onclick="document.getElementById('roster-pdf-input').click()">📄 Import PDF Roster</button>
</div>
<?php } else { ?>
<div class="card">
    <table class="tbl">
        <thead><tr><th style="text-align:left">Team</th><th style="text-align:left">Age Group</th><th style="text-align:left">Tournament</th><th>Players</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach($rosters as $ros): ?>
        <tr>
            <td><a href="?t=<?=$T?>&view=roster_edit&r=<?=urlencode($ros['roster_key'])?>" style="font-weight:700;color:var(--navy)"><?=htmlspecialchars($ros['name'])?></a></td>
            <td style="text-align:left;font-family:var(--ff)"><?=htmlspecialchars($ros['age_group'])?></td>
            <td style="text-align:left;font-family:var(--ff);color:var(--muted)"><?=htmlspecialchars($ros['tournament']??'')?></td>
            <td><?=$ros['player_count']?></td>
            <td style="font-family:var(--ff);font-size:11px"><?=date('M j, Y',strtotime($ros['updated_at']))?></td>
            <td style="display:flex;gap:6px;justify-content:flex-end">
                <a href="?t=<?=$T?>&view=roster_edit&r=<?=urlencode($ros['roster_key'])?>" class="btn btn-ghost btn-sm">Edit</a>
                <button class="btn btn-danger btn-sm" onclick="deleteRoster('<?=addslashes($ros['roster_key'])?>')">Delete</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php } ?>

<?php elseif ($view === 'roster_edit' && $selRoster): ?>
<script>
(function(){
  const T = '<?=addslashes($token)?>';
  async function _rApi(body) {
    const r = await fetch('stats-api.php?t='+encodeURIComponent(T)+'&_='+Date.now(), {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({...body, token: T})
    });
    return r.json();
  }
  window.deleteRoster = async function(key) {
    if (!confirm('Delete this roster? This cannot be undone.')) return;
    const j = await _rApi({action:'delete_roster', roster_key:key});
    if (j.ok) location.href = '?t='+encodeURIComponent(T)+'&view=rosters';
    else alert('Delete failed: '+(j.error||'Unknown error'));
  };
  window.reimportPdf = function(input, key, name, ag) {
    if (typeof parsePdfToTeams === 'function') {
      parsePdfToTeams(input, async function(teams) {
        if (!teams.length) { alert('⚠️ No players found'); return; }
        const allPlayers = teams.flatMap(t => t.players);
        const j = await _rApi({action:'update_roster', roster_key:key, players:allPlayers});
        if (j.ok) location.reload();
        else alert('Re-import failed: '+(j.error||'Unknown error'));
      });
    } else {
      setTimeout(() => window.reimportPdf(input, key, name, ag), 200);
    }
  };
})();
</script>
<!-- ══ ROSTER EDITOR ══ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px">
    <div>
        <div style="font-family:var(--fd);font-size:28px;color:var(--navy)" data-roster-name><?=htmlspecialchars($selRoster['name'])?></div>
        <div style="font-size:13px;color:var(--muted)"><?=htmlspecialchars($selRoster['age_group'])?><?=!empty($selRoster['tournament'])?' · '.htmlspecialchars($selRoster['tournament']):''?> · <?=count($selRoster['players'])?> players</div>
    </div>
    <div class="btn-row">
        <button class="btn btn-ghost" onclick="document.getElementById('reimport-input').click()">📄 Re-import PDF</button>
        <input type="file" id="reimport-input" accept="application/pdf" style="display:none" onchange="reimportPdf(this,'<?=addslashes($selectedRKey)?>','<?=addslashes($selRoster['name'])?>','<?=addslashes($selRoster['age_group'])?>')">
        <button class="btn btn-danger btn-sm" onclick="deleteRoster('<?=addslashes($selectedRKey)?>')">Delete</button>
    </div>
</div>

<div class="card" style="margin-bottom:14px">
    <div class="card-hdr"><span class="card-ttl">Roster Details</span></div>
    <div class="card-body">
        <div class="fg" style="margin-bottom:12px"><label class="lbl">Team Name <span style="color:var(--danger)">*</span></label>
            <input class="inp" id="r-name" value="<?=htmlspecialchars($selRoster['name'] ?? '')?>" placeholder="e.g. WAVE U16 Girls">
        </div>
        <div class="fg"><label class="lbl">Tournament <span style="font-weight:400;opacity:.5">(optional)</span></label>
            <input class="inp" id="r-tournament" value="<?=htmlspecialchars($selRoster['tournament'] ?? '')?>" placeholder="e.g. Spring Invitational">
        </div>
    </div>
</div>

<div class="card">
    <div class="card-hdr">
        <span class="card-ttl">Players</span>
        <button class="btn btn-ghost btn-sm" onclick="showAddPlayer()">&#xFE65; Add</button>
    </div>
    <div class="card-body" id="player-list">
        <?php foreach($selRoster['players'] as $p): ?>
        <div class="player-row" data-id="<?=htmlspecialchars($p['id'])?>">
            <span class="player-num"><?=htmlspecialchars($p['number'])?></span>
            <span class="player-name"><?=htmlspecialchars($p['name'])?></span>
            <span class="player-pos"><?=($p['isGoalie']??false)?'🧤 Goalie':'🏊 Field'?></span>
            <button class="btn btn-danger btn-sm" onclick="removePlayer('<?=htmlspecialchars($p['id'],ENT_QUOTES)?>')">✕</button>
        </div>
        <?php endforeach; ?>
        <?php if(empty($selRoster['players'])): ?><div style="color:var(--muted);font-size:13px">No players yet.</div><?php endif; ?>
    </div>
</div>

<!-- Add player form (hidden) -->
<div id="add-player-form" style="display:none" class="card">
    <div class="card-hdr"><span class="card-ttl">Add Player</span></div>
    <div class="card-body">
        <div class="grid3">
            <div class="fg"><label class="lbl"># <span style="color:var(--danger)">*</span></label><input class="inp" id="ap-num" placeholder="7" type="number" min="1" max="99" onkeydown="if(event.key==='Enter')addPlayer()"></div>
            <div class="fg" style="grid-column:span 2"><label class="lbl">Name <span style="font-weight:400;opacity:.5">(optional)</span></label><input class="inp" id="ap-name" placeholder="First Last" onkeydown="if(event.key==='Enter')addPlayer()"></div>
        </div>
        <div class="fg">
            <label class="lbl">Position</label>
            <div style="display:flex;gap:8px">
                <button id="pos-field"  class="btn btn-navy btn-sm" onclick="setPos('field')">🏊 Field</button>
                <button id="pos-goalie" class="btn btn-out btn-sm"  onclick="setPos('goalie')">🧤 Goalie</button>
            </div>
        </div>
        <div class="btn-row">
            <button class="btn btn-navy" onclick="addPlayer()">Add Player</button>
            <button class="btn btn-out"  onclick="document.getElementById('add-player-form').style.display='none'">Cancel</button>
        </div>
    </div>
</div>

<div class="btn-row" style="margin-top:8px">
    <button class="btn btn-navy" onclick="saveRoster()">💾 Save Roster</button>
    <a href="?t=<?=$T?>&view=rosters" class="btn btn-out">← Back</a>
</div>

<script>
let _players = <?=json_encode($selRoster['players'])?>;
let _pos = 'field';
const ROSTER_KEY = '<?=addslashes($selectedRKey)?>';

function setPos(p){_pos=p;document.getElementById('pos-field').className='btn btn-sm '+(p==='field'?'btn-navy':'btn-out');document.getElementById('pos-goalie').className='btn btn-sm '+(p==='goalie'?'btn-navy':'btn-out');}
function showAddPlayer(){document.getElementById('add-player-form').style.display='';document.getElementById('ap-num').focus();}

function sortedPlayers() {
    return [..._players].sort((a,b) => parseInt(a.number) - parseInt(b.number));
}

function renderList() {
    const list = document.getElementById('player-list');
    if (!_players.length) { list.innerHTML='<div style="color:var(--muted);font-size:13px;padding:4px 0">No players yet.</div>'; return; }
    const nums = _players.map(p => p.number);
    const dupNums = new Set(nums.filter((n,i) => nums.indexOf(n) !== i));
    list.innerHTML = sortedPlayers().map(p => `
        <div class="player-row" data-id="${p.id}" style="${dupNums.has(p.number)?'background:rgba(239,68,68,0.07);outline:1px solid rgba(239,68,68,0.3);border-radius:6px;':''}">
            <input type="number" min="1" max="99" value="${p.number}"
                style="width:52px;padding:5px 4px;font-family:var(--fm);font-size:14px;font-weight:900;text-align:center;border:1.5px solid ${dupNums.has(p.number)?'#ef4444':'var(--bdr)'};border-radius:6px;background:var(--bg);color:${dupNums.has(p.number)?'#ef4444':'var(--navy)'}"
                onchange="updateCapNum('${p.id}', this.value)"
                onblur="updateCapNum('${p.id}', this.value)">
            <input type="text" value="${p.name||''}" placeholder="Player name"
                style="flex:1;padding:5px 8px;font-size:13px;font-weight:600;border:1px solid var(--bdr);border-radius:6px;background:var(--bg);color:var(--text);font-family:var(--fb)"
                onchange="renamePLayer('${p.id}', this.value)">
            <button class="btn btn-sm" style="border:1.5px solid ${p.isGoalie?'var(--navy)':'var(--bdr)'};background:${p.isGoalie?'var(--gold-dim)':'var(--bg)'};color:${p.isGoalie?'var(--navy)':'var(--muted)'};font-size:13px;padding:4px 9px;border-radius:20px" onclick="toggleGoalie('${p.id}')">${p.isGoalie?'🧤':'🤽'}</button>
            <button class="btn btn-danger btn-sm" onclick="removePlayer('${p.id}')">✕</button>
        </div>`).join('');
}
function updateCapNum(id, val) {
    const num = parseInt(val);
    if (isNaN(num) || num < 1 || num > 99) return;
    const p = _players.find(p => p.id === id);
    if (p) { p.number = String(num); renderList(); }
}
function renamePLayer(id, name) {
    const p = _players.find(p => p.id === id);
    if (p) p.name = name;
}
function toggleGoalie(id) {
    const p = _players.find(p => p.id === id);
    if (p) { p.isGoalie = !p.isGoalie; renderList(); }
}
function removePlayer(id){ _players=_players.filter(p=>p.id!==id); renderList(); }
function addPlayer(){
    const num=document.getElementById('ap-num').value.trim();
    const name=document.getElementById('ap-name').value.trim();
    if(!num){toast('⚠️ Cap number is required');return;}
    _players.push({id:'m_'+Date.now()+'_'+Math.random().toString(36).slice(2,5),number:num,name,isGoalie:_pos==='goalie'});
    document.getElementById('ap-name').value=''; document.getElementById('ap-num').value=''; document.getElementById('ap-num').focus();
    renderList();
}
async function saveRoster(){
    const name=(document.getElementById('r-name')?.value||'').trim();
    if (!name) { toast('⚠️ Team name is required'); document.getElementById('r-name').focus(); return; }
    const ag=<?=json_encode($selRoster['age_group'])?>;
    const tournament=(document.getElementById('r-tournament')?.value||'').trim();
    const nums = _players.map(p => p.number);
    const dupes = [...new Set(nums.filter((n,i) => nums.indexOf(n) !== i))];
    if (dupes.length) { toast('⚠️ Duplicate cap number' + (dupes.length>1?'s':'') + ': ' + dupes.map(n=>'#'+n).join(', ') + ' — fix before saving'); return; }
    const j=await api({action:'save_roster',roster_key:ROSTER_KEY,name,age_group:ag,tournament,players:_players});
    if(j.ok){
        toast('✅ Roster saved ('+_players.length+' players)');
        const h = document.querySelector('[data-roster-name]');
        if (h) h.textContent = name;
    } else toast('⚠️ '+j.error);
}

async function reimportPdf(input,key,name,ag){
    await parsePdfToTeams(input,teams=>{
        if(teams.length===0){toast('⚠️ No players found in PDF');return;}
        if(teams.length===1){applyReimport(key,name,ag,teams[0].players);}
        else{showTeamPicker(teams,chosen=>applyReimport(key,name,ag,chosen.players));}
    });
}
async function applyReimport(key,name,ag,players){
    const j=await api({action:'save_roster',roster_key:key,name,age_group:ag,players});
    if(j.ok){toast('✅ Roster updated ('+players.length+' players)');setTimeout(()=>reload(),700);}
    else toast('⚠️ '+j.error);
}
renderList();
</script>

<?php elseif ($view === 'review' && $selGame): ?>
<!-- ══ GAME SUBMISSIONS ══ -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px">
    <div>
        <div style="font-family:var(--fd);font-size:28px;color:var(--navy);letter-spacing:.5px"><?=htmlspecialchars($selGame['wave_team'])?> vs <?=htmlspecialchars($selGame['opponent'])?></div>
        <div style="font-size:13px;color:var(--muted);margin-top:2px">
            <?=date('l, F j, Y',strtotime($selGame['game_date']))?>
            <?php if($selGame['tournament']): ?>&middot; <?=htmlspecialchars($selGame['tournament'])?><?php endif; ?>
            &middot; <?=count($submissions)?> submission<?=count($submissions)!==1?'s':''?>
        </div>
    </div>
    <?php if(count($submissions)===0 && !$officialGame): ?>
    <button class="btn btn-sm" style="background:rgba(239,68,68,.08);color:var(--danger);border:1px solid rgba(239,68,68,.25);align-self:center"
        onclick="deleteAbandonedGame('<?=addslashes($selGame['game_key'])?>','<?=addslashes($selGame['wave_team'].' vs '.$selGame['opponent'])?>')"
        title="Delete this game — no submissions exist">
        🗑 Delete Game
    </button>
    <?php endif; ?>
</div>

<?php if(count($submissions)===0): ?>
<div class="banner banner-warn"><span>⏳</span><div>No submissions yet — trackers need to submit from the app.</div></div>

<?php else: ?>

<?php if($officialGame): ?>
<div class="banner banner-ok" style="margin-bottom:18px">
    <span style="font-size:20px">✅</span>
    <div>
        <div style="font-weight:700;color:#166534">Using: <?=htmlspecialchars($officialGame['method'])?></div>
        <div style="font-size:12px;color:var(--muted)">Score: <?=$officialGame['wave_score']?>–<?=$officialGame['opp_score']?> &middot; Set <?=date('M j \a\t g:ia',strtotime($officialGame['finalized_at']))?></div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-hdr"><span class="card-ttl">Submissions — select one to use for reporting</span></div>
    <div class="card-body" style="padding:0">
        <?php foreach($submissions as $s):
            $isSelected = $officialGame && $officialGame['method'] === $s['tracker_name'];
        ?>
        <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;border-bottom:1px solid var(--bdr);background:<?=$isSelected?'rgba(0,48,135,0.04)':'transparent'?>">
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <?php if($s['is_coach']): ?><span class="pill pill-coach">🏅 Coach</span><?php endif; ?>
                    <span style="font-weight:700;font-size:14px"><?=htmlspecialchars($s['tracker_name'])?></span>
                    <span style="font-size:13px;color:var(--muted)"><?=$s['wave_score']?>–<?=$s['opp_score']?></span>
                </div>
                <div style="font-size:11px;color:var(--muted);margin-top:3px">
                    <?=date('M j \a\t g:ia', strtotime($s['submitted_at']))?>
                    &middot; <?=count($s['events']??[])?> events
                </div>
            </div>
            <?php if($isSelected): ?>
            <span class="pill pill-ok" style="white-space:nowrap">✓ In use</span>
            <?php else: ?>
            <button class="btn btn-navy btn-sm" onclick="selectSub(<?=$s['id']?>,'<?=addslashes($s['tracker_name'])?>')">Use for reporting</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
async function selectSub(id, name) {
    const j = await api({ action: 'select_submission', submission_id: id });
    if (j.ok) { toast('✅ Using ' + name + ' for reporting'); setTimeout(() => reload(), 700); }
    else toast('⚠️ ' + j.error);
}
</script>

<?php elseif ($view === 'teams'): ?>
<!-- ══ MANAGE TEAMS ══ -->
<div style="margin-bottom:18px">
    <div style="font-family:var(--fd);font-size:28px;color:var(--navy)">Manage Opponents</div>
    <div style="font-size:13px;color:var(--muted)">Pre-populate team names that end users can select when creating a game. Each name + age group + gender combination is a separate entry.</div>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="card-hdr"><span class="card-ttl">Add Team</span></div>
    <div class="card-body">

        <!-- Step 1: Team name -->
        <div class="fg" style="margin-bottom:14px">
            <label class="lbl">Team Name <span style="color:var(--danger)">*</span></label>
            <input class="inp" id="tn-name" placeholder="e.g. Ottawa Titans" oninput="tnPreviewUpdate()">
        </div>

        <!-- Step 2: Gender -->
        <div class="fg" style="margin-bottom:14px">
            <label class="lbl">Gender <span style="color:var(--danger)">*</span></label>
            <div style="display:flex;gap:8px" id="tn-gender-btns">
                <?php foreach(['Boys','Girls','Co-Ed'] as $g): ?>
                <button type="button" class="btn btn-out btn-sm tn-gender-btn" data-val="<?=$g?>"
                    onclick="tnSelectGender('<?=$g?>')"
                    style="flex:1;padding:9px 4px;font-size:13px"><?=$g?></button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="tn-gender" value="">
        </div>

        <!-- Step 3: Age groups (checkboxes) -->
        <div class="fg" style="margin-bottom:16px">
            <label class="lbl">Age Groups <span style="font-weight:400;opacity:.6">(check all that apply)</span></label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px" id="tn-ag-boxes">
                <?php foreach(['10U','12U','14U','16U','18U','Open'] as $ag): ?>
                <label style="display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;border:1.5px solid var(--bdr);cursor:pointer;font-size:13px;font-weight:600;transition:all .12s;background:var(--sur)" id="tn-ag-lbl-<?=$ag?>">
                    <input type="checkbox" class="tn-ag-cb" value="<?=$ag?>" onchange="tnPreviewUpdate()" style="accent-color:var(--navy);width:15px;height:15px">
                    <?=$ag?>
                </label>
                <?php endforeach; ?>
                <!-- "Any / No age group" -->
                <label style="display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;border:1.5px solid var(--bdr);cursor:pointer;font-size:13px;font-weight:600;transition:all .12s;background:var(--sur)" id="tn-ag-lbl-any">
                    <input type="checkbox" class="tn-ag-cb" value="" onchange="tnPreviewUpdate()" style="accent-color:var(--navy);width:15px;height:15px">
                    No age group
                </label>
            </div>
        </div>

        <!-- Preview of what will be added -->
        <div id="tn-preview" style="display:none;margin-bottom:14px;padding:10px 14px;background:rgba(0,48,135,.04);border:1px solid rgba(0,48,135,.12);border-radius:10px;font-size:13px;color:var(--navy)"></div>

        <button class="btn btn-navy" onclick="addTeamNames()" id="tn-submit-btn">＋ Add to List</button>
    </div>
</div>

<?php if(empty($teamNames)) { ?>
<div class="empty"><div class="empty-ico">🏷</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">No team names yet</div><div>Use the form above to add teams with their age groups and gender.</div></div>
<?php } else {
    // Group by team name for display
    $grouped = [];
    foreach($teamNames as $tn) {
        $grouped[$tn['name']][] = $tn;
    }
?>
<div class="card">
    <table class="tbl">
        <thead><tr><th style="text-align:left">Team Name</th><th style="text-align:left">Gender</th><th style="text-align:left">Age Group</th><th></th></tr></thead>
        <tbody>
        <?php foreach($grouped as $teamName => $rows): ?>
            <?php foreach($rows as $i => $tn): ?>
            <tr>
                <?php if($i===0): ?>
                <td style="font-weight:700;vertical-align:top" rowspan="<?=count($rows)?>"><?=htmlspecialchars($teamName)?></td>
                <?php endif; ?>
                <td style="text-align:left">
                    <?php $g=$tn['gender']?:'—';
                    $gc=$g==='Boys'?'#3b82f6':($g==='Girls'?'#ec4899':'#7c3aed');
                    $rgb=$g==='Boys'?'59,130,246':($g==='Girls'?'236,72,153':'124,58,237'); ?>
                    <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;background:rgba(<?=$rgb?>,.13);color:<?=$gc?>"><?=$g?></span>
                </td>
                <td style="text-align:left"><?=htmlspecialchars($tn['age_group']?:'—')?></td>
                <td><button type="button" class="btn btn-danger btn-sm"
                    data-id="<?=(int)$tn['id']?>"
                    data-label="<?=htmlspecialchars($teamName.' '.($tn['age_group']?:'').' '.($tn['gender']?:''),ENT_QUOTES)?>"
                    onclick="deleteTeamNameFromBtn(this)">✕</button></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php } ?>

<script>
let _tnGender = '';

function tnSelectGender(val) {
    _tnGender = val;
    document.getElementById('tn-gender').value = val;
    document.querySelectorAll('.tn-gender-btn').forEach(b => {
        const on = b.dataset.val === val;
        b.className = 'btn btn-sm tn-gender-btn ' + (on ? 'btn-navy' : 'btn-out');
        b.style.flex = '1'; b.style.padding = '9px 4px'; b.style.fontSize = '13px';
    });
    tnPreviewUpdate();
}

function tnPreviewUpdate() {
    const name    = document.getElementById('tn-name').value.trim();
    const gender  = _tnGender;
    const checked = [...document.querySelectorAll('.tn-ag-cb:checked')].map(c => c.value);
    const prev    = document.getElementById('tn-preview');
    if (!name || !gender || !checked.length) { prev.style.display='none'; return; }
    const lines = checked.map(ag => `<strong>${name}</strong> · ${gender}${ag ? ' · ' + ag : ''}`);
    prev.style.display = '';
    prev.innerHTML = 'Will add:<br>' + lines.join('<br>');
}

async function addTeamNames() {
    const name   = document.getElementById('tn-name').value.trim();
    const gender = _tnGender;
    const checked = [...document.querySelectorAll('.tn-ag-cb:checked')].map(c => c.value);
    if (!name)          { alert('⚠️ Enter a team name'); return; }
    if (!gender)        { alert('⚠️ Select a gender'); return; }
    if (!checked.length){ alert('⚠️ Select at least one age group'); return; }

    const entries = checked.map(ag => ({ name, age_group: ag, gender }));
    const token = '<?=addslashes($token)?>';
    const r = await fetch('stats-api.php?t=' + encodeURIComponent(token) + '&_=' + Date.now(), {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'save_team_names_bulk', entries, token })
    });
    const j = await r.json();
    if (j.ok) { location.reload(); }
    else alert('⚠️ ' + (j.error || 'Failed'));
}

async function deleteTeamName(id, label) {
    if (!confirm('Remove "' + label.trim() + '" from the list?')) return;
    const token = '<?=addslashes($token)?>';
    const r = await fetch('stats-api.php?t=' + encodeURIComponent(token) + '&_=' + Date.now(), {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'delete_team_name', id, token })
    });
    const j = await r.json();
    if (j.ok) { location.reload(); }
    else alert('⚠️ ' + (j.error || 'Failed'));
}
function deleteTeamNameFromBtn(btn) {
    deleteTeamName(parseInt(btn.dataset.id), btn.dataset.label);
}
</script>

<?php elseif ($view === 'tournaments'): ?>
<script>
const _TOUR_TOKEN = '<?=addslashes($token)?>';
async function _tourApi(body) {
    const r = await fetch('stats-api.php?t=' + encodeURIComponent(_TOUR_TOKEN) + '&_=' + Date.now(), {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({...body, token: _TOUR_TOKEN})
    });
    return r.json();
}
async function addTournament(){
    const name=document.getElementById('tour-name').value.trim();
    const season=document.getElementById('tour-season').value.trim();
    if(!name){alert('⚠️ Enter a tournament name');return;}
    const j=await _tourApi({action:'save_tournament_name',name,season});
    if(j.ok){location.reload();}else alert('⚠️ '+(j.error||'Failed'));
}
async function deleteTournament(name){
    if(!confirm('Remove "'+name+'" from the list?'))return;
    const j=await _tourApi({action:'delete_tournament_name',name});
    if(j.ok){location.reload();}else alert('⚠️ '+(j.error||'Failed'));
}
function editTournament(btn, oldName) {
    const row = btn.closest('tr');
    const nameCell   = row.querySelector('.tour-name-cell');
    const seasonCell = row.querySelector('.tour-season-cell');
    const actCell    = row.querySelector('.tour-act-cell');
    const curName   = nameCell.textContent.trim();
    const curSeason = seasonCell.textContent.trim().replace('—','');
    nameCell.innerHTML   = `<input class="inp" style="padding:5px 8px;font-size:13px" value="${curName.replace(/"/g,'&quot;')}" id="edit-tour-name">`;
    seasonCell.innerHTML = `<input class="inp" style="padding:5px 8px;font-size:13px" value="${curSeason.replace(/"/g,'&quot;')}" id="edit-tour-season" placeholder="e.g. 2026 Season">`;
    actCell.innerHTML    = `
        <button class="btn btn-navy btn-sm" onclick="saveTournamentEdit('${oldName.replace(/'/g,"\\'")}')">💾 Save</button>
        <button class="btn btn-ghost btn-sm" onclick="location.reload()">Cancel</button>`;
    document.getElementById('edit-tour-name').focus();
}
async function saveTournamentEdit(oldName) {
    const newName  = document.getElementById('edit-tour-name')?.value.trim();
    const season   = document.getElementById('edit-tour-season')?.value.trim();
    if (!newName) { alert('⚠️ Tournament name cannot be empty'); return; }
    const j = await _tourApi({action:'update_tournament_name', old_name:oldName, name:newName, season});
    if(j.ok){location.reload();}else alert('⚠️ '+(j.error||'Failed'));
}
</script>
<!-- ══ MANAGE TOURNAMENTS ══ -->
<div style="margin-bottom:18px">
    <div style="font-family:var(--fd);font-size:28px;color:var(--navy)">Manage Tournaments</div>
    <div style="font-size:13px;color:var(--muted)">Pre-populate tournament names that end users can select when creating a game.</div>
</div>
<div class="card" style="margin-bottom:16px">
    <div class="card-hdr"><span class="card-ttl">Add Tournament</span></div>
    <div class="card-body">
        <div class="grid2">
            <div class="fg"><label class="lbl">Tournament Name</label><input class="inp" id="tour-name" placeholder="e.g. Ottawa Spring Open" onkeydown="if(event.key==='Enter')addTournament()"></div>
            <div class="fg"><label class="lbl">Season <span style="font-weight:400;opacity:.5">(optional)</span></label><input class="inp" id="tour-season" placeholder="e.g. 2026 Season"></div>
        </div>
        <button class="btn btn-navy" onclick="addTournament()">＋ Add Tournament</button>
    </div>
</div>
<?php if(empty($tournamentNames)) { ?>
<div class="empty"><div class="empty-ico">🏆</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">No tournaments yet</div><div>Add tournament names above so end users can select them from a dropdown.</div></div>
<?php } else { ?>
<div class="card">
    <table class="tbl">
        <thead><tr><th style="text-align:left">Tournament Name</th><th style="text-align:left">Season</th><th></th></tr></thead>
        <tbody>
        <?php foreach($tournamentNames as $tn): ?>
        <tr>
            <td class="tour-name-cell" style="font-weight:700"><?=htmlspecialchars($tn['name'])?></td>
            <td class="tour-season-cell" style="text-align:left"><?=htmlspecialchars($tn['season']?:'—')?></td>
            <td class="tour-act-cell" style="text-align:right;white-space:nowrap">
                <button class="btn btn-ghost btn-sm" onclick="editTournament(this,'<?=addslashes($tn['name'])?>')">✏️ Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteTournament('<?=addslashes($tn['name'])?>')">✕</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php } ?>

<?php elseif ($view === 'reports'): ?>
<!-- ══ REPORTS ══ -->
<div style="margin-bottom:18px">
    <div style="font-family:var(--fd);font-size:28px;color:var(--navy)">Reports</div>
    <div style="font-size:13px;color:var(--muted)">Generate stat reports from game data, preview, then publish to end users.</div>
</div>

<!-- ── Generate Report Card ── -->
<div class="card" style="margin-bottom:16px">
    <div class="card-hdr"><span class="card-ttl">📊 Generate Report</span></div>
    <div class="card-body">

        <!-- Scope selector -->
        <div style="margin-bottom:14px">
            <div class="lbl" style="margin-bottom:8px">Scope</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button class="btn rpt-scope-btn on" data-scope="game"      onclick="setScope('game')">Single Game</button>
                <button class="btn rpt-scope-btn"    data-scope="tournament" onclick="setScope('tournament')">Tournament</button>
                <button class="btn rpt-scope-btn"    data-scope="season"     onclick="setScope('season')">Season</button>
            </div>
        </div>

        <!-- Game selector -->
        <div id="rpt-sel-game" style="margin-bottom:14px">
            <label class="lbl">Game</label>
            <select class="inp" id="rpt-game-key">
                <option value="">— select a game —</option>
                <?php foreach($games as $g): if(!isset($offMap[$g['game_key']])) continue; ?>
                <option value="<?=htmlspecialchars($g['game_key'])?>"><?=htmlspecialchars(date('M j Y', strtotime($g['game_date'])).' · '.$g['wave_team'].' vs '.$g['opponent'].($g['tournament']?' ('.$g['tournament'].')':''))?></option>
                <?php endforeach; ?>
            </select>
            <?php if(!array_filter(array_keys($offMap))): ?>
            <div style="font-size:12px;color:var(--muted);margin-top:6px">⚠️ No approved games yet — finalize a game in the Games tab first.</div>
            <?php endif; ?>
        </div>

        <!-- Tournament selector -->
        <div id="rpt-sel-tournament" style="margin-bottom:14px;display:none">
            <label class="lbl">Tournament</label>
            <select class="inp" id="rpt-tournament">
                <option value="">— select a tournament —</option>
                <?php foreach($distinctTournaments as $tn): ?>
                <option value="<?=htmlspecialchars($tn)?>"><?=htmlspecialchars($tn)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Season selector -->
        <div id="rpt-sel-season" style="margin-bottom:14px;display:none">
            <label class="lbl">Season</label>
            <select class="inp" id="rpt-season-sel">
                <option value="">— select a season —</option>
                <?php
                $distinctSeasons = array_unique(array_filter(array_column($games, 'season')));
                rsort($distinctSeasons);
                foreach($distinctSeasons as $sn): ?>
                <option value="<?=htmlspecialchars($sn)?>"><?=htmlspecialchars($sn)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Report title override -->
        <div style="margin-bottom:14px">
            <label class="lbl">Report Title <span style="font-weight:400;opacity:.5">(auto-filled, editable)</span></label>
            <input class="inp" id="rpt-title" placeholder="e.g. Spring Invitational 2026 — WAVE U16">
        </div>
        <div style="margin-bottom:14px">
            <label class="lbl">Subtitle <span style="font-weight:400;opacity:.5">(optional)</span></label>
            <input class="inp" id="rpt-subtitle" placeholder="e.g. May 17–18, 2026 · 4 games played">
        </div>

        <button class="btn btn-navy" onclick="generateReport()" id="rpt-generate-btn">📊 Generate Report</button>
        <div id="rpt-preflight" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:12px;line-height:1.7"></div>
    </div>
</div>

<!-- ── Report ── -->
<div id="rpt-preview-wrap" style="display:none;margin-bottom:16px">
    <div class="card" style="margin-bottom:12px">
        <div class="card-hdr">
            <span class="card-ttl" id="rpt-preview-title">Report</span>
            <div style="display:flex;gap:8px">
                <button class="btn btn-ghost btn-sm" onclick="document.getElementById('rpt-preview-wrap').style.display='none'">✕ Close</button>
                <button class="btn btn-ghost btn-sm" onclick="printReport()">💾 Save as PDF</button>
                <button class="btn btn-navy btn-sm" onclick="publishGeneratedReport()">📣 Publish to End Users</button>
            </div>
        </div>
        <div class="card-body" id="rpt-preview-body" style="font-size:13px;line-height:1.6">
            <!-- rendered by JS -->
        </div>
    </div>
</div>

<!-- ── Ask SkipShot teaser ── -->
<div class="card" style="margin-bottom:16px;border:1.5px solid rgba(66,133,244,.25);background:linear-gradient(135deg,rgba(66,133,244,.03),rgba(52,168,83,.03))">
    <div class="card-body" style="display:flex;align-items:center;gap:14px;padding:14px 16px">
        <div style="font-size:32px">🤖</div>
        <div style="flex:1">
            <div style="font-weight:700;font-size:14px;color:var(--navy);margin-bottom:2px">Ask SkipShot</div>
            <div style="font-size:12px;color:var(--muted)">Ask plain-English questions about your stats — powered by Gemini.</div>
        </div>
        <a href="?t=<?=$T?>&view=ask" class="btn btn-navy btn-sm" style="white-space:nowrap">Open →</a>
    </div>
</div>

<!-- ── Published Reports List ── -->
<div style="margin-top:28px;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div style="font-size:11px;font-weight:700;letter-spacing:1.5px;color:var(--muted);text-transform:uppercase">
        Published Reports <span style="background:var(--navy);color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;letter-spacing:0;vertical-align:middle"><?=count($publishedReports)?></span>
    </div>
    <?php if(count($publishedReports) > 1): ?>
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted)">
        <span>Sort by</span>
        <select id="rpt-list-sort" class="inp" style="padding:4px 8px;font-size:12px;width:auto" onchange="sortReportList(this.value)">
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="title">Title A–Z</option>
            <option value="type">Type</option>
        </select>
    </div>
    <?php endif; ?>
</div>

<?php if(empty($publishedReports)): ?>
<div class="empty" style="margin-top:12px"><div class="empty-ico">📣</div><div style="font-size:15px;font-weight:700;margin-bottom:6px">No reports published yet</div><div>Generate a report above and publish it to end users.</div></div>
<?php else: ?>
<div id="rpt-list" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
<?php foreach($publishedReports as $pr):
    $rdata = $pr['data'] ?? [];
    $scope = $rdata['scope'] ?? '—';
    $gcount = $rdata['gameCount'] ?? 0;
    $scopeLabel = ['game'=>'Single Game','tournament'=>'Tournament','season'=>'Season'][$scope] ?? ucfirst($scope);
    $scopeColor = ['game'=>'#0ea5e9','tournament'=>'#f59e0b','season'=>'#8b5cf6'][$scope] ?? '#6b7280';
    $pubDate = date('M j, Y', strtotime($pr['published_at']));
    $pubTime = date('g:ia', strtotime($pr['published_at']));
?>
<div class="rpt-list-row" data-title="<?=htmlspecialchars(strtolower($pr['title']))?>" data-ts="<?=strtotime($pr['published_at'])?>" data-scope="<?=htmlspecialchars($scope)?>" style="background:var(--surface);border:1px solid var(--bdr);border-radius:12px;overflow:hidden">
    <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;cursor:pointer" onclick="toggleRptRow('<?=addslashes($pr['report_key'])?>',this.parentElement)">
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:14px;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($pr['title'])?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:3px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="background:<?=$scopeColor?>;color:#fff;border-radius:20px;padding:1px 8px;font-size:10px;font-weight:700"><?=$scopeLabel?></span>
                <?php if($gcount): ?><span><?=$gcount?> game<?=$gcount!==1?'s':''?></span><?php endif; ?>
                <span>Published <?=$pubDate?> at <?=$pubTime?></span>
                <?php if($rdata['subtitle'] ?? ''): ?><span style="opacity:.65">· <?=htmlspecialchars($rdata['subtitle'])?></span><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
            <button class="btn btn-ghost btn-sm" onclick="event.stopPropagation();printStoredReport('<?=addslashes($pr['report_key'])?>')" title="Save as PDF">💾 PDF</button>
            <button class="btn btn-danger btn-sm" onclick="event.stopPropagation();deleteReport('<?=addslashes($pr['report_key'])?>')" title="Delete">✕ Delete</button>
            <span class="rpt-row-chevron" style="font-size:11px;color:var(--muted);transition:transform .2s;display:inline-block">▼</span>
        </div>
    </div>
    <div id="rpt-body-<?=htmlspecialchars($pr['report_key'])?>" style="display:none;border-top:1px solid var(--bdr);padding:16px;font-size:13px;line-height:1.6">
        <div class="rpt-body-inner"></div>
    </div>
</div>
<script>window['_rptStored_<?=addslashes($pr['report_key'])?>']=<?=json_encode($rdata)?>;</script>
<?php endforeach; ?>
</div>
<?php endif; ?>
<script>
// ── Local helpers (main script loads after this block) ───────────────────────
const _T = '<?=addslashes($token)?>';
function goto(url) { location.href = url; }
function reload()  { location.reload(); }
function toast(msg) { if (typeof window._toast === 'function') window._toast(msg); else console.log(msg); }
async function api(body) {
    const r = await fetch('stats-api.php?t='+encodeURIComponent(_T)+'&_='+Date.now(), {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({...body, token: _T})
    });
    const text = await r.text();
    if (!r.ok) return {ok:false, error:'HTTP '+r.status+': '+text.slice(0,200)};
    try { return JSON.parse(text); } catch(e) { return {ok:false, error:'Bad JSON: '+text.slice(0,200)}; }
}

// ── Report scope UI ─────────────────────────────────────────────────────────
let _rptScope = 'game';
let _rptData  = null; // last generated report data

function setScope(s) {
    _rptScope = s;
    document.querySelectorAll('.rpt-scope-btn').forEach(b => {
        b.className = 'btn rpt-scope-btn' + (b.dataset.scope === s ? ' on' : '');
    });
    document.getElementById('rpt-sel-game').style.display       = s === 'game'       ? '' : 'none';
    document.getElementById('rpt-sel-tournament').style.display = s === 'tournament' ? '' : 'none';
    document.getElementById('rpt-sel-season').style.display     = s === 'season'     ? '' : 'none';
    autoFillTitle();
}

function autoFillTitle() {
    const titleEl = document.getElementById('rpt-title');
    if (_rptScope === 'game') {
        const sel = document.getElementById('rpt-game-key');
        titleEl.value = sel.options[sel.selectedIndex]?.text?.replace(/^.*?·\s*/,'') || '';
    } else if (_rptScope === 'tournament') {
        titleEl.value = document.getElementById('rpt-tournament').value || '';
    } else {
        titleEl.value = document.getElementById('rpt-season-sel').value || '';
    }
}
document.getElementById('rpt-game-key').addEventListener('change', autoFillTitle);
document.getElementById('rpt-tournament').addEventListener('change', autoFillTitle);
document.getElementById('rpt-season-sel').addEventListener('change', autoFillTitle);

async function checkPreflight() {
    const pf = document.getElementById('rpt-preflight');
    let payload = { action: 'get_report_data', scope: _rptScope };
    if (_rptScope === 'game') {
        const gk = document.getElementById('rpt-game-key').value;
        if (!gk) { pf.style.display='none'; return; }
        payload.game_key = gk;
    } else if (_rptScope === 'tournament') {
        const tn = document.getElementById('rpt-tournament').value;
        if (!tn) { pf.style.display='none'; return; }
        payload.tournament = tn;
    } else {
        const sn = document.getElementById('rpt-season-sel').value;
        if (!sn) { pf.style.display='none'; return; }
        payload.season = sn;
    }
    pf.style.display=''; pf.style.background='#f8f9fc'; pf.textContent='Checking…';
    const j = await api(payload);
    if (!j.ok || !j.games) { pf.style.display='none'; return; }
    const total = j.games.length;
    const withOfficial = j.games.filter(g => g.events_source === 'official').length;
    const missing = total - withOfficial;
    if (missing === 0) {
        pf.style.background='rgba(22,163,74,.07)'; pf.style.border='1px solid rgba(22,163,74,.25)'; pf.style.color='#166534';
        pf.innerHTML = `✅ All ${total} game${total!==1?'s':''} have a submission selected for reporting.`;
    } else {
        pf.style.background='rgba(245,158,11,.07)'; pf.style.border='1px solid rgba(245,158,11,.3)'; pf.style.color='#92650a';
        pf.innerHTML = `⚠️ ${withOfficial} of ${total} game${total!==1?'s':''} have a submission selected. ` +
            `${missing} will use the best available submission. ` +
            `<a href="?t=<?=$T?>&view=games" style="color:#92650a;font-weight:700">Select submissions →</a>`;
    }
}
document.getElementById('rpt-game-key').addEventListener('change', checkPreflight);
document.getElementById('rpt-tournament').addEventListener('change', checkPreflight);
document.getElementById('rpt-season-sel').addEventListener('change', checkPreflight);

// ── Generate report ─────────────────────────────────────────────────────────
// ── Published report list: expand/collapse, sort, PDF ────────────────────────
function toggleRptRow(rk, rowEl) {
    const body    = document.getElementById('rpt-body-' + rk);
    const chevron = rowEl.querySelector('.rpt-row-chevron');
    const isOpen  = body.style.display !== 'none';
    if (isOpen) {
        body.style.display = 'none';
        chevron.style.transform = '';
    } else {
        const inner = body.querySelector('.rpt-body-inner');
        if (!inner.innerHTML.trim()) {
            const data = window['_rptStored_' + rk];
            inner.innerHTML = data && data.sections ? renderReportHTML(data) : '<p style="color:var(--muted)">No data.</p>';
        }
        body.style.display = '';
        chevron.style.transform = 'rotate(180deg)';
    }
}

function sortReportList(mode) {
    const list = document.getElementById('rpt-list');
    if (!list) return;
    const rows = [...list.querySelectorAll('.rpt-list-row')];
    rows.sort((a, b) => {
        if (mode === 'newest') return parseInt(b.dataset.ts) - parseInt(a.dataset.ts);
        if (mode === 'oldest') return parseInt(a.dataset.ts) - parseInt(b.dataset.ts);
        if (mode === 'title')  return a.dataset.title.localeCompare(b.dataset.title);
        if (mode === 'type')   return a.dataset.scope.localeCompare(b.dataset.scope);
        return 0;
    });
    rows.forEach(r => list.appendChild(r));
}

function printStoredReport(rk) {
    const data = window['_rptStored_' + rk];
    if (!data) { toast('⚠️ No data for this report'); return; }
    const title   = data.title || 'Report';
    const content = renderReportHTML(data);
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head>
        <meta charset="utf-8">
        <title>${title}</title>
        <style>
            body { font-family: system-ui, sans-serif; font-size: 13px; color: #1a2235; padding: 24px; max-width: 900px; margin: 0 auto; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 8px; }
            th { background: #003087; color: #fff; padding: 6px 10px; text-align: left; font-size: 11px; }
            th:not(:first-child):not(:nth-child(2)) { text-align: center; }
            td { padding: 5px 10px; border-bottom: 1px solid #e2e8f0; }
            td:not(:first-child):not(:nth-child(2)) { text-align: center; }
            tr:nth-child(even) { background: #f8fafc; }
            h2 { font-size: 16px; color: #003087; border-bottom: 2px solid #e2e8f0; padding-bottom: 5px; margin: 20px 0 10px; }
            svg { max-width: 100%; }
            @media print { body { padding: 0; } }
        </style>
    </head><body>
        <h1 style="font-size:20px;color:#003087;margin-bottom:4px">${title}</h1>
        ${content}
        <script>window.onload = function(){ window.print(); }<\/script>
    </body></html>`);
    win.document.close();
}
async function generateReport() {
    const btn = document.getElementById('rpt-generate-btn');
    const title = document.getElementById('rpt-title').value.trim();
    if (!title) { toast('⚠️ Enter a report title'); return; }

    let payload = { action: 'get_report_data', scope: _rptScope };
    if (_rptScope === 'game') {
        const gk = document.getElementById('rpt-game-key').value;
        if (!gk) { toast('⚠️ Select a game'); return; }
        payload.game_key = gk;
    } else if (_rptScope === 'tournament') {
        const tn = document.getElementById('rpt-tournament').value;
        if (!tn) { toast('⚠️ Select a tournament'); return; }
        payload.tournament = tn;
    } else {
        const sn = document.getElementById('rpt-season-sel').value;
        if (!sn) { toast('⚠️ Select a season'); return; }
        payload.season = sn;
    }

    btn.textContent = '⏳ Loading…';
    btn.disabled = true;
    const j = await api(payload);
    btn.textContent = '📊 Generate Report';
    btn.disabled = false;

    if (!j.ok) { toast('⚠️ ' + j.error); return; }
    if (!j.games || j.games.length === 0) { toast('⚠️ No games found for that scope'); return; }

    // Pre-flight: warn about games with no selection
    const noSelection = j.games.filter(g => g.events_source === 'submission');
    if (noSelection.length > 0) {
        const names = noSelection.map(g => `${g.wave_team} vs ${g.opponent} (${g.game_date})`).join('\n');
        const proceed = confirm(
            `⚠️ ${noSelection.length} game${noSelection.length>1?'s have':' has'} no submission selected.\n\n` +
            `${names}\n\nThese will use the best available tracker submission. ` +
            `\n\nContinue anyway?`
        );
        if (!proceed) return;
    }

    _rptData = crunchReportData(j.games, title, document.getElementById('rpt-subtitle').value.trim(), _rptScope);

    const wrap = document.getElementById('rpt-preview-wrap');
    document.getElementById('rpt-preview-title').textContent = title;
    document.getElementById('rpt-preview-body').innerHTML = renderReportHTML(_rptData);
    wrap.style.display = '';
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function publishGeneratedReport() {
    if (!_rptData) { toast('⚠️ Generate a report first'); return; }
    const title    = document.getElementById('rpt-title').value.trim();
    const subtitle = document.getElementById('rpt-subtitle').value.trim();
    const j = await api({ action: 'publish_report', data: {
        type: _rptScope, title, subtitle, season: '', data: _rptData
    }});
    if (j.ok) { toast('✅ Published!'); setTimeout(() => goto('?t=<?=$T?>&view=reports&rk=' + j.report_key), 700); }
    else toast('⚠️ ' + j.error);
}

async function deleteReport(key) {
    if (!confirm('Delete this report? End users will no longer see it.')) return;
    const j = await api({ action: 'delete_report', report_key: key });
    if (j.ok) { toast('✅ Deleted'); setTimeout(() => goto('?t=<?=$T?>&view=reports'), 500); }
    else toast('⚠️ ' + j.error);
}

// Add scope button styling
document.head.insertAdjacentHTML('beforeend', `<style>
.rpt-scope-btn { background:#f1f5f9; color:var(--txt); border:1px solid var(--bdr); padding:7px 16px; border-radius:6px; font-size:13px; font-weight:600; }
.rpt-scope-btn.on { background:var(--navy); color:#fff; border-color:var(--navy); }
.ai-scope-btn { background:#f1f5f9; color:var(--txt); border:1px solid var(--bdr); padding:7px 16px; border-radius:6px; font-size:13px; font-weight:600; }
.ai-scope-btn.on { background:var(--navy); color:#fff; border-color:var(--navy); }
</style>`);
</script>
<?php elseif ($view === 'ask'): ?>
<div style="font-family:var(--fd);font-size:28px;color:var(--navy);margin-bottom:4px">🤖 Ask SkipShot</div>
<div style="font-size:13px;color:var(--muted);margin-bottom:20px">Ask plain-English questions about your stats. Powered by Gemini 2.5 Flash.</div>

<?php if(!empty($settings['gemini_api_key'])): ?>
<div class="card" style="border:1.5px solid rgba(66,133,244,.3);background:linear-gradient(135deg,rgba(66,133,244,.04),rgba(52,168,83,.04))">
    <div class="card-body">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
            <button class="btn ai-scope-btn on" data-scope="game"       onclick="setAiScope('game')">Single Game</button>
            <button class="btn ai-scope-btn"    data-scope="tournament" onclick="setAiScope('tournament')">Tournament</button>
            <button class="btn ai-scope-btn"    data-scope="all"        onclick="setAiScope('all')">All Games</button>
        </div>
        <div id="ai-sel-game" style="margin-bottom:12px">
            <label class="lbl">Game</label>
            <select class="inp" id="ai-game-key">
                <option value="">— select a game —</option>
                <?php foreach($games as $g): if(!isset($offMap[$g['game_key']])) continue; ?>
                <option value="<?=htmlspecialchars($g['game_key'])?>"><?=htmlspecialchars(date('M j Y',strtotime($g['game_date'])).' · '.$g['wave_team'].' vs '.$g['opponent'].($g['tournament']?' ('.$g['tournament'].')':''))?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="ai-sel-tournament" style="margin-bottom:12px;display:none">
            <label class="lbl">Tournament</label>
            <select class="inp" id="ai-tournament">
                <option value="">— select a tournament —</option>
                <?php foreach($distinctTournaments as $tn): ?>
                <option value="<?=htmlspecialchars($tn)?>"><?=htmlspecialchars($tn)?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="ai-sel-all" style="margin-bottom:12px;display:none">
            <div style="font-size:12px;color:var(--muted)">SkipShot will analyse all <?=count($approvedGames)?> approved game<?=count($approvedGames)!==1?'s':''?>.</div>
        </div>
        <div style="margin-bottom:12px">
            <label class="lbl">Your Question</label>
            <div style="display:flex;gap:8px">
                <input class="inp" id="ai-question" placeholder="e.g. Who had the most assists? What was our man-up conversion rate?" style="flex:1" onkeydown="if(event.key==='Enter')askData()">
                <button class="btn btn-ghost" onclick="toggleSpeech()" id="ai-mic-btn" title="Ask by voice" style="white-space:nowrap;display:none">🎤</button>
                <button class="btn btn-navy" onclick="askData()" id="ai-ask-btn" style="white-space:nowrap">✨ Ask SkipShot</button>
            </div>
        </div>
        <div style="margin-bottom:14px">
            <div style="font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);margin-bottom:6px">Suggested</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px" id="ai-suggestions">
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">Who had the most assists?</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">What was our man-up conversion rate?</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">Which goalie had the best save percentage?</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">What zone did we score from most?</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">Compare first half vs second half shooting.</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">Who drew the most kickouts?</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">What was our record this season?</button>
                <button class="btn btn-ghost btn-sm" onclick="setSuggestedQ(this)">Who was our top scorer overall?</button>
            </div>
        </div>
        <div id="ai-answer-wrap" style="display:none">
            <div style="font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);margin-bottom:8px">SkipShot says…</div>
            <div id="ai-answer" style="background:var(--bg);border:1px solid var(--bdr);border-radius:10px;padding:14px 16px;font-size:13px;line-height:1.8;white-space:pre-wrap;color:var(--txt)"></div>
            <div id="ai-answer-meta" style="font-size:11px;color:var(--muted);margin-top:6px;text-align:right"></div>
        </div>
        <div id="ai-error" style="display:none;padding:10px 14px;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;font-size:13px;color:#b91c1c"></div>
    </div>
</div>
<?php else: ?>
<div class="card" style="opacity:.8">
    <div class="card-body" style="text-align:center;padding:32px 20px">
        <div style="font-size:40px;margin-bottom:12px">🤖</div>
        <div style="font-weight:700;font-size:16px;color:var(--navy);margin-bottom:8px">SkipShot needs a Gemini key</div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:16px">Add your Gemini API key in Settings to enable Ask SkipShot.</div>
        <a href="?t=<?=$T?>&view=settings" class="btn btn-navy">⚙️ Go to Settings</a>
    </div>
</div>
<?php endif; ?>
<script>
const TOKEN = '<?=addslashes($token)?>';
async function api(body) {
    const r = await fetch('stats-api.php?t='+encodeURIComponent(TOKEN)+'&_='+Date.now(), {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({...body, token: TOKEN})
    });
    const text = await r.text();
    if (!r.ok) return {ok:false, error:'HTTP '+r.status+': '+text.slice(0,200)};
    try { return JSON.parse(text); } catch(e) { return {ok:false, error:'Bad JSON: '+text.slice(0,200)}; }
}
function toast(msg,dur=3000){const t=document.getElementById('toast');t.textContent=msg;t.style.display='block';clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',dur);}

// ── Ask SkipShot ────────────────────────────────────────────────────────────────────
let _aiScope = 'game';

function setAiScope(s) {
  _aiScope = s;
  document.querySelectorAll('.ai-scope-btn').forEach(b => b.classList.toggle('on', b.dataset.scope === s));
  document.getElementById('ai-sel-game').style.display       = s === 'game'       ? '' : 'none';
  document.getElementById('ai-sel-tournament').style.display = s === 'tournament' ? '' : 'none';
}

function setSuggestedQ(btn) {
  const inp = document.getElementById('ai-question');
  if (inp) { inp.value = btn.textContent.trim(); inp.focus(); }
}

async function askData() {
  const question = document.getElementById('ai-question')?.value?.trim();
  if (!question) { document.getElementById('ai-question').focus(); return; }

  const body = { action: 'ask_data', question, scope: _aiScope };
  if (_aiScope === 'game')       body.game_key   = document.getElementById('ai-game-key')?.value || '';
  if (_aiScope === 'tournament') body.tournament = document.getElementById('ai-tournament')?.value || '';

  const answerWrap = document.getElementById('ai-answer-wrap');
  const answerEl   = document.getElementById('ai-answer');
  const metaEl     = document.getElementById('ai-answer-meta');
  const errorEl    = document.getElementById('ai-error');
  const btn        = document.getElementById('ai-ask-btn');

  answerWrap.style.display = 'none';
  errorEl.style.display    = 'none';
  btn.textContent = '⏳ Thinking…';
  btn.disabled    = true;

  const t0 = Date.now();
  const j  = await api(body);
  const elapsed = ((Date.now() - t0) / 1000).toFixed(1);

  btn.textContent = '✨ Ask SkipShot';
  btn.disabled    = false;

  if (!j.ok) {
    errorEl.textContent  = '⚠️ ' + (j.error || 'Something went wrong');
    errorEl.style.display = '';
    return;
  }

  answerEl.textContent  = j.answer || '(no answer returned)';
  metaEl.textContent    = `Gemini 2.5 Flash · ${elapsed}s · ${j.games_used ?? '?'} game${(j.games_used??0)!==1?'s':''} analysed`;
  answerWrap.style.display = '';
}

// ── Voice Input ──────────────────────────────────────────────────────────────
(function initSpeech() {
  // Only show mic on touch devices with SpeechRecognition support
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR || !('ontouchstart' in window)) return;

  const micBtn = document.getElementById('ai-mic-btn');
  if (!micBtn) return;
  micBtn.style.display = '';  // reveal the button

  let recognition = null;
  let listening = false;

  micBtn.addEventListener('click', toggleSpeech);

  window.toggleSpeech = function() {
    if (listening) {
      recognition?.stop();
      return;
    }

    recognition = new SR();
    recognition.lang = 'en-CA';
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;

    const inp = document.getElementById('ai-question');
    const originalPlaceholder = inp.placeholder;

    recognition.onstart = () => {
      listening = true;
      micBtn.textContent = '🔴';
      micBtn.title = 'Tap to stop';
      inp.value = '';
      inp.placeholder = 'Listening…';
      inp.style.outline = '2px solid #ef4444';
    };

    recognition.onresult = (e) => {
      const transcript = Array.from(e.results)
        .map(r => r[0].transcript)
        .join('');
      inp.value = transcript;
    };

    recognition.onend = () => {
      listening = false;
      micBtn.textContent = '🎤';
      micBtn.title = 'Ask by voice';
      inp.placeholder = originalPlaceholder;
      inp.style.outline = '';
      // Focus the input so they can review before tapping Ask
      if (inp.value.trim()) inp.focus();
    };

    recognition.onerror = (e) => {
      listening = false;
      micBtn.textContent = '🎤';
      inp.placeholder = originalPlaceholder;
      inp.style.outline = '';
      if (e.error !== 'aborted') toast('🎤 ' + (e.error === 'not-allowed' ? 'Mic permission denied' : 'Speech error: ' + e.error));
    };

    recognition.start();
  };
})();
</script>
<?php elseif ($view !== 'settings'): ?>
<script>
const _gApiToken = '<?=addslashes($token)?>';
async function _gApi(body) {
  const r = await fetch('stats-api.php?t='+encodeURIComponent(_gApiToken)+'&_='+Date.now(), {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({...body, token: _gApiToken})
  });
  return r.json();
}
window.deleteAbandonedGame = async function(gameKey, label) {
  if (!confirm('Delete "'+label+'"?\n\nThis game has no submissions and will be permanently removed. This cannot be undone.')) return;
  const j = await _gApi({action:'delete_game', game_key:gameKey});
  if (j.ok) location.reload();
  else alert('⚠️ '+(j.error||'Delete failed'));
};
window.quickFinalize = async function(gameKey, subId, trackerName) {
  if (!confirm('Use '+trackerName+"'s stats as the official record for this game?")) return;
  const j = await _gApi({action:'select_submission', submission_id:subId});
  if (j.ok) location.reload();
  else alert('⚠️ '+(j.error||'Error'));
};
window.approveSubmission = async function(subId, trackerName) {
  if (!confirm('Approve "'+trackerName+'" as the official record for this game?')) return;
  const j = await _gApi({action:'select_submission', submission_id:subId});
  if (j.ok) location.reload();
  else alert('⚠️ '+(j.error||'Approval failed'));
};
window.showDeleteAll = function() {
  const el = document.getElementById('delete-all-overlay');
  if (el) { document.getElementById('delete-confirm-input').value=''; document.getElementById('confirm-delete-btn').disabled=true; el.style.display='flex'; }
};
window.confirmDeleteAll = async function() {
  const j = await _gApi({action:'delete_all_games'});
  if (j.ok) location.reload();
  else alert('⚠️ '+(j.error||'Delete failed'));
};
</script>
<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Dashboard</div>
<div style="font-family:var(--fd);font-size:30px;color:var(--navy);margin-bottom:2px">Ottawa Wave Swim &amp; Polo</div>
<div style="font-size:13px;color:var(--muted);margin-bottom:20px">Stats Admin · <?=date('l, F j, Y')?></div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:24px">
    <a href="?t=<?=$T?>&view=rosters" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 10px;background:var(--navy);border-radius:12px;color:#fff;text-decoration:none;transition:filter .15s;text-align:center" onmouseover="this.style.filter='brightness(1.15)'" onmouseout="this.style.filter=''">
        <span style="font-size:26px">📋</span>
        <span style="font-family:var(--fd);font-size:15px;letter-spacing:.3px">Manage Rosters</span>
        <span style="font-size:11px;opacity:.65"><?=count($rosters)?> saved</span>
    </a>
    <a href="?t=<?=$T?>&view=teams" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 10px;background:var(--gold);border-radius:12px;color:#1a2235;text-decoration:none;transition:filter .15s;text-align:center" onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter=''">
        <span style="font-size:26px">🏷</span>
        <span style="font-family:var(--fd);font-size:15px;letter-spacing:.3px">Manage Opponents</span>
        <span style="font-size:11px;opacity:.7"><?=count(array_unique(array_column($teamNames,'name')))?> teams</span>
    </a>
    <a href="?t=<?=$T?>&view=tournaments" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 10px;background:var(--gold);border-radius:12px;color:#1a2235;text-decoration:none;transition:filter .15s;text-align:center" onmouseover="this.style.filter='brightness(1.08)'" onmouseout="this.style.filter=''">
        <span style="font-size:26px">🏆</span>
        <span style="font-family:var(--fd);font-size:15px;letter-spacing:.3px">Manage Tournaments</span>
        <span style="font-size:11px;opacity:.7"><?=count($tournamentNames)?> listed</span>
    </a>
</div>

<?php if(empty($games)) { ?>
<div class="banner banner-info">
    <span style="font-size:20px">💡</span>
    <div><div style="font-weight:700;margin-bottom:3px">Getting started</div>
    <div style="font-size:12px;color:var(--muted);line-height:1.6">
        1. Go to <strong>Manage Rosters</strong> and import your team PDFs<br>
        2. Add team and tournament names so end users can select them<br>
        3. End users create games directly in the tracker app and submit stats here
    </div></div>
</div>
<?php } else { ?>
<div class="card">
    <div class="card-hdr">
        <span class="card-ttl">Games</span>
        <span style="font-size:12px;color:var(--muted)"><?=count(array_filter($games,fn($g)=>isset($offMap[$g['game_key']])))?> / <?=count($games)?> finalized</span>
    </div>
    <div style="padding:10px 14px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Sort:</span>
        <button class="btn btn-ghost btn-sm sort-btn on" data-sort="date"   onclick="sortGames('date')">Date ↓</button>
        <button class="btn btn-ghost btn-sm sort-btn"    data-sort="status" onclick="sortGames('status')">Status</button>
        <button class="btn btn-ghost btn-sm sort-btn"    data-sort="subs"   onclick="sortGames('subs')">Submissions</button>
        <button class="btn btn-ghost btn-sm sort-btn"    data-sort="opp"    onclick="sortGames('opp')">Opponent</button>
    </div>
    <table class="tbl" id="games-tbl" style="border-collapse:collapse">
        <thead><tr>
            <th style="text-align:left;padding-left:14px">Game</th>
            <th style="text-align:left">Date</th>
            <th style="text-align:center">Status</th>
            <th></th>
        </tr></thead>
        <tbody id="games-tbody">
        <?php foreach($games as $g):
            $fin      = isset($offMap[$g['game_key']]);
            $subCount = (int)$g['sub_count'];
            $gameSubs = $allSubs[$g['game_key']] ?? [];
            $statusVal = $fin ? 'finalized' : ($subCount > 0 ? 'review' : 'empty');
            // Extract approved sub ID from method field e.g. "selected:42:John Smith"
            $approvedSubId = null;
            if ($fin) {
                $method = $offMap[$g['game_key']] ?? '';
                if (preg_match('/^selected:(\d+):/', $method, $m)) $approvedSubId = (int)$m[1];
            }
        ?>
        <!-- Game row -->
        <tr data-date="<?=htmlspecialchars($g['game_date'])?>"
            data-status="<?=$statusVal?>"
            data-subs="<?=$subCount?>"
            data-opp="<?=htmlspecialchars(strtolower($g['opponent']))?>"
            style="background:<?=$fin?'#f0fff4':($subCount>0?'rgba(245,158,11,.04)':'')?>">
            <td style="font-weight:700;padding:10px 10px 10px 14px">
                <div style="color:var(--navy);font-size:14px"><?=htmlspecialchars($g['wave_team'])?> vs <?=htmlspecialchars($g['opponent'])?></div>
                <?php if($g['tournament']): ?>
                <div style="font-size:10px;color:var(--muted);font-weight:400;margin-top:1px"><?=htmlspecialchars($g['tournament'])?></div>
                <?php endif; ?>
            </td>
            <td style="font-family:var(--fm);font-size:12px;color:var(--muted);white-space:nowrap"><?=date('M j, Y',strtotime($g['game_date']))?></td>
            <td style="text-align:center">
                <?php if($fin): ?>
                    <span class="pill pill-ok">✅ Finalized</span>
                <?php elseif($subCount===0): ?>
                    <span class="pill pill-pend">⏳ Awaiting</span>
                <?php else: ?>
                    <span class="pill pill-warn">⚠️ <?=$subCount?> Pending</span>
                <?php endif; ?>
            </td>
            <td style="text-align:right;padding-right:12px;white-space:nowrap">
                <button class="btn btn-ghost btn-sm" onclick="openGameDrawer('<?=addslashes($g['game_key'])?>')" title="View full stats">👁 View</button>
                <?php if($fin): ?>
                <button class="btn btn-ghost btn-sm" onclick="downloadGamePdf('<?=addslashes($g['game_key'])?>','<?=addslashes($g['wave_team'].' vs '.$g['opponent'])?>')" title="Download PDF report">⬇ PDF</button>
                <?php $pushedDate = $pushedGameKeys[$g['game_key']] ?? null; ?>
                <?php if($pushedDate): ?>
                <button class="btn btn-ghost btn-sm" style="color:var(--muted);cursor:default" title="Already pushed on <?=$pushedDate?>" disabled>✅ Pushed</button>
                <?php else: ?>
                <button class="btn btn-navy btn-sm" onclick="pushGameReport('<?=addslashes($g['game_key'])?>','<?=addslashes($g['wave_team'].' vs '.$g['opponent'])?>')" title="Publish report to end users">📣 Push</button>
                <?php endif; ?>
                <?php endif; ?>
                <?php if($subCount === 0 && !$fin): ?>
                <button class="btn btn-sm" style="background:rgba(239,68,68,.08);color:var(--danger);border:1px solid rgba(239,68,68,.25)"
                    onclick="deleteAbandonedGame('<?=addslashes($g['game_key'])?>','<?=addslashes($g['wave_team'].' vs '.$g['opponent'])?>')" title="Delete — no submissions">🗑</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php foreach($gameSubs as $s):
            $initials = implode('', array_map(fn($w)=>strtoupper($w[0]), array_filter(explode(' ', trim($s['tracker_name']??'?')))));
            $initials = substr($initials, 0, 2) ?: '?';
            $isApproved = $fin && (string)$approvedSubId === (string)$s['id'];
            // Pick avatar colour based on first char
            $avatarColors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#ef4444'];
            $avatarColor  = $avatarColors[ord($initials[0]) % count($avatarColors)];
        ?>
        <!-- Submission sub-row -->
        <tr style="background:<?=$isApproved?'rgba(22,163,74,.06)':'#fafbfd'?>;border-top:1px solid var(--bdr)">
            <td style="padding:8px 10px 8px 32px" colspan="1">
                <div style="display:flex;align-items:center;gap:10px">
                    <!-- Initials avatar -->
                    <div style="width:30px;height:30px;border-radius:50%;background:<?=$avatarColor?>;color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;letter-spacing:.5px">
                        <?=htmlspecialchars($initials)?>
                    </div>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:var(--text)">
                            <?=htmlspecialchars($s['tracker_name'] ?: 'Unknown')?>
                            <?php if($s['is_coach']): ?><span style="font-size:10px;background:rgba(0,48,135,.1);color:var(--navy);border-radius:4px;padding:1px 6px;font-weight:700;margin-left:4px">COACH</span><?php endif; ?>
                            <?php if($isApproved): ?><span style="font-size:10px;background:rgba(22,163,74,.15);color:#15803d;border-radius:4px;padding:1px 6px;font-weight:700;margin-left:4px">✓ APPROVED</span><?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:var(--muted);margin-top:1px">
                            Score: <strong><?=$s['wave_score']??'?'?> – <?=$s['opp_score']??'?'?></strong>
                            &middot; <?=(int)$s['event_count']?> events
                        </div>
                    </div>
                </div>
            </td>
            <td colspan="2"></td>
            <td style="text-align:right;padding-right:12px;white-space:nowrap">
                <?php if(!$isApproved): ?>
                <button class="btn btn-sm" style="background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.3);font-weight:700"
                    onclick="approveSubmission(<?=$s['id']?>,'<?=addslashes($s['tracker_name']?:'Unknown')?>')"
                    title="Approve this submission as the official record">
                    ❓ Approve
                </button>
                <?php else: ?>
                <span style="font-size:11px;color:#15803d;font-weight:700">Official record</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php } ?>

<!-- ── Game Detail Drawer ─────────────────────────────────────────────────── -->
<div id="game-drawer-overlay" onclick="closeGameDrawer()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200"></div>
<div id="game-drawer" style="display:none;position:fixed;top:0;right:0;width:min(700px,100vw);height:100vh;background:#fff;z-index:201;box-shadow:-4px 0 32px rgba(0,0,0,.18);overflow-y:auto;flex-direction:column">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid var(--bdr);position:sticky;top:0;background:#fff;z-index:1;gap:10px">
        <div style="min-width:0">
            <div id="drawer-title" style="font-family:var(--fd);font-size:18px;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
            <div id="drawer-subtitle" style="font-size:12px;color:var(--muted);margin-top:2px"></div>
        </div>
        <div style="display:flex;gap:8px;flex-shrink:0">
            <button id="drawer-pdf-btn" onclick="printDrawerReport()" class="btn btn-ghost btn-sm" style="display:none">⬇ PDF</button>
            <button id="drawer-push-btn" onclick="pushDrawerReport()" class="btn btn-navy btn-sm" style="display:none">📣 Push to Users</button>
            <button onclick="closeGameDrawer()" class="btn btn-ghost btn-sm">✕</button>
        </div>
    </div>
    <div id="drawer-subs" style="padding:14px 20px 0;display:none"></div>
    <div id="drawer-body" style="padding:20px;font-size:13px;line-height:1.6"></div>
</div>

<script>
// ── Sortable games table ─────────────────────────────────────────────────────
const _pushedGameKeys = <?=json_encode($pushedGameKeys)?>;
let _sortDir = { date: -1, status: 1, subs: -1, opp: 1 };
let _sortCur = 'date';
function sortGames(col) {
    if (_sortCur === col) _sortDir[col] *= -1;
    _sortCur = col;
    document.querySelectorAll('.sort-btn').forEach(b => b.classList.toggle('on', b.dataset.sort === col));
    const tbody = document.getElementById('games-tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        let av = a.dataset[col], bv = b.dataset[col];
        if (col === 'subs') return (_sortDir[col]) * (parseInt(bv) - parseInt(av));
        if (col === 'date') return (_sortDir[col]) * av.localeCompare(bv) * -1;
        const statusOrder = { finalized: 0, review: 1, empty: 2 };
        if (col === 'status') return (_sortDir[col]) * ((statusOrder[av]||9) - (statusOrder[bv]||9));
        return (_sortDir[col]) * av.localeCompare(bv);
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── Game detail drawer ───────────────────────────────────────────────────────
let _drawerGameKey = null;
let _drawerGameData = null;

async function openGameDrawer(gameKey) {
    _drawerGameKey = gameKey;
    _drawerGameData = null;
    const overlay  = document.getElementById('game-drawer-overlay');
    const drawer   = document.getElementById('game-drawer');
    const body     = document.getElementById('drawer-body');
    const subsEl   = document.getElementById('drawer-subs');
    document.getElementById('drawer-title').textContent    = 'Loading…';
    document.getElementById('drawer-subtitle').textContent = '';
    document.getElementById('drawer-pdf-btn').style.display  = 'none';
    document.getElementById('drawer-push-btn').style.display = 'none';
    body.innerHTML    = '<div style="color:var(--muted);padding:20px 0;text-align:center">Loading…</div>';
    subsEl.innerHTML  = '';
    subsEl.style.display = 'none';
    overlay.style.display = 'block';
    drawer.style.display  = 'flex';

    const j = await _gApi({ action: 'get_game_detail', game_key: gameKey });
    if (!j.ok) { body.innerHTML = '<div style="color:var(--danger)">⚠️ ' + (j.error||'Error loading game') + '</div>'; return; }

    const g    = j.game;
    const fin  = j.finalized;
    const subs = j.submissions || [];

    document.getElementById('drawer-title').textContent    = (g.wave_team||'Wave') + ' vs ' + (g.opponent||'Opponent');
    document.getElementById('drawer-subtitle').textContent = [
        g.game_date ? new Date(g.game_date+'T12:00:00').toLocaleDateString('en-CA',{month:'short',day:'numeric',year:'numeric'}) : '',
        g.tournament || ''
    ].filter(Boolean).join(' · ');

    // ── Submissions panel (always shown if there are any) ──
    if (subs.length > 0) {
        let sh = `<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:8px">Submissions</div>`;
        subs.forEach(s => {
            const initials = (s.tracker_name||'?').split(' ').map(w=>w[0]?.toUpperCase()||'').join('').slice(0,2)||'?';
            const colors   = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#ef4444'];
            const color    = colors[initials.charCodeAt(0) % colors.length];
            const approved = fin && j.official && j.official.method && j.official.method.includes(':'+s.id+':');
            sh += `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--bdr);border-radius:8px;margin-bottom:6px;background:${approved?'rgba(22,163,74,.05)':'var(--bg)'}">
                <div style="width:28px;height:28px;border-radius:50%;background:${color};color:#fff;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">${initials}</div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:13px">${s.tracker_name||'Unknown'}${s.is_coach?' <span style="font-size:10px;background:rgba(0,48,135,.1);color:var(--navy);border-radius:4px;padding:1px 5px;font-weight:700">COACH</span>':''}${approved?' <span style="font-size:10px;background:rgba(22,163,74,.15);color:#15803d;border-radius:4px;padding:1px 5px;font-weight:700">✓ APPROVED</span>':''}</div>
                    <div style="font-size:11px;color:var(--muted)">Score: ${s.wave_score ?? '?'} – ${s.opp_score ?? '?'} · ${s.event_count||0} events</div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0">
                    ${!approved ? `<button class="btn btn-sm" style="background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.3);font-weight:700" onclick="approveSubmission(${s.id},'${(s.tracker_name||'').replace(/'/g,"\\'")}')">❓ Approve</button>` : ''}
                    <button class="btn btn-sm" style="background:rgba(239,68,68,.06);color:var(--danger);border:1px solid rgba(239,68,68,.2)" onclick="deleteSubmission(${s.id},'${(s.tracker_name||'').replace(/'/g,"\\'")}','${gameKey}')">🗑</button>
                </div>
            </div>`;
        });
        subsEl.innerHTML     = sh;
        subsEl.style.display = 'block';
    }

    // ── Report body ──
    if (fin && j.official) {
        document.getElementById('drawer-pdf-btn').style.display  = '';
        document.getElementById('drawer-push-btn').style.display = '';
        const pushBtn = document.getElementById('drawer-push-btn');
        if (_pushedGameKeys[gameKey]) {
            pushBtn.textContent = '✅ Pushed';
            pushBtn.disabled    = true;
            pushBtn.title       = 'Already pushed on ' + _pushedGameKeys[gameKey];
            pushBtn.className   = 'btn btn-ghost btn-sm';
            pushBtn.style.color = 'var(--muted)';
        } else {
            pushBtn.textContent = '📣 Push to Users';
            pushBtn.disabled    = false;
            pushBtn.title       = '';
            pushBtn.className   = 'btn btn-navy btn-sm';
            pushBtn.style.color = '';
        }

        // Build a synthetic single-game structure for crunchReportData
        const players = g.players || [];
        const og = j.official;
        const syntheticGame = [{
            game_key: gameKey,
            game_date: g.game_date,
            opponent: g.opponent,
            tournament: g.tournament || '',
            players: players,
            official_events: og.events || [],
            wave_score: og.wave_score,
            opp_score:  og.opp_score,
        }];
        const title    = (g.wave_team||'Wave') + ' vs ' + (g.opponent||'Opponent');
        const subtitle = document.getElementById('drawer-subtitle').textContent;
        _drawerGameData = crunchReportData(syntheticGame, title, subtitle, 'game');
        body.innerHTML = renderReportHTML(_drawerGameData);
    } else if (subs.length > 0) {
        body.innerHTML = `<div style="text-align:center;padding:30px 20px;color:var(--muted)">
            <div style="font-size:32px;margin-bottom:10px">⏳</div>
            <div style="font-weight:700;margin-bottom:4px">Awaiting Approval</div>
            <div style="font-size:12px">Approve a submission above to generate the full report.</div>
        </div>`;
    } else {
        body.innerHTML = `<div style="text-align:center;padding:30px 20px;color:var(--muted)">
            <div style="font-size:32px;margin-bottom:10px">📭</div>
            <div style="font-weight:700;margin-bottom:4px">No Submissions Yet</div>
            <div style="font-size:12px">No stats have been submitted for this game.</div>
        </div>`;
    }
}

function closeGameDrawer() {
    document.getElementById('game-drawer-overlay').style.display = 'none';
    document.getElementById('game-drawer').style.display         = 'none';
    _drawerGameKey  = null;
    _drawerGameData = null;
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeGameDrawer(); });

// ── Drawer: Print to PDF ─────────────────────────────────────────────────────
function printDrawerReport() {
    if (!_drawerGameData) return;
    const title   = document.getElementById('drawer-title').textContent;
    const content = document.getElementById('drawer-body').innerHTML;
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
    <style>
        body{font-family:system-ui,sans-serif;font-size:13px;color:#1a2235;padding:24px;max-width:860px;margin:0 auto}
        table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px}
        th{background:#003087;color:#fff;padding:6px 10px;text-align:left;font-size:11px}
        th:not(:first-child):not(:nth-child(2)){text-align:center}
        td{padding:5px 10px;border-bottom:1px solid #e2e8f0}
        td:not(:first-child):not(:nth-child(2)){text-align:center}
        tr:nth-child(even){background:#f8fafc}
        h2{font-size:15px;color:#003087;border-bottom:2px solid #e2e8f0;padding-bottom:5px;margin:20px 0 10px;text-transform:uppercase;letter-spacing:1px}
        svg{max-width:100%}
        @media print{body{padding:0}}
    </style></head><body>
    <h1 style="font-size:20px;color:#003087;margin-bottom:2px">${title}</h1>
    <div style="font-size:12px;color:#64748b;margin-bottom:20px">${document.getElementById('drawer-subtitle').textContent}</div>
    ${content}
    <script>window.onload=function(){window.print()}<\/script>
    </body></html>`);
    win.document.close();
}

// ── Game row: direct PDF (opens drawer data or loads fresh) ──────────────────
async function downloadGamePdf(gameKey, label) {
    if (_drawerGameKey === gameKey && _drawerGameData) { printDrawerReport(); return; }
    const j = await _gApi({ action: 'get_game_detail', game_key: gameKey });
    if (!j.ok || !j.official) { alert('⚠️ No approved stats for this game yet.'); return; }
    const g = j.game;
    const syntheticGame = [{
        game_key: gameKey, game_date: g.game_date, opponent: g.opponent,
        tournament: g.tournament||'', players: g.players||[],
        official_events: j.official.events||[], wave_score: j.official.wave_score, opp_score: j.official.opp_score,
    }];
    const data = crunchReportData(syntheticGame, label, '', 'game');
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${label}</title>
    <style>body{font-family:system-ui,sans-serif;font-size:13px;color:#1a2235;padding:24px;max-width:860px;margin:0 auto}
    table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px}th{background:#003087;color:#fff;padding:6px 10px;text-align:left;font-size:11px}
    th:not(:first-child):not(:nth-child(2)){text-align:center}td{padding:5px 10px;border-bottom:1px solid #e2e8f0}td:not(:first-child):not(:nth-child(2)){text-align:center}
    tr:nth-child(even){background:#f8fafc}h2{font-size:15px;color:#003087;border-bottom:2px solid #e2e8f0;padding-bottom:5px;margin:20px 0 10px;text-transform:uppercase;letter-spacing:1px}
    svg{max-width:100%}@media print{body{padding:0}}</style></head><body>
    <h1 style="font-size:20px;color:#003087;margin-bottom:20px">${label}</h1>
    ${renderReportHTML(data)}
    <script>window.onload=function(){window.print()}<\/script></body></html>`);
    win.document.close();
}

// ── Drawer: Push report to end users ─────────────────────────────────────────
async function pushDrawerReport() {
    if (!_drawerGameData || !_drawerGameKey) return;
    const title = document.getElementById('drawer-title').textContent;
    const sub   = document.getElementById('drawer-subtitle').textContent;
    const btn   = document.getElementById('drawer-push-btn');
    btn.disabled = true; btn.textContent = '⏳ Pushing…';
    const data = { ..._drawerGameData, game_key: _drawerGameKey };
    const j = await _gApi({ action: 'publish_report', data: {
        type: 'game', title, subtitle: sub, season: '', data
    }});
    if (j.ok) {
        btn.textContent = '✅ Pushed!';
        btn.className   = 'btn btn-ghost btn-sm';
        btn.style.color = 'var(--muted)';
        setTimeout(() => { closeGameDrawer(); location.reload(); }, 900);
    } else {
        btn.disabled = false; btn.textContent = '📣 Push to Users';
        alert('⚠️ ' + (j.error||'Publish failed'));
    }
}

// ── Game row: push without opening drawer ─────────────────────────────────────
async function pushGameReport(gameKey, label) {
    if (!confirm('Publish a report for "'+label+'" to end users?')) return;
    const j = await _gApi({ action: 'get_game_detail', game_key: gameKey });
    if (!j.ok || !j.official) { alert('⚠️ No approved stats for this game yet.'); return; }
    const g = j.game;
    const syntheticGame = [{
        game_key: gameKey, game_date: g.game_date, opponent: g.opponent,
        tournament: g.tournament||'', players: g.players||[],
        official_events: j.official.events||[], wave_score: j.official.wave_score, opp_score: j.official.opp_score,
    }];
    const data = crunchReportData(syntheticGame, label, '', 'game');
    data.game_key = gameKey; // track which game this report belongs to
    const r = await _gApi({ action: 'publish_report', data: {
        type: 'game', title: label, subtitle: '', season: '', data
    }});
    if (r.ok) { toast('✅ Report published to end users!'); setTimeout(() => location.reload(), 800); }
    else alert('⚠️ ' + (r.error||'Publish failed'));
}


// ── Publish generated report ─────────────────────────────────────────────────
// ── Delete submission ────────────────────────────────────────────────────────
window.deleteSubmission = async function(subId, name, gameKey) {
    if (!confirm('Delete submission from "'+name+'"?\n\nThis cannot be undone.')) return;
    const j = await _gApi({ action: 'delete_submission', submission_id: subId });
    if (j.ok) { closeGameDrawer(); location.reload(); }
    else alert('⚠️ ' + (j.error||'Delete failed'));
};
</script>

<?php endif; ?>

<?php if ($view === 'settings'): ?>
<script>
async function saveAiSettings() {
  const key = document.getElementById('s-gemini-key')?.value?.trim() || '';
  const statusEl = document.getElementById('s-ai-status');
  if (statusEl) statusEl.textContent = '⏳ Saving…';
  try {
    const token = '<?=addslashes($token)?>';
    const r = await fetch('stats-api.php?t=' + encodeURIComponent(token) + '&_=' + Date.now(), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save_settings', token, settings: { gemini_api_key: key } })
    });
    const j = await r.json();
    if (j.ok) {
      if (statusEl) statusEl.textContent = '✅ Saved! Reload the page to see the status update.';
      const t = document.getElementById('toast');
      if (t) { t.textContent = '✅ AI settings saved'; t.style.display = 'block'; setTimeout(() => t.style.display = 'none', 3000); }
    } else {
      if (statusEl) statusEl.textContent = '⚠️ ' + (j.error || 'Save failed');
    }
  } catch(e) {
    if (statusEl) statusEl.textContent = '⚠️ Network error: ' + e.message;
  }
}
</script>
<div style="margin-bottom:18px">
  <div style="font-family:var(--fd);font-size:28px;color:var(--navy)">⚙️ Settings</div>
  <div style="font-size:13px;color:var(--muted)">Customise the club name, colours, and AI settings.</div>
</div>

<!-- ── Branding ── -->
<div class="card" style="margin-bottom:16px">
  <div class="card-hdr"><span class="card-ttl">🎨 Branding</span></div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:20px">
    <div>
      <label class="lbl">Club / Team Name</label>
      <input class="inp" id="s-club-name" value="<?=$clubName?>" placeholder="e.g. WAVE" style="max-width:320px">
      <div style="font-size:11px;color:var(--muted);margin-top:5px">Replaces "WAVE" throughout the admin and tracker app.</div>
    </div>
    <div>
      <label class="lbl">Primary Colour</label>
      <div style="display:flex;align-items:center;gap:12px">
        <input type="color" id="s-primary" value="<?=$colorPri?>" style="width:48px;height:40px;border:none;background:none;cursor:pointer;padding:0">
        <input class="inp" id="s-primary-hex" value="<?=$colorPri?>" placeholder="#003087" style="max-width:120px;font-family:var(--fm)">
        <div id="s-primary-preview" style="width:80px;height:40px;border-radius:8px;background:<?=$colorPri?>;border:1px solid var(--bdr)"></div>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:5px">Used for the header, buttons, and navy accents.</div>
    </div>
    <div>
      <label class="lbl">Secondary / Accent Colour</label>
      <div style="display:flex;align-items:center;gap:12px">
        <input type="color" id="s-secondary" value="<?=$colorSec?>" style="width:48px;height:40px;border:none;background:none;cursor:pointer;padding:0">
        <input class="inp" id="s-secondary-hex" value="<?=$colorSec?>" placeholder="#FFC72C" style="max-width:120px;font-family:var(--fm)">
        <div id="s-secondary-preview" style="width:80px;height:40px;border-radius:8px;background:<?=$colorSec?>;border:1px solid var(--bdr)"></div>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:5px">Used for gold highlights and active states.</div>
    </div>
    <div>
      <label class="lbl">Preview</label>
      <div id="s-preview-bar" style="height:48px;border-radius:10px;display:flex;align-items:center;padding:0 18px;gap:12px;background:<?=$colorPri?>;border-bottom:3px solid <?=$colorSec?>">
        <span id="s-preview-title" style="font-family:var(--fd);font-size:22px;color:#fff;letter-spacing:1px"><?=$clubName?> STATS</span>
        <span id="s-preview-badge" style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:<?=$colorSec?>;color:#1a2235"><?=$clubName?></span>
      </div>
    </div>
    <div><button class="btn btn-navy" onclick="saveSettings()">💾 Save Settings</button></div>
  </div>
</div>

<!-- ── AI / SkipShot Settings ── -->
<div class="card" style="margin-bottom:16px">
  <div class="card-hdr"><span class="card-ttl">🤖 AI Settings</span></div>
  <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
    <div style="font-size:13px;color:var(--muted)">Powers the Ask SkipShot natural language analysis feature.</div>
    <div>
      <label class="lbl">Google Gemini API Key</label>
      <input class="inp" id="s-gemini-key" type="text" value="<?=htmlspecialchars($settings['gemini_api_key'] ?? '')?>" placeholder="AIza…" style="font-family:var(--fm)">
      <div style="font-size:11px;color:var(--muted);margin-top:5px">
        Get your key at <a href="https://aistudio.google.com/app/apikey" target="_blank" style="color:var(--navy)">aistudio.google.com</a>. Stored securely server-side — never exposed to end users. Model: <code style="font-family:var(--fm)">gemini-2.5-flash</code>.
      </div>
    </div>
    <?php if(!empty($settings['gemini_api_key'])): ?>
    <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:rgba(22,101,52,.07);border:1px solid rgba(22,101,52,.2);border-radius:8px;font-size:12px;color:#166534">
      ✅ Gemini key is set — Ask SkipShot is ready to use.
    </div>
    <?php else: ?>
    <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25);border-radius:8px;font-size:12px;color:#92400e">
      ⚠️ No key set — Ask SkipShot will be unavailable until you add one.
    </div>
    <?php endif; ?>
    <div>
      <button class="btn btn-navy" onclick="saveAiSettings()">💾 Save AI Settings</button>
      <div id="s-ai-status" style="font-size:12px;color:var(--muted);margin-top:6px"></div>
    </div>
  </div>
</div>

<!-- ── Database Backup ── -->
<div class="card" style="margin-bottom:16px">
    <div class="card-hdr"><span class="card-ttl">💾 Database Backup</span></div>
    <div class="card-body">
        <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Download a full copy of the SQLite database. Keep this somewhere safe — it contains all games, rosters, submissions, and settings.</div>
        <a href="stats-api.php?t=<?=$token?>&action=download_db" class="btn btn-ghost" style="display:inline-block;text-decoration:none">📥 Download stats.db</a>
    </div>
</div>

<!-- ── Danger Zone ── -->
<div class="card" style="border-color:rgba(239,68,68,.25)">
    <div class="card-hdr" style="background:rgba(239,68,68,.04)"><span class="card-ttl" style="color:var(--danger)">⚠️ Danger Zone</span></div>
    <div class="card-body">
        <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Permanently delete all games, submissions, and official records. Rosters, team names, and tournament names are kept.</div>
        <button class="btn btn-danger" onclick="showDeleteAll()">🗑 Delete All Game Data</button>
    </div>
</div>
<?php endif; ?>

</main>
</div><!-- .layout -->

<div style="text-align:center;padding:14px;font-size:11px;color:var(--muted);border-top:1px solid var(--bdr);background:var(--sur)">
    Built with <a href="https://claude.ai" target="_blank" style="color:var(--navy);font-weight:700;text-decoration:none">Claude</a> by Anthropic &nbsp;·&nbsp; Ottawa Wave Swim &amp; Polo
</div>

<!-- ══ PDF Team Picker Overlay ══ -->
<div id="tournament-picker-overlay" class="overlay" style="display:none" onclick="if(event.target===this)closeTournamentPicker()">
    <div class="overlay-box" style="max-width:440px">
        <div class="overlay-hdr">
            <div class="overlay-ttl">📋 Assign a Tournament</div>
            <button class="btn btn-ghost btn-sm" onclick="closeTournamentPicker()">✕</button>
        </div>
        <div style="padding:14px 20px 6px;font-size:13px;color:var(--muted)">Which tournament are these rosters for? This will be saved with each roster so the tracker can pre-fill it.</div>
        <div style="padding:10px 20px 4px;display:flex;flex-direction:column;gap:8px;max-height:240px;overflow-y:auto" id="tournament-picker-list"></div>
        <div style="padding:12px 20px 6px;border-top:1px solid var(--bdr)">
            <label class="lbl" style="margin-bottom:6px;display:block">Or enter a new tournament name</label>
            <div style="display:flex;gap:8px">
                <input class="inp" id="tournament-custom-input" placeholder="e.g. Spring Invitational 2026" style="flex:1;margin:0">
                <button class="btn btn-navy" onclick="confirmTournamentPicker()">Use This</button>
            </div>
        </div>
        <div style="padding:10px 20px 16px;border-top:1px solid var(--bdr)">
            <button class="btn btn-out" style="width:100%" onclick="skipTournamentPicker()">Skip / No Tournament</button>
        </div>
    </div>
</div>

<div id="team-picker-overlay" class="overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="overlay-box" style="max-width:460px">
        <div class="overlay-hdr">
            <div class="overlay-ttl">Teams Found in PDF</div>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('team-picker-overlay').style.display='none'">✕</button>
        </div>
        <div style="padding:12px 20px 6px;font-size:13px;color:var(--muted);border-bottom:1px solid var(--bdr)">Select teams to import. Tap a name to rename it before saving.</div>
        <div id="team-picker-list" style="padding:12px 20px 4px;max-height:320px;overflow-y:auto"></div>
        <div style="padding:12px 20px 16px;border-top:1px solid var(--bdr);display:flex;gap:8px;align-items:center">
            <button class="btn btn-ghost btn-sm" onclick="pickerSelectAll(true)">Select All</button>
            <button class="btn btn-ghost btn-sm" onclick="pickerSelectAll(false)">None</button>
            <button class="btn btn-navy" style="margin-left:auto" onclick="importSelectedTeams()">✅ Import Selected</button>
        </div>
    </div>
</div>

<!-- ══ Delete All Overlay ══ -->
<div id="delete-all-overlay" class="overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="overlay-box" style="max-width:420px">
        <div class="overlay-hdr" style="background:rgba(239,68,68,.06)">
            <div class="overlay-ttl" style="color:var(--danger)">⚠️ Delete All Game Data</div>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('delete-all-overlay').style.display='none'">✕</button>
        </div>
        <div style="padding:20px">
            <div style="background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.25);border-radius:10px;padding:14px;margin-bottom:16px;font-size:13px;line-height:1.6">
                <strong style="color:var(--danger)">This will permanently delete:</strong><br>
                • All games<br>• All tracker submissions<br>• All official records<br><br>
                <strong>Rosters will NOT be deleted.</strong><br>This action cannot be undone.
            </div>
            <div class="fg" style="margin-bottom:16px">
                <label class="lbl">Type <strong>DELETE</strong> to confirm</label>
                <input class="inp" id="delete-confirm-input" placeholder="DELETE" oninput="document.getElementById('confirm-delete-btn').disabled=this.value!=='DELETE'">
            </div>
            <div class="btn-row">
                <button class="btn btn-danger" id="confirm-delete-btn" disabled onclick="confirmDeleteAll()">🗑 Delete Everything</button>
                <button class="btn btn-out" onclick="document.getElementById('delete-all-overlay').style.display='none'">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ Export Overlay ══ -->
<div id="export-overlay" class="overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="overlay-box" style="max-width:480px">
        <div class="overlay-hdr">
            <div class="overlay-ttl">📊 Export Stats</div>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('export-overlay').style.display='none'">✕</button>
        </div>
        <div style="padding:20px">
            <div id="export-scope-info" style="font-size:13px;color:var(--muted);margin-bottom:14px;padding:10px 12px;background:var(--bg);border-radius:8px"></div>
            <div id="export-game-picker" style="display:none;margin-bottom:14px">
                <label class="lbl">Select Game</label>
                <select class="inp sel" id="export-game-select">
                    <?php foreach($games as $g): ?>
                    <option value="<?=htmlspecialchars($g['game_key'])?>"><?=htmlspecialchars($g['wave_team'])?> vs <?=htmlspecialchars($g['opponent'])?> — <?=date('M j Y',strtotime($g['game_date']))?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="export-season-picker" style="display:none;margin-bottom:14px">
                <label class="lbl">Select Season</label>
                <select class="inp sel" id="export-season-select">
                    <?php $seasons=array_unique(array_filter(array_column($games,'season'))); foreach($seasons as $s): ?>
                    <option value="<?=htmlspecialchars($s)?>"><?=htmlspecialchars($s)?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:16px;padding:14px;background:rgba(0,48,135,.04);border:1px solid rgba(0,48,135,.12);border-radius:10px">
                <div style="font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Goal Attribution</div>
                <div style="display:flex;gap:8px">
                    <button id="attr-individual" class="btn btn-navy btn-sm" onclick="setGoalAttr('individual')" style="flex:1">👤 By Player</button>
                    <button id="attr-team"       class="btn btn-out  btn-sm" onclick="setGoalAttr('team')"       style="flex:1">🏊 By Team Only</button>
                </div>
                <div id="attr-note" style="display:none;margin-top:10px;font-size:12px;color:var(--muted);font-style:italic;line-height:1.5">*** Teams score goals. Individual goal scorers are not displayed.</div>
            </div>
            <div class="btn-row">
                <button class="btn btn-navy" onclick="doExport('pdf')">📄 Export PDF</button>
                <button class="btn btn-ghost" onclick="doExport('excel')">📊 Export Excel</button>
            </div>
            <div id="export-status" style="margin-top:10px;font-size:13px;color:var(--muted)"></div>
        </div>
    </div>
</div>

<div id="toast"></div>

<!-- ══ Raw Data Export Overlay ══ -->
<div id="raw-export-overlay" class="overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="overlay-box" style="max-width:460px">
        <div class="overlay-hdr">
            <div class="overlay-ttl">📊 Export Raw Data</div>
            <button class="btn btn-ghost btn-sm" onclick="document.getElementById('raw-export-overlay').style.display='none'">✕</button>
        </div>
        <div style="padding:20px">
            <div id="raw-scope-info" style="font-size:13px;color:var(--muted);margin-bottom:14px;padding:10px 12px;background:var(--bg);border-radius:8px;line-height:1.5"></div>

            <div id="raw-game-picker" style="display:none;margin-bottom:14px">
                <label class="lbl">Select Game</label>
                <select class="inp sel" id="raw-game-select">
                    <?php foreach($games as $g): if(!isset($offMap[$g['game_key']])) continue; ?>
                    <option value="<?=htmlspecialchars($g['game_key'])?>"><?=htmlspecialchars($g['wave_team'])?> vs <?=htmlspecialchars($g['opponent'])?> — <?=date('M j Y',strtotime($g['game_date']))?><?=$g['tournament']?' · '.htmlspecialchars($g['tournament']):''?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="raw-tournament-picker" style="display:none;margin-bottom:14px">
                <label class="lbl">Select Tournament</label>
                <select class="inp sel" id="raw-tournament-select">
                    <?php
                    $approvedTournaments = array_unique(array_filter(array_column(array_filter($games, fn($g) => isset($offMap[$g['game_key']])), 'tournament')));
                    sort($approvedTournaments);
                    foreach($approvedTournaments as $tn): ?>
                    <option value="<?=htmlspecialchars($tn)?>"><?=htmlspecialchars($tn)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:16px;padding:12px 14px;background:rgba(0,48,135,.04);border:1px solid rgba(0,48,135,.12);border-radius:10px;font-size:12px;color:var(--muted);line-height:1.6">
                <strong style="color:var(--navy)">Columns included:</strong> Date · Game · Tournament · Age Group · Player · Cap # · Position · Period · Stat · Shot Location
            </div>

            <div class="btn-row">
                <button class="btn btn-navy" onclick="doRawExport()">📥 Download Excel</button>
                <button class="btn btn-out" onclick="document.getElementById('raw-export-overlay').style.display='none'">Cancel</button>
            </div>
            <div id="raw-export-status" style="margin-top:10px;font-size:13px;color:var(--muted)"></div>
        </div>
    </div>
</div>

<script>
const TOKEN = '<?=addslashes($token)?>';
const CLUB_NAME = '<?=addslashes($settings["club_name"] ?? "WAVE")?>';
const TOURNAMENT_NAMES = <?=json_encode(array_column($tournamentNames,'name'))?>;

// Always navigate with a cache-busting timestamp so the browser never shows stale PHP
function goto(url) {
    const sep = url.includes('?') ? '&' : '?';
    location.href = url + sep + '_=' + Date.now();
}
function reload() {
    goto(location.href.split('&_=')[0].split('?_=')[0] + (location.search ? '' : '?t=' + encodeURIComponent(TOKEN)));
}

function toast(msg,dur=3000){const t=document.getElementById('toast');t.textContent=msg;t.style.display='block';clearTimeout(t._t);t._t=setTimeout(()=>t.style.display='none',dur);}

// ── Settings page ──────────────────────────────────────────────────────────────
if (document.getElementById('s-club-name')) {
  function syncColor(id) {
    const picker = document.getElementById('s-' + id);
    const hex    = document.getElementById('s-' + id + '-hex');
    picker.addEventListener('input', () => { hex.value = picker.value; updatePreview(); });
    hex.addEventListener('input', () => {
      const v = hex.value.trim();
      if (/^#[0-9a-fA-F]{6}$/.test(v)) { picker.value = v; updatePreview(); }
    });
  }
  syncColor('primary');
  syncColor('secondary');
  function updatePreview() {
    const pri  = document.getElementById('s-primary').value;
    const sec  = document.getElementById('s-secondary').value;
    const name = document.getElementById('s-club-name').value || 'WAVE';
    const bar  = document.getElementById('s-preview-bar');
    bar.style.background = pri;
    bar.style.borderBottomColor = sec;
    document.getElementById('s-preview-title').textContent = name + ' STATS';
    document.getElementById('s-preview-badge').style.background = sec;
    document.getElementById('s-preview-badge').textContent = name;
    document.getElementById('s-primary-preview').style.background = pri;
    document.getElementById('s-secondary-preview').style.background = sec;
  }
  document.getElementById('s-club-name').addEventListener('input', updatePreview);

}

async function saveSettings() {
  const j = await api({
    action: 'save_settings',
    settings: {
      club_name:       document.getElementById('s-club-name').value.trim(),
      primary_color:   document.getElementById('s-primary').value,
      secondary_color: document.getElementById('s-secondary').value,
    }
  });
  if (j.ok) { toast('✅ Settings saved — reload to apply'); }
  else toast('Error: ' + (j.error || 'unknown'));
}


async function api(body){
    try {
        const r=await fetch('stats-api.php?t='+encodeURIComponent(TOKEN)+'&_='+Date.now(),{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...body,token:TOKEN})});
        const text=await r.text();
        if(!r.ok) return {ok:false,error:'HTTP '+r.status+': '+r.statusText+' — '+text.slice(0,200)};
        try { return JSON.parse(text); }
        catch(e) { return {ok:false,error:'Bad JSON from server: '+text.slice(0,300)}; }
    } catch(err) {
        return {ok:false,error:'Network error: '+err.message};
    }
}

async function deleteAbandonedGame(gameKey, label) {
    if (!confirm('Delete "' + label + '"?\n\nThis game has no submissions and will be permanently removed. This cannot be undone.')) return;
    const j = await api({ action: 'delete_game', game_key: gameKey });
    if (j.ok) { toast('🗑 Game deleted'); setTimeout(() => reload(), 700); }
    else toast('⚠️ ' + j.error);
}

async function quickFinalize(gameKey, subId, trackerName) {
    if (!confirm(`Use ${trackerName}'s stats as the official record for this game?`)) return;
    toast('⏳ Finalizing…');
    const j = await api({ action: 'select_submission', submission_id: subId });
    if (j.ok) { toast('✅ Finalized!'); setTimeout(() => reload(), 700); }
    else toast('⚠️ ' + (j.error || 'Error'));
}

async function avgSubs(key){
    toast('⏳ Averaging…');
    const j=await api({action:'average_submissions',game_key:key});
    if(j.ok){toast('✅ '+j.message);setTimeout(()=>reload(),800);}
    else{alert('Error: '+(j.error||'Unknown error. Please check the page and try again.'));toast('⚠️ Failed');}
}

// ── Delete All ────────────────────────────────────────────────────────────────
function showDeleteAll(){
    document.getElementById('delete-confirm-input').value='';
    document.getElementById('confirm-delete-btn').disabled=true;
    document.getElementById('delete-all-overlay').style.display='flex';
}
async function confirmDeleteAll(){
    const j=await api({action:'delete_all_games'});
    if(j.ok){toast('✅ All game data deleted');document.getElementById('delete-all-overlay').style.display='none';setTimeout(()=>reload(),800);}
    else toast('⚠️ '+j.error);
}

// ── Export ────────────────────────────────────────────────────────────────────
let _exportScope = 'all';
let _goalAttr    = 'individual'; // 'individual' | 'team'

function setGoalAttr(mode) {
    _goalAttr = mode;
    document.getElementById('attr-individual').className = 'btn btn-sm '+(mode==='individual'?'btn-navy':'btn-out');
    document.getElementById('attr-team').className       = 'btn btn-sm '+(mode==='team'      ?'btn-navy':'btn-out');
    document.getElementById('attr-note').style.display   = mode==='team' ? '' : 'none';
}

function showExport(scope){
    _exportScope=scope;
    _goalAttr='individual';
    setGoalAttr('individual'); // reset toggle
    document.getElementById('export-game-picker').style.display   = scope==='game'  ?'':'none';
    document.getElementById('export-season-picker').style.display = scope==='season'?'':'none';
    document.getElementById('export-scope-info').textContent =
        scope==='game'  ? 'Export stats for a single game.' :
        scope==='season'? 'Export stats for all games in a season.' :
                          'Export all stats across all games and seasons.';
    document.getElementById('export-status').textContent='';
    document.getElementById('export-overlay').style.display='flex';
}

async function doExport(format){
    const statusEl = document.getElementById('export-status');
    statusEl.textContent='⏳ Loading data…';
    const body={action:'export_stats',scope:_exportScope};
    if(_exportScope==='game')   body.game_key=document.getElementById('export-game-select')?.value||'';
    if(_exportScope==='season') body.season  =document.getElementById('export-season-select')?.value||'';
    const j=await api(body);
    if(!j.ok){statusEl.textContent='⚠️ '+j.error;return;}
    if(!j.data.length){statusEl.textContent='No data found for this selection.';return;}
    statusEl.textContent='⏳ Building '+format.toUpperCase()+'…';
    const teamGoals = _goalAttr === 'team';
    if(format==='pdf')   await exportPdf(j.data,_exportScope,teamGoals);
    if(format==='excel') await exportExcel(j.data,_exportScope,teamGoals);
    statusEl.textContent='✅ Done!';
}

// ── Raw Data Export ────────────────────────────────────────────────────────────
let _rawScope = 'all';

function showRawExport(scope) {
    _rawScope = scope;
    const gamePicker  = document.getElementById('raw-game-picker');
    const tourPicker  = document.getElementById('raw-tournament-picker');
    const info        = document.getElementById('raw-scope-info');
    gamePicker.style.display  = scope === 'game'       ? '' : 'none';
    tourPicker.style.display  = scope === 'tournament' ? '' : 'none';
    info.innerHTML =
        scope === 'game'       ? 'Export every stat event from a single approved game.' :
        scope === 'tournament' ? 'Export every stat event across all approved games in a tournament.' :
                                 'Export every stat event across <strong>all approved games</strong>.';
    document.getElementById('raw-export-status').textContent = '';
    document.getElementById('raw-export-overlay').style.display = 'flex';
}

async function doRawExport() {
    const statusEl = document.getElementById('raw-export-status');
    statusEl.textContent = '⏳ Loading data…';
    const body = { action: 'export_stats', scope: _rawScope };
    if (_rawScope === 'game')       body.game_key   = document.getElementById('raw-game-select')?.value || '';
    if (_rawScope === 'tournament') body.tournament = document.getElementById('raw-tournament-select')?.value || '';
    const j = await api(body);
    if (!j.ok) { statusEl.textContent = '⚠️ ' + j.error; return; }
    if (!j.data.length) { statusEl.textContent = 'No approved games found for this selection.'; return; }
    statusEl.textContent = '⏳ Building Excel…';
    await exportRawExcel(j.data, _rawScope);
    statusEl.textContent = '✅ Done!';
}

async function exportRawExcel(data, scope) {
    if (!window.XLSX) await loadScript('https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js');

    const STAT_LABELS = {
        goal:'Goal', assist:'Assist', shot:'Shot', steal:'Steal', block:'Block',
        turnover:'Turnover', kickout:'Kickout', kickout_earned:'Kickout Earned',
        save:'Save', goals_against:'Goal Against', penalty_shot_scored:'Penalty Shot (Scored)',
        penalty_shot_missed:'Penalty Shot (Missed)'
    };

    const wb = XLSX.utils.book_new();

    // ── Sheet 1: Events (one row per stat event) ──
    const evtRows = [['Date','Game','Tournament','Age Group','Period','Stat','Player','Cap #','Position','Shot Location']];
    for (const { game, players, official } of data) {
        const sub = official || (data.find(d => d.game.game_key === game.game_key)?.submissions?.[0]);
        if (!sub) continue;
        const playerMap = {};
        (players || []).forEach(p => { playerMap[p.id] = p; });
        for (const evt of (sub.events || [])) {
            const p   = playerMap[evt.playerId] || {};
            const pos = p.isGoalie ? 'Goalie' : 'Field';
            evtRows.push([
                game.game_date?.slice(0,10) || '',
                game.wave_team + ' vs ' + game.opponent,
                game.tournament || '',
                game.age_group  || '',
                evt.period      || '',
                STAT_LABELS[evt.stat] || evt.stat || '',
                p.name          || '',
                p.number        || '',
                pos,
                evt.shotLocation || '',
            ]);
        }
    }
    const wsEvt = XLSX.utils.aoa_to_sheet(evtRows);
    wsEvt['!cols'] = [{wch:12},{wch:32},{wch:22},{wch:10},{wch:8},{wch:20},{wch:22},{wch:6},{wch:8},{wch:14}];
    XLSX.utils.book_append_sheet(wb, wsEvt, 'Events');

    // ── Sheet 2: Player Totals (one row per player per game) ──
    const STAT_KEYS = ['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save','goals_against'];
    const totRows = [['Date','Game','Tournament','Age Group','Player','Cap #','Position',...STAT_KEYS.map(s => STAT_LABELS[s] || s)]];
    for (const { game, players, official } of data) {
        const sub = official || (data.find(d => d.game.game_key === game.game_key)?.submissions?.[0]);
        if (!sub) continue;
        const evts = sub.events || [];
        for (const p of (players || [])) {
            const counts = STAT_KEYS.map(s => evts.filter(e => e.playerId === p.id && e.stat === s).length);
            if (counts.every(c => c === 0)) continue; // skip players with no stats
            totRows.push([
                game.game_date?.slice(0,10) || '',
                game.wave_team + ' vs ' + game.opponent,
                game.tournament || '',
                game.age_group  || '',
                p.name    || '',
                p.number  || '',
                p.isGoalie ? 'Goalie' : 'Field',
                ...counts,
            ]);
        }
    }
    const wsTot = XLSX.utils.aoa_to_sheet(totRows);
    wsTot['!cols'] = [{wch:12},{wch:32},{wch:22},{wch:10},{wch:22},{wch:6},{wch:8},...STAT_KEYS.map(()=>({wch:14}))];
    XLSX.utils.book_append_sheet(wb, wsTot, 'Player Totals');

    // ── Sheet 3: Game Summary ──
    const sumRows = [['Date','Game','Tournament','Age Group',CLUB_NAME+' Score','Opponent Score','Official Method']];
    for (const { game, official } of data) {
        const sub = official;
        sumRows.push([
            game.game_date?.slice(0,10) || '',
            game.wave_team + ' vs ' + game.opponent,
            game.tournament   || '',
            game.age_group    || '',
            sub?.wave_score   ?? game.off_ws ?? '',
            sub?.opp_score    ?? game.off_os ?? '',
            game.off_method   || '',
        ]);
    }
    const wsSum = XLSX.utils.aoa_to_sheet(sumRows);
    wsSum['!cols'] = [{wch:12},{wch:32},{wch:22},{wch:10},{wch:14},{wch:14},{wch:20}];
    XLSX.utils.book_append_sheet(wb, wsSum, 'Game Summary');

    const label = scope === 'game' ? 'Game' : scope === 'tournament' ? 'Tournament' : 'All';
    XLSX.writeFile(wb, CLUB_NAME + '_RawStats_' + label + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

const GOAL_DISCLAIMER = '*** Teams score goals. Individual goal scorers are not displayed.';
const STAT_COLS=['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save','goals_against'];
const STAT_HDR =['G','A','SH','ST','BL','TO','KO','KOE','SV','GA'];

function gatherRows(data, teamGoals){
    const rows=[];
    for(const {game,players,submissions} of data){
        const sub = submissions[0];
        if(!sub) continue;
        const evts=sub.events||[];
        for(const p of (players||[])){
            const row={
                date: game.game_date?.slice(0,10)||'',
                game: game.wave_team+' vs '+game.opponent,
                tournament: game.tournament||'',
                player: p.name||('#'+p.number),
                cap: p.number,
            };
            STAT_COLS.forEach(s=>{
                const count = evts.filter(e=>e.playerId===p.id&&e.stat===s).length;
                // Zero out goals at individual level when team-only mode
                row[s] = (teamGoals && (s==='goal'||s==='goals_against')) ? 0 : count;
            });
            rows.push(row);
        }
    }
    return rows;
}

async function exportExcel(data, scope, teamGoals){
    if(!window.XLSX) await loadScript('https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js');
    const rows = gatherRows(data, teamGoals);

    // Build stat columns — exclude G and GA if team-only
    const exportCols = teamGoals ? STAT_COLS.filter(s=>s!=='goal'&&s!=='goals_against') : STAT_COLS;
    const exportHdr  = exportCols.map(s=>STAT_HDR[STAT_COLS.indexOf(s)]);

    const wb = XLSX.utils.book_new();

    // Main stats sheet
    const header = ['Date','Game','Tournament','Cap #','Player',...exportHdr];
    const sheet  = [header, ...rows.map(r=>[r.date,r.game,r.tournament,r.cap,r.player,...exportCols.map(s=>r[s]||0)])];
    if(teamGoals) sheet.push([],[GOAL_DISCLAIMER]);
    const ws = XLSX.utils.aoa_to_sheet(sheet);
    ws['!cols'] = [{wch:12},{wch:30},{wch:18},{wch:6},{wch:22},...exportHdr.map(()=>({wch:5}))];
    XLSX.utils.book_append_sheet(wb, ws, 'Stats');

    // Team goals summary sheet (when team-only)
    if(teamGoals){
        const teamSheet = [['Date','Game','Tournament',CLUB_NAME+' Goals','Opponent Goals']];
        for(const {game,submissions} of data){
            const sub=submissions[0]; if(!sub) continue;
            teamSheet.push([game.game_date?.slice(0,10)||'',game.wave_team+' vs '+game.opponent,game.tournament||'',sub.wave_score||0,sub.opp_score||0]);
        }
        const ts = XLSX.utils.aoa_to_sheet(teamSheet);
        ts['!cols']=[{wch:12},{wch:30},{wch:18},{wch:14},{wch:14}];
        XLSX.utils.book_append_sheet(wb, ts, 'Team Goals');
    }

    XLSX.writeFile(wb, 'WAVE_Stats_'+scope+'_'+new Date().toISOString().slice(0,10)+'.xlsx');
}

async function exportPdf(data, scope, teamGoals){
    if(!window.jspdf) await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
    if(!window.jspdf?.jsPDF?.prototype?.autoTable) await loadScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js');
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
    const NAVY=[0,48,135], GOLD=[255,199,44], WHITE=[255,255,255], LIGHT=[240,242,247],
          NAVY2=[30,65,160], MUTED=[120,130,150], RED=[220,38,38], GREEN=[22,101,52], AMBER=[146,101,10];

    const PW=297, PH=210, ML=10, MR=10, CW=PW-ML-MR;

    // ── Helpers ──────────────────────────────────────────────────────────────────
    function pct(n,d){ return d ? Math.round(n/d*100)+'%' : '—'; }
    function shotZoneLabel(x, y) {
        // x,y are 0–100 in canonical frame (WAVE attacks right)
        // Zones based on pool geometry
        const rx = x / 100;  // 0=left end, 1=right end
        const ry = y / 100;  // 0=top lane, 1=bottom lane
        // Right half = attacking end
        if (rx < 0.45) return 'Defensive Half';
        if (rx > 0.82) {
            if (ry < 0.35) return '2m Wing (Top)';
            if (ry > 0.65) return '2m Wing (Bot)';
            return '2m Front';
        }
        if (rx > 0.65) {
            if (ry < 0.3)  return 'Right Wing';
            if (ry > 0.7)  return 'Left Wing';
            return 'Centre';
        }
        return 'Point';
    }

    // ── Page header ──────────────────────────────────────────────────────────────
    function drawPageHeader(pageTitle) {
        doc.setFillColor(...NAVY); doc.rect(0,0,PW,22,'F');
        doc.setFillColor(...GOLD); doc.rect(0,22,PW,2,'F');
        doc.setTextColor(...WHITE);
        doc.setFont('helvetica','bold'); doc.setFontSize(18);
        doc.text(CLUB_NAME, ML, 14);
        doc.setFontSize(9); doc.setFont('helvetica','normal');
        doc.text(pageTitle + ' · ' + new Date().toLocaleDateString('en-CA',{year:'numeric',month:'short',day:'numeric'}), ML, 20);
        doc.text(new Date().toLocaleTimeString('en-CA',{hour:'2-digit',minute:'2-digit'}), PW-MR, 20, {align:'right'});
    }

    // ── Footer on every page ─────────────────────────────────────────────────────
    function drawFooters(teamGoals) {
        const pages = doc.internal.getNumberOfPages();
        for(let i=1;i<=pages;i++){
            doc.setPage(i);
            doc.setFillColor(...NAVY); doc.rect(0,203,PW,7,'F');
            doc.setTextColor(...GOLD); doc.setFont('helvetica','bold'); doc.setFontSize(7);
            doc.text(CLUB_NAME+' · Confidential', ML, 208);
            if(teamGoals){
                doc.setTextColor(255,243,180); doc.setFont('helvetica','italic');
                doc.text(GOAL_DISCLAIMER, PW/2, 208, {align:'center'});
            }
            doc.setTextColor(...WHITE); doc.setFont('helvetica','normal');
            doc.text('Page '+i+' of '+pages, PW-MR, 208, {align:'right'});
        }
    }

    const scopeLabel = scope==='game' ? 'Single Game Report' : scope==='season' ? 'Season Report' : scope==='tournament' ? 'Tournament Report' : 'All-Time Report';
    let firstPage = true;
    let y = 28;

    for(const {game, players, submissions} of data){
        const sub = submissions[0]; if(!sub) continue;
        const evts = sub.events || [];
        const waveScore = game.off_ws ?? sub.wave_score ?? 0;
        const oppScore  = game.off_os ?? sub.opp_score  ?? 0;

        // Field players and goalies
        const fieldPlayers  = (players||[]).filter(p => !p.isGoalie);
        const goalies        = (players||[]).filter(p =>  p.isGoalie);

        // Shot events
        const forShots      = evts.filter(e => e.stat==='goal' || e.stat==='shot');
        const againstShots  = evts.filter(e => e.stat==='goals_against' || e.stat==='shot_against');
        const forGoals      = evts.filter(e => e.stat==='goal').length;
        const againstGoals  = evts.filter(e => e.stat==='goals_against').length;

        // Man-up / man-down
        const muShots  = forShots.filter(e=>e.situation==='man_up').length;
        const muGoals  = forShots.filter(e=>e.situation==='man_up'&&e.stat==='goal').length;
        const mdShots  = forShots.filter(e=>e.situation==='man_down').length;
        const mdGoals  = forShots.filter(e=>e.situation==='man_down'&&e.stat==='goal').length;
        const evShots  = forShots.filter(e=>e.situation==='even'||!e.situation).length;
        const evGoals  = forShots.filter(e=>(e.situation==='even'||!e.situation)&&e.stat==='goal').length;

        // New page if needed (or first game)
        if(!firstPage){ doc.addPage(); y=28; }
        firstPage = false;
        drawPageHeader(scopeLabel);

        // ── Game title bar ───────────────────────────────────────────────────────
        doc.setFillColor(...LIGHT); doc.roundedRect(ML,y,CW,10,2,2,'F');
        doc.setTextColor(...NAVY); doc.setFont('helvetica','bold'); doc.setFontSize(12);
        doc.text(game.wave_team+' vs '+game.opponent, ML+3, y+7);
        // Score badge
        doc.setFillColor(...NAVY); doc.roundedRect(PW-MR-36,y,36,10,2,2,'F');
        doc.setTextColor(...GOLD); doc.setFont('helvetica','bold'); doc.setFontSize(11);
        doc.text(waveScore+' – '+oppScore, PW-MR-18, y+7, {align:'center'});
        y+=13;
        // Meta line
        doc.setTextColor(...MUTED); doc.setFont('helvetica','normal'); doc.setFontSize(8);
        const meta=[game.game_date?.slice(0,10), game.tournament, game.age_group].filter(Boolean).join(' · ');
        doc.text(meta, ML+3, y); y+=7;

        // ── SECTION 1: Player Stats ──────────────────────────────────────────────
        // Column order: A first, then G, then SH, ST, BL, TO, KO, KOE (no SV/GA for field players)
        const fieldCols = teamGoals
            ? ['assist','shot','steal','block','turnover','kickout','kickout_earned']
            : ['assist','goal','shot','steal','block','turnover','kickout','kickout_earned'];
        const fieldHdrs = { assist:'A', goal:'G', shot:'SH', steal:'ST', block:'BL', turnover:'TO', kickout:'KO', kickout_earned:'KOE' };
        const activeCols = fieldCols.filter(s => evts.some(e => e.stat===s && fieldPlayers.some(p=>p.id===e.playerId)));

        if(activeCols.length && fieldPlayers.length){
            doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(...NAVY);
            doc.text('FIELD PLAYERS', ML, y); y+=3;

            const head=[['#','Player',...activeCols.map(s=>fieldHdrs[s])]];
            const body = fieldPlayers.map(p=>{
                const vals = activeCols.map(s=>evts.filter(e=>e.playerId===p.id&&e.stat===s).length||'');
                return [p.number, p.name||'—', ...vals];
            }).filter(row=>row.slice(2).some(v=>v!==''));

            if(body.length){
                doc.autoTable({
                    startY:y, head, body,
                    margin:{left:ML,right:MR},
                    styles:{fontSize:8,cellPadding:2},
                    headStyles:{fillColor:NAVY,textColor:WHITE,fontStyle:'bold',halign:'center'},
                    columnStyles:{0:{halign:'center',cellWidth:10},1:{cellWidth:50},...Object.fromEntries(activeCols.map((_,i)=>[i+2,{halign:'center',cellWidth:13}]))},
                    alternateRowStyles:{fillColor:LIGHT},
                });
                y = doc.lastAutoTable.finalY+5;
            }
        }

        // ── SECTION 2: Goalie Stats ──────────────────────────────────────────────
        if(goalies.length){
            doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(...NAVY);
            doc.text('GOALIES', ML, y); y+=3;

            const gHead=[['#','Goalie','Shots Faced','Saves','Save %','Goals Against','Steals']];
            const gBody = goalies.map(g=>{
                const shotsFaced = evts.filter(e=>(e.stat==='goals_against'||e.stat==='shot_against') && e.playerId===g.id).length
                                 + evts.filter(e=>(e.stat==='goals_against'||e.stat==='shot_against') && !e.playerId).length / Math.max(goalies.length,1);
                // Use saves attributed to goalie (save stat) + infer from shots faced
                const saves       = evts.filter(e=>e.stat==='save'&&e.playerId===g.id).length;
                const goalsAg     = evts.filter(e=>e.stat==='goals_against'&&e.playerId===g.id).length;
                const steals      = evts.filter(e=>e.stat==='steal'&&e.playerId===g.id).length;
                const sfInt       = Math.round(shotsFaced);
                return [g.number, g.name||'—', sfInt, saves, pct(saves, sfInt), goalsAg, steals];
            });

            doc.autoTable({
                startY:y, head:gHead, body:gBody,
                margin:{left:ML,right:MR},
                styles:{fontSize:8,cellPadding:2},
                headStyles:{fillColor:[40,80,140],textColor:WHITE,fontStyle:'bold',halign:'center'},
                columnStyles:{0:{halign:'center',cellWidth:10},1:{cellWidth:50},2:{halign:'center',cellWidth:24},3:{halign:'center',cellWidth:16},4:{halign:'center',cellWidth:16},5:{halign:'center',cellWidth:26},6:{halign:'center',cellWidth:16}},
                alternateRowStyles:{fillColor:LIGHT},
            });
            y = doc.lastAutoTable.finalY+5;
        }

        if(y > 170){ doc.addPage(); y=28; drawPageHeader(scopeLabel); }

        // ── SECTION 3: Situation Breakdown ───────────────────────────────────────
        const hasSitData = muShots || mdShots;
        const leftColX = ML, rightColX = ML + CW/2 + 3;
        const colW = CW/2 - 3;

        // Left: Shooting summary
        doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(...NAVY);
        doc.text('SHOOTING SUMMARY', leftColX, y); 

        const shootBody = [
            ['Shots For',   forShots.length,    forGoals,    pct(forGoals,forShots.length)],
            ['Shots Against',againstShots.length,againstGoals,pct(againstGoals,againstShots.length)],
        ];
        doc.autoTable({
            startY:y+3, head:[['','Shots','Goals','Conv%']], body:shootBody,
            margin:{left:leftColX,right:rightColX+colW-MR+6},
            tableWidth: colW,
            styles:{fontSize:8,cellPadding:2},
            headStyles:{fillColor:NAVY,textColor:WHITE,fontStyle:'bold',halign:'center'},
            columnStyles:{0:{cellWidth:32},1:{halign:'center'},2:{halign:'center'},3:{halign:'center'}},
            alternateRowStyles:{fillColor:LIGHT},
        });
        const leftBot = doc.lastAutoTable.finalY;

        // Right: Situation breakdown (Man-Up / Even / Man-Down)
        if(hasSitData){
            doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(...NAVY);
            doc.text('EXTRA MAN / MAN DOWN', rightColX, y);
            const sitBody=[
                ['Man-Up (PP)',   muShots, muGoals, pct(muGoals,muShots)],
                ['Even Strength', evShots, evGoals, pct(evGoals,evShots)],
                ['Man-Down (PK)', mdShots, mdGoals, pct(mdGoals,mdShots)],
            ].filter(r=>r[1]>0);
            doc.autoTable({
                startY:y+3, head:[['Situation','Shots','Goals','Conv%']], body:sitBody,
                margin:{left:rightColX,right:MR},
                tableWidth: colW,
                styles:{fontSize:8,cellPadding:2},
                headStyles:{fillColor:[40,100,60],textColor:WHITE,fontStyle:'bold',halign:'center'},
                columnStyles:{0:{cellWidth:34},1:{halign:'center'},2:{halign:'center'},3:{halign:'center'}},
                alternateRowStyles:{fillColor:LIGHT},
            });
        }
        y = Math.max(leftBot, doc.lastAutoTable?.finalY||0) + 5;

        // ── SECTION 4: Shot Location Map ─────────────────────────────────────────
        const shotEvts = evts.filter(e=>(e.stat==='goal'||e.stat==='shot')&&e.shotLocation);
        const agEvts   = evts.filter(e=>(e.stat==='goals_against'||e.stat==='shot_against')&&e.shotLocation);

        if(shotEvts.length || agEvts.length){
            if(y > 155){ doc.addPage(); y=28; drawPageHeader(scopeLabel); }
            doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(...NAVY);
            doc.text('SHOT LOCATIONS', ML, y); y+=4;

            // Draw pool outlines (landscape A4, two pools side by side)
            const pools = [
                { label:'WAVE Shots', evts:shotEvts, x:ML,           w:(CW/2)-4, isFor:true  },
                { label:'Shots Against', evts:agEvts, x:ML+CW/2+1,  w:(CW/2)-4, isFor:false },
            ];
            const poolH = 38;

            for(const pool of pools){
                const {x:px, w:pw, evts:pevts, label, isFor} = pool;
                // Pool background
                doc.setFillColor(220,235,255); doc.roundedRect(px,y,pw,poolH,2,2,'F');
                doc.setDrawColor(...NAVY2); doc.setLineWidth(0.4);
                doc.roundedRect(px,y,pw,poolH,2,2,'S');
                // Goal boxes
                doc.setFillColor(255,255,255);
                doc.rect(px,       y+poolH*0.3, 4, poolH*0.4,'F');
                doc.rect(px+pw-4,  y+poolH*0.3, 4, poolH*0.4,'F');
                doc.setDrawColor(...NAVY2); doc.setLineWidth(0.3);
                doc.rect(px,       y+poolH*0.3, 4, poolH*0.4,'S');
                doc.rect(px+pw-4,  y+poolH*0.3, 4, poolH*0.4,'S');
                // Half-line
                doc.setDrawColor(150,170,210); doc.setLineWidth(0.3);
                doc.line(px+pw/2, y+2, px+pw/2, y+poolH-2);
                // 2m and 5m arcs (simplified as lines)
                doc.setDrawColor(200,80,80); doc.setLineWidth(0.25);
                doc.line(px+pw*0.8,y+2,px+pw*0.8,y+poolH-2);
                doc.setDrawColor(255,160,0);
                doc.line(px+pw*0.65,y+2,px+pw*0.65,y+poolH-2);
                // Label
                doc.setFont('helvetica','bold'); doc.setFontSize(7);
                doc.setTextColor(...(isFor ? GREEN : RED));
                doc.text(label+' ('+pevts.length+')', px+pw/2, y+poolH+4, {align:'center'});
                // Plot shots
                for(const e of pevts){
                    const loc = e.shotLocation;
                    if(!loc||loc.x==null) continue;
                    // loc.x is in canonical frame: 100=right=attacking end for WAVE
                    // For "against" shots, flip: opp attacks left
                    const rx = isFor ? loc.x/100 : 1 - loc.x/100;
                    const ry = loc.y/100;
                    const sx = px + 4 + rx*(pw-8);
                    const sy = y  + 2 + ry*(poolH-4);
                    const isGoal = e.stat==='goal'||e.stat==='goals_against';
                    doc.setFillColor(...(isGoal ? (isFor?[34,197,94]:[220,38,38]) : [255,165,0]));
                    doc.circle(sx, sy, 1.2, 'F');
                }
            }
            y += poolH + 10;

            // Zone tally table
            const zoneCount = {};
            shotEvts.forEach(e=>{ if(e.shotLocation){ const z=shotZoneLabel(e.shotLocation.x,e.shotLocation.y); zoneCount[z]=(zoneCount[z]||{shots:0,goals:0}); zoneCount[z].shots++; if(e.stat==='goal')zoneCount[z].goals++; } });
            const zoneRows = Object.entries(zoneCount).map(([z,v])=>[z,v.shots,v.goals,pct(v.goals,v.shots)]).sort((a,b)=>b[1]-a[1]);
            if(zoneRows.length){
                doc.autoTable({
                    startY:y, head:[['Zone','Shots','Goals','Conv%']], body:zoneRows,
                    margin:{left:ML,right:ML+CW/2+3},
                    tableWidth:CW/2-3,
                    styles:{fontSize:7.5,cellPadding:2},
                    headStyles:{fillColor:NAVY2,textColor:WHITE,fontStyle:'bold',halign:'center'},
                    columnStyles:{0:{cellWidth:36},1:{halign:'center'},2:{halign:'center'},3:{halign:'center'}},
                    alternateRowStyles:{fillColor:LIGHT},
                });
                y = doc.lastAutoTable.finalY+5;
            }
        }

        // teamGoals disclaimer
        if(teamGoals){
            doc.setFillColor(255,243,205); doc.roundedRect(ML,y,CW,7,1,1,'F');
            doc.setTextColor(...AMBER); doc.setFont('helvetica','italic'); doc.setFontSize(7);
            doc.text(GOAL_DISCLAIMER, PW/2, y+4.5, {align:'center'});
            y+=10;
        }
    }

    drawFooters(teamGoals);
    doc.save(CLUB_NAME+'_Stats_'+scope+'_'+new Date().toISOString().slice(0,10)+'.pdf');
}

function loadScript(src){
    return new Promise((res,rej)=>{
        const s=document.createElement('script');s.src=src;
        s.onload=res;s.onerror=rej;document.head.appendChild(s);
    });
}

async function mergeGame(sourceKey, targetKey, sourceName) {
    if (!confirm('Merge "'+sourceName+'" into this game?\n\nAll submissions from that game will move here and that game record will be deleted. This cannot be undone.')) return;
    const j = await api({ action: 'merge_games', source_key: sourceKey, target_key: targetKey });
    if (j.ok) { toast('✅ Games merged'); setTimeout(() => reload(), 800); }
    else toast('⚠️ ' + j.error);
}

// ── PDF Parser ────────────────────────────────────────────────────────────────
// Parses a PDF and calls callback with array of { ageGroup, teamName, players[] }
// Handles multiple teams per PDF by detecting "14U Team Name" style headers

let _pickerTeams    = [];
let _pickerCallback = null; // null = roster import mode, fn = re-import single-select mode

function showTeamPicker(teams, cb) {
    _pickerTeams    = teams.map(t => ({...t})); // shallow clone so edits don't mutate original
    _pickerCallback = cb;
    const list = document.getElementById('team-picker-list');

    if (cb) {
        // Re-import mode: single-click select
        list.innerHTML = _pickerTeams.map((t,i) => `
            <div style="padding:12px 14px;border:1px solid var(--bdr);border-radius:10px;margin-bottom:8px;cursor:pointer;transition:background .12s"
                 onmouseover="this.style.background='#f5f7fc'" onmouseout="this.style.background=''"
                 onclick="pickTeamSingle(${i})">
                <div style="font-weight:700;font-size:15px">${t.ageGroup ? t.ageGroup+' · ' : ''}${t.teamName}</div>
                <div style="font-size:12px;color:var(--muted);margin-top:2px">${t.players.length} players</div>
            </div>`).join('');
    } else {
        // Roster import mode: checkboxes + inline rename
        list.innerHTML = _pickerTeams.map((t,i) => `
            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1.5px solid var(--bdr);border-radius:10px;margin-bottom:8px;background:var(--sur);transition:border-color .12s"
                 id="pick-row-${i}">
                <input type="checkbox" id="pick-${i}" checked style="width:18px;height:18px;flex-shrink:0;cursor:pointer;accent-color:var(--navy)"
                       onchange="document.getElementById('pick-row-${i}').style.borderColor=this.checked?'var(--bdr)':'#e5e7eb';document.getElementById('pick-name-${i}').style.opacity=this.checked?1:0.4">
                <div style="flex:1;min-width:0">
                    <input id="pick-name-${i}" type="text" value="${t.teamName.replace(/"/g,'&quot;')}"
                           style="width:100%;font-weight:700;font-size:14px;border:none;border-bottom:1.5px solid transparent;background:transparent;color:var(--txt);padding:2px 0;outline:none;transition:border-color .15s"
                           onfocus="this.style.borderBottomColor='var(--navy)'"
                           onblur="this.style.borderBottomColor='transparent';_pickerTeams[${i}].teamName=this.value.trim()||_pickerTeams[${i}].teamName"
                           oninput="_pickerTeams[${i}].teamName=this.value"
                           placeholder="Team name">
                    <div style="font-size:11px;color:var(--muted);margin-top:1px">${t.ageGroup ? t.ageGroup+' · ' : ''}${t.players.length} players — click name to rename</div>
                </div>
            </div>`).join('');
    }
    document.getElementById('team-picker-overlay').style.display = 'flex';
}

function pickerSelectAll(val) {
    _pickerTeams.forEach((_,i) => { const cb=document.getElementById('pick-'+i); if(cb) cb.checked=val; });
}

function pickTeamSingle(idx) {
    document.getElementById('team-picker-overlay').style.display = 'none';
    if (_pickerCallback) _pickerCallback(_pickerTeams[idx]);
}

async function importSelectedTeams() {
    const selected = _pickerTeams.filter((_,i) => { const cb=document.getElementById('pick-'+i); return cb&&cb.checked; });
    if (!selected.length) { toast('⚠️ No teams selected'); return; }

    // Warn on duplicate names
    const names = selected.map(t => t.teamName.trim().toLowerCase());
    const dupes = names.filter((n,i) => names.indexOf(n) !== i);
    if (dupes.length) {
        toast('⚠️ Two teams have the same name — please rename before importing.');
        return;
    }

    document.getElementById('team-picker-overlay').style.display = 'none';
    const tournament = _pickerTournament || '';
    const j = await api({ action:'save_rosters_bulk', teams: selected.map(t=>({name:t.teamName.trim(),age_group:t.ageGroup,tournament,players:t.players})) });
    if (j.ok) {
        toast('✅ Imported: ' + j.saved.map(s=>s.name+' ('+s.count+')').join(', '));
        setTimeout(() => goto('?t=<?=$T?>&view=rosters'), 900);
    } else toast('⚠️ ' + j.error);
}

// ── Group raw items into lines by Y proximity ──────────────────────────────
function pdfGroupLines(items, yTolerance = 4) {
    const sorted = [...items].sort((a, b) => Math.abs(a.y - b.y) <= yTolerance ? a.x - b.x : a.y - b.y);
    const lines = [];
    let cur = [], curY = null;
    for (const it of sorted) {
        if (curY === null || Math.abs(it.y - curY) <= yTolerance) { cur.push(it); curY = it.y; }
        else { if (cur.length) lines.push(cur); cur = [it]; curY = it.y; }
    }
    if (cur.length) lines.push(cur);
    return lines.map(l => ({
        text:     l.map(i => i.text).join(' ').trim(),
        y:        l[0].y,
        fontSize: Math.max(...l.map(i => i.fontSize || 8)),
        raw:      l,
    }));
}

// ── Parse one column's sorted lines into teams ─────────────────────────────
// Tuned to WAVE roster PDF format:
//   Header: "18U WAVE Girls" / "14U WAVE Piranhas" (age group + team name)
//   Player: "1 Adelle Hircock" / "8 Jorge Gabriel Davila Sanchez"
//   Special names: von Finckenstein, Prud'homme, Yérim Fall, O'Farrell, etc.
function pdfParseColumn(lines) {
    // Age-group header: starts with e.g. "14U " or "18U "
    const headerRe = /^(\d{1,2}U)\s+(.{2,60})$/i;
    // Player: 1-2 digit number, space, then name starting with capital (incl. accented)
    // Name parts can include: letters, accents (full unicode), hyphens, apostrophes, spaces
    // Allows lowercase connectors: de, von, van, bin, etc.
    const playerRe = /^(\d{1,2})\s+((?:[A-ZÁÀÂÄÉÈÊËÍÎÏÓÔÖÙÛÜÝÇÑŠŽŒÆ\u00C0-\u024F][A-Za-zÀ-öø-ÿŠŽœæ\u00C0-\u024F'\u2019-]*(?:\s+(?:de|von|van|bin|el|al|d'))?(?:\s+[A-Za-zÀ-öø-ÿ\u00C0-\u024F'\u2019-]+)*))$/;

    // Lines to skip entirely
    const skipRe = /^(shadow|toronto|pan am|feb\b|mar\b|scarborough|final roster|names in alpha|let.s go|wave swim|swim and polo|updated on|\(names|\))/i;

    const teams = [];
    let cur = null;

    for (const line of lines) {
        const t = line.text;
        if (!t || t.length < 2) continue;
        if (skipRe.test(t)) continue;

        const hm = t.match(headerRe);
        if (hm) {
            cur = { ageGroup: hm[1].toUpperCase(), teamName: hm[2].trim(), players: [] };
            teams.push(cur);
            continue;
        }

        if (cur) {
            const pm = t.match(playerRe);
            if (pm) {
                cur.players.push({
                    id:       'p_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7),
                    number:   pm[1],
                    name:     pm[2].trim(),
                    isGoalie: false,
                });
            }
        }
    }

    return teams.filter(t => t.players.length > 0);
}

async function parsePdfToTeams(input, callback) {
    const file = input.files[0]; if (!file) return;
    input.value = ''; // clear after capturing reference — file object remains valid
    toast('⏳ Parsing PDF…', 15000);

    try {
        await loadPdfJs();
    } catch(e) {
        toast('⚠️ Could not load PDF reader. Check your internet connection.');
        console.error('[PDF] loadPdfJs failed:', e);
        return;
    }

    let buf;
    try {
        buf = await file.arrayBuffer();
    } catch(e) {
        toast('⚠️ Could not read file.');
        console.error('[PDF] arrayBuffer failed:', e);
        return;
    }

    let pdf;
    try {
        pdf = await pdfjsLib.getDocument({ data: buf }).promise;
    } catch(e) {
        toast('⚠️ Could not parse PDF — is it a valid PDF file?');
        console.error('[PDF] getDocument failed:', e);
        return;
    }

    let tournamentName = '';
    const allTeams = [];

    try {
    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
        const page    = await pdf.getPage(pageNum);
        const vp      = page.getViewport({ scale: 1 });
        const content = await page.getTextContent();

        // Collect items, flip Y so top of page = small Y
        const items = content.items
            .filter(it => it.str.trim())
            .map(it => ({
                text:     it.str.trim(),
                x:        it.transform[4],
                y:        vp.height - it.transform[5],
                fontSize: Math.abs(it.transform[3]),
                width:    Math.abs(it.width) || 1,
            }));

        if (!items.length) continue;

        // Grab tournament name from top quarter of page
        if (!tournamentName) {
            const topItems = items.filter(it => it.y < vp.height * 0.28).sort((a,b) => a.y - b.y);
            for (const it of topItems) {
                if (/invitational|future hopes|championship|tournament|cup|classic/i.test(it.text) && it.text.length < 100) {
                    tournamentName = it.text.trim(); break;
                }
            }
        }

        // ── Split into LEFT and RIGHT columns at page midpoint ──
        const midX = vp.width / 2;
        const leftItems  = items.filter(it => it.x + it.width * 0.5 < midX);
        const rightItems = items.filter(it => it.x + it.width * 0.5 >= midX);

        const leftTeams  = pdfParseColumn(pdfGroupLines(leftItems));
        const rightTeams = pdfParseColumn(pdfGroupLines(rightItems));

        // Interleave: left[0], right[0], left[1], right[1] …
        // This preserves visual reading order (top-left, top-right, bottom-left, bottom-right)
        const maxLen = Math.max(leftTeams.length, rightTeams.length);
        for (let i = 0; i < maxLen; i++) {
            if (leftTeams[i])  allTeams.push(leftTeams[i]);
            if (rightTeams[i]) allTeams.push(rightTeams[i]);
        }
    }
    } catch(e) {
        toast('⚠️ Error reading PDF contents.');
        console.error('[PDF] page parse error:', e);
        return;
    }

    toast('');
    if (allTeams.length === 0) {
        toast('⚠️ No teams found. The PDF format may not match the expected layout.');
        console.warn('[PDF parser] No teams extracted. Check console for raw items.');
        return;
    }

    callback(allTeams, tournamentName);
}

// ── Roster-level PDF import (from Rosters page) ───────────────────────────────
let _pickerTournament = '';
let _pendingImportTeams = null;

function showTournamentPicker(teams) {
    _pendingImportTeams = teams;
    _pickerTournament = '';
    const list = document.getElementById('tournament-picker-list');
    if (!TOURNAMENT_NAMES || TOURNAMENT_NAMES.length === 0) {
        list.innerHTML = '<div style="font-size:13px;color:var(--muted);padding:6px 0">No tournaments saved yet. Enter one below or skip.</div>';
    } else {
        list.innerHTML = TOURNAMENT_NAMES.map(name => {
            const safe = name.replace(/'/g, "\\'");
            return '<div onclick="selectTournamentOption(this,\'' + safe + '\')" style="padding:10px 14px;border:1.5px solid var(--bdr);border-radius:9px;cursor:pointer;font-weight:600;font-size:14px;transition:all .12s" onmouseover="if(!this.dataset.sel)this.style.background=\'var(--bg)\'" onmouseout="if(!this.dataset.sel)this.style.background=\'\'">&#x1F3C6; ' + name + '</div>';
        }).join('');
    }
    document.getElementById('tournament-custom-input').value = '';
    document.getElementById('tournament-picker-overlay').style.display = 'flex';
}

function selectTournamentOption(el, name) {
    document.querySelectorAll('#tournament-picker-list > div').forEach(d => {
        d.style.border = '1.5px solid var(--bdr)'; d.style.background = ''; delete d.dataset.sel;
    });
    el.style.border = '1.5px solid var(--navy)';
    el.style.background = 'rgba(0,48,135,.06)';
    el.dataset.sel = '1';
    document.getElementById('tournament-custom-input').value = name;
    _pickerTournament = name;
}

function confirmTournamentPicker() {
    const custom = document.getElementById('tournament-custom-input').value.trim();
    if (custom) _pickerTournament = custom;
    closeTournamentPicker();
    _proceedAfterTournament();
}

function skipTournamentPicker() {
    _pickerTournament = '';
    closeTournamentPicker();
    _proceedAfterTournament();
}

function closeTournamentPicker() {
    document.getElementById('tournament-picker-overlay').style.display = 'none';
}

async function _proceedAfterTournament() {
    const teams = _pendingImportTeams;
    _pendingImportTeams = null;
    const tournament = _pickerTournament;
    if (!teams) return;
    if (teams.length === 1) {
        const t = teams[0];
        const j = await api({ action: 'save_roster', name: t.teamName, age_group: t.ageGroup, tournament, players: t.players });
        if (j.ok) {
            toast('✅ Saved: ' + t.teamName + ' (' + t.players.length + ' players)');
            setTimeout(() => goto('?t=<?=$T?>&view=roster_edit&r=' + j.roster_key), 700);
        } else toast('⚠️ ' + j.error);
    } else {
        showTeamPicker(teams, null);
    }
}

async function importPdfToRoster(input) {
    await parsePdfToTeams(input, async (teams) => {
        if (!teams || teams.length === 0) { toast('⚠️ No players found in this PDF.'); return; }
        showTournamentPicker(teams);
    });
}

let pdfJsLoaded = false;
function loadPdfJs() {
    return new Promise((res, rej) => {
        if (window.pdfjsLib) { res(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
        s.onload = () => { pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'; res(); };
        s.onerror = rej;
        document.head.appendChild(s);
    });
}
</script>
</body>
</html>

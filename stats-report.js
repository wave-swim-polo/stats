// ═══════════════════════════════════════════════════════════════════════════════
// stats-report.js — Shared report engine for WAVE Stats Tracker
// Used by both stats-admin.php and stats-tracker.php
// ONE source of truth: edit this file, both admin and end-user reports update.
// ═══════════════════════════════════════════════════════════════════════════════

// ── Crunch all stats from games array ──────────────────────────────────────
function crunchReportData(games, title, subtitle, scope) {
    // Aggregate all events across all games
    // Build player map from all games
    const playerMap = {}; // id → {name, number, isGoalie, teamId}
    games.forEach(g => (g.players || []).forEach(p => { playerMap[p.id] = p; }));

    const allEvents = [];
    const gameResults = [];

    games.forEach(g => {
        const evts = g.official_events || [];
        evts.forEach(e => { e._game = g.game_key; e._gameDate = g.game_date; });
        allEvents.push(...evts);

        // Game result row
        const waveScore = g.wave_score ?? evts.filter(e => ['goal','penalty_5m_goal_for'].includes(e.stat) && isWaveEvent(e)).length;
        const oppScore  = g.opp_score  ?? evts.filter(e => ['goals_against','penalty_5m_goal_against'].includes(e.stat)).length;
        gameResults.push({
            date: g.game_date, opponent: g.opponent, tournament: g.tournament,
            waveScore, oppScore,
            result: waveScore > oppScore ? 'W' : waveScore < oppScore ? 'L' : 'T',
            events: evts
        });
    });

    const sections = [];

    // ── 1. Game-by-game results table ───────────────────────────────────────
    if (games.length > 1) {
        const wins   = gameResults.filter(r=>r.result==='W').length;
        const losses = gameResults.filter(r=>r.result==='L').length;
        const ties   = gameResults.filter(r=>r.result==='T').length;
        const waveTotal = gameResults.reduce((s,r)=>s+r.waveScore,0);
        const oppTotal  = gameResults.reduce((s,r)=>s+r.oppScore,0);
        sections.push({ type: 'results_table', title: 'Results', rows: gameResults, record: `${wins}W–${losses}L${ties?'–'+ties+'T':''}`, waveTotal, oppTotal });
    }

    // ── 2. Team Box Score (goals/shots/saves by period) ─────────────────────
    const periods = ['1Q','2Q','3Q','4Q','OT','SO'];
    const boxScore = {};
    const oppOnlyStats = new Set(['goals_against','penalty_5m_goal_against','shot_against']);
    // Wave event = has a playerId AND (roster exists → must be in roster; no roster → trust it)
    // isWaveEvent: any event with a playerId that isn't an opp-only stat is a WAVE event.
    // playerMap is for name lookup only — do NOT gate events on roster membership,
    // since local player IDs and server player IDs may differ.
    const isWaveEvent = e => oppOnlyStats.has(e.stat) ? false : !!e.playerId;
    periods.forEach(p => {
        const pEvts = allEvents.filter(e => e.period === p);
        boxScore[p] = {
            waveGoals:  pEvts.filter(e => ['goal','penalty_5m_goal_for'].includes(e.stat) && isWaveEvent(e)).length,
            oppGoals:   pEvts.filter(e => ['goals_against','penalty_5m_goal_against'].includes(e.stat)).length,
            waveShots:  pEvts.filter(e => ['goal','shot'].includes(e.stat) && isWaveEvent(e)).length,
            waveSaves:  pEvts.filter(e => e.stat === 'save' && isWaveEvent(e)).length,
        };
    });
    const hasData = periods.some(p => boxScore[p].waveGoals || boxScore[p].oppGoals || boxScore[p].waveShots);
    if (hasData) sections.push({ type: 'box_score', title: 'Team Box Score', boxScore, periods });

    // ── 3. Player Stats & Leaderboards ──────────────────────────────────────
    const statKeys = ['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save'];
    const playerStats = {}; // pid → { name, number, isGoalie, stats:{}, goalsAgainst, shotsAgainst, ... }

    // Seed from roster if available
    Object.entries(playerMap).forEach(([pid, p]) => {
        playerStats[pid] = { name: p.name, number: p.number, isGoalie: p.isGoalie,
            stats: Object.fromEntries(statKeys.map(k=>[k,0])),
            goalsAgainst: 0, shotsAgainst: 0, penalties5mFor: 0, penalties5mAgainst: 0, penalties5mBlock: 0 };
    });
    // Seed any event playerIds not already in playerMap (covers ID mismatch between local and server)
    allEvents.forEach(e => {
        if (!e.playerId || oppOnlyStats.has(e.stat)) return;
        if (!playerStats[e.playerId]) {
            playerStats[e.playerId] = {
                name: e.playerName || ('Cap ' + (e.capNumber || '?')),
                number: String(e.capNumber || '?'),
                isGoalie: e.stat === 'save',
                stats: Object.fromEntries(statKeys.map(k=>[k,0])),
                goalsAgainst: 0, shotsAgainst: 0, penalties5mFor: 0, penalties5mAgainst: 0, penalties5mBlock: 0
            };
        }
        if (e.stat === 'save') playerStats[e.playerId].isGoalie = true;
    });

    allEvents.forEach(e => {
        const pid = e.playerId;
        if (!pid || !playerStats[pid]) return;
        if (statKeys.includes(e.stat))            playerStats[pid].stats[e.stat]++;
        if (e.stat === 'goals_against')            playerStats[pid].goalsAgainst++;
        if (e.stat === 'shot_against')             playerStats[pid].shotsAgainst++;
        if (e.stat === 'penalty_5m_goal_for')      { playerStats[pid].stats.goal++; playerStats[pid].penalties5mFor++; }
        if (e.stat === 'penalty_5m_miss_for')      playerStats[pid].penalties5mFor++;
        if (e.stat === 'penalty_5m_goal_against')  playerStats[pid].goalsAgainst++;
        if (e.stat === 'penalty_5m_block')         playerStats[pid].penalties5mBlock++;
        // Shooting attempts = goals + shots (for shooting %)
        if (['goal','shot'].includes(e.stat) && isWaveEvent(e)) playerStats[pid].shotsAttempted = (playerStats[pid].shotsAttempted||0) + 1;
        if (e.stat === 'penalty_5m_goal_for')                   playerStats[pid].shotsAttempted = (playerStats[pid].shotsAttempted||0) + 1;
        if (e.stat === 'penalty_5m_miss_for')                   playerStats[pid].shotsAttempted = (playerStats[pid].shotsAttempted||0) + 1;
    });

    // Build leaderboard arrays
    const allPlayers = Object.entries(playerStats).map(([pid,p])=>({pid,...p}))
        .filter(p => Object.values(p.stats).some(v=>v>0) || p.goalsAgainst > 0 || p.shotsAgainst > 0);

    const fieldPlayers  = allPlayers.filter(p => !p.isGoalie);
    const goaliePlayers = allPlayers.filter(p => p.isGoalie);

    sections.push({ type: 'player_stats', title: 'Player Stats', fieldPlayers, goaliePlayers, statKeys });

    // Leaderboards
    const leaders = {};
    ['goal','assist','shot','steal','block','kickout_earned','save'].forEach(stat => {
        const sorted = allPlayers.filter(p=>p.stats[stat]>0).sort((a,b)=>b.stats[stat]-a.stats[stat]).slice(0,5);
        if (sorted.length) leaders[stat] = sorted;
    });
    sections.push({ type: 'leaderboards', title: 'Leaderboards', leaders });

    // ── 4. Play-by-Play ──────────────────────────────────────────────────────
    const PBP_LABELS = {
        goal:'Goal', assist:'Assist', shot:'Shot (missed)', save:'Save',
        steal:'Steal', block:'Block', kickout:'Exclusion', kickout_earned:'Exclusion drawn',
        turnover:'Turnover', goals_against:'Goal against', shot_against:'Shot against',
        penalty_5m_goal_for:'Penalty goal', penalty_5m_miss_for:'Penalty miss',
        penalty_5m_goal_against:'Penalty goal against', penalty_5m_block:'Penalty blocked',
    };
    const pbpPeriods = ['1Q','2Q','3Q','4Q','OT','SO'];
    const pbpGroups = [];
    pbpPeriods.forEach(p => {
        const pEvts = allEvents.filter(e => e.period === p).slice().sort((a,b) => (a.ts||0)-(b.ts||0));
        if (!pEvts.length) return;
        let wScore = 0, oScore = 0;
        // compute running score UP TO this period start
        allEvents.filter(e => {
            const pi = pbpPeriods.indexOf(e.period), ci = pbpPeriods.indexOf(p);
            return pi >= 0 && pi < ci;
        }).forEach(e => {
            if (['goal','penalty_5m_goal_for'].includes(e.stat) && isWaveEvent(e)) wScore++;
            if (['goals_against','penalty_5m_goal_against'].includes(e.stat)) oScore++;
        });
        const rows = pEvts.map(e => {
            const isGoal = ['goal','penalty_5m_goal_for'].includes(e.stat) && isWaveEvent(e);
            const isOppGoal = ['goals_against','penalty_5m_goal_against'].includes(e.stat);
            if (isGoal) wScore++;
            if (isOppGoal) oScore++;
            const p = playerMap[e.playerId] || (playerStats[e.playerId] ? {name: playerStats[e.playerId].name, number: playerStats[e.playerId].number} : null);
            return {
                stat: e.stat,
                label: PBP_LABELS[e.stat] || e.stat,
                playerName: p ? p.name : null,
                capNumber: p ? p.number : (e.capNumber || (isOppGoal && e.oppNum ? e.oppNum : null)),
                isWave: isWaveEvent(e),
                isGoal: isGoal || isOppGoal,
                runningScore: `${wScore}–${oScore}`,
            };
        });
        pbpGroups.push({ period: p, rows });
    });
    if (pbpGroups.length) sections.push({ type: 'play_by_play', title: 'Play-by-Play', groups: pbpGroups });

    // ── 5. Team Comparison ────────────────────────────────────────────────────
    const waveGoalsTotal  = allEvents.filter(e => ['goal','penalty_5m_goal_for'].includes(e.stat) && isWaveEvent(e)).length;
    const waveShotsTotal  = allEvents.filter(e => ['goal','shot'].includes(e.stat) && isWaveEvent(e)).length + allEvents.filter(e => ['penalty_5m_goal_for','penalty_5m_miss_for'].includes(e.stat) && isWaveEvent(e)).length;
    const oppGoalsTotal   = allEvents.filter(e => ['goals_against','penalty_5m_goal_against'].includes(e.stat)).length;
    const oppShotsAgainst = allEvents.filter(e => e.stat === 'shot_against').length + oppGoalsTotal;
    const waveSavesTotal  = allEvents.filter(e => e.stat === 'save' && isWaveEvent(e)).length;
    const waveStealsTotal = allEvents.filter(e => e.stat === 'steal' && isWaveEvent(e)).length;
    const waveTOTotal     = allEvents.filter(e => e.stat === 'turnover' && isWaveEvent(e)).length;
    const waveBlocksTotal = allEvents.filter(e => e.stat === 'block' && isWaveEvent(e)).length;
    const waveKOTotal     = allEvents.filter(e => e.stat === 'kickout_earned' && isWaveEvent(e)).length;
    const waveManUpGoals  = allEvents.filter(e => e.stat === 'goal' && e.situation === 'man_up' && isWaveEvent(e)).length;
    const wavePenGoals    = allEvents.filter(e => e.stat === 'penalty_5m_goal_for' && isWaveEvent(e)).length;
    const waveGoalsAll    = waveGoalsTotal; // includes penalty goals via stat accumulation
    const wavePPConv      = waveKOTotal > 0 ? Math.round((waveManUpGoals + wavePenGoals) / waveKOTotal * 100) : null;
    // For opponent we only have goals_against, shot_against, and derived data
    const teamCompare = [
        { label: 'Goals / Attempts', waveVal: `${waveGoalsTotal}/${waveShotsTotal}`, oppVal: `${oppGoalsTotal}/${oppShotsAgainst||'?'}`, wavePct: waveShotsTotal>0?Math.round(waveGoalsTotal/waveShotsTotal*100):null, oppPct: oppShotsAgainst>0?Math.round(oppGoalsTotal/oppShotsAgainst*100):null },
        ...(waveKOTotal>0 ? [{ label: 'Powerplay Conv.', waveVal: `${waveManUpGoals+wavePenGoals}/${waveKOTotal}`, oppVal: '—', wavePct: wavePPConv, oppPct: null }] : []),
        { label: 'Saves', waveVal: String(waveSavesTotal), oppVal: '—', wavePct: null, oppPct: null },
        { label: 'Steals', waveVal: String(waveStealsTotal), oppVal: '—', wavePct: null, oppPct: null },
        { label: 'Turnovers', waveVal: String(waveTOTotal), oppVal: '—', wavePct: null, oppPct: null },
        { label: 'Blocks', waveVal: String(waveBlocksTotal), oppVal: '—', wavePct: null, oppPct: null },
    ].filter(r => r.waveVal !== '0' && r.waveVal !== '0/0');
    if (teamCompare.length) sections.push({ type: 'team_compare', title: 'Team Comparison', rows: teamCompare,
        waveLabel: games[0]?.wave_team || 'WAVE', oppLabel: games[0]?.opponent || 'Opponent' });

    // ── 6. Goalie Save Percentage ────────────────────────────────────────────
    const goalieData = goaliePlayers.map(p => {
        const saves = p.stats.save || 0;
        const ga    = p.goalsAgainst || 0;
        const sa    = p.shotsAgainst || saves + ga;
        const svPct = sa > 0 ? Math.round(saves / sa * 1000) / 10 : null;
        return { name: p.name, number: p.number, saves, goalsAgainst: ga, shotsAgainst: sa, svPct };
    }).filter(g => g.saves + g.goalsAgainst > 0);
    if (goalieData.length) sections.push({ type: 'goalies', title: 'Goalie Stats', goalieData });

    // ── 7. Man-Up / Man-Down Shooting ───────────────────────────────────────
    const shotEvts = allEvents.filter(e => ['goal','shot'].includes(e.stat) && isWaveEvent(e));
    const situations = {
        man_up:   { label: 'Man Up',   shots: 0, goals: 0 },
        man_down: { label: 'Man Down', shots: 0, goals: 0 },
        even:     { label: 'Even',     shots: 0, goals: 0 },
    };
    shotEvts.forEach(e => {
        const sit = e.situation || 'even';
        if (!situations[sit]) return;
        situations[sit].shots++;
        if (e.stat === 'goal') situations[sit].goals++;
    });
    const situArr = Object.entries(situations).map(([k,v]) => ({
        key: k, ...v, pct: v.shots > 0 ? Math.round(v.goals/v.shots*100) : null
    })).filter(s => s.shots > 0);
    if (situArr.length) sections.push({ type: 'situations', title: 'Shooting by Situation', situations: situArr });

    // ── 8. 5M Penalty Breakdown ─────────────────────────────────────────────
    const penFor     = allEvents.filter(e => e.stat === 'penalty_5m_goal_for').length;
    const penForMiss = allEvents.filter(e => e.stat === 'penalty_5m_miss_for').length;
    const penAgainst = allEvents.filter(e => e.stat === 'penalty_5m_goal_against').length;
    const penAgMiss  = allEvents.filter(e => e.stat === 'penalty_5m_miss_against').length;
    const penBlock   = allEvents.filter(e => e.stat === 'penalty_5m_block').length;
    const totalFor     = penFor + penForMiss;
    const totalAgainst = penAgainst + penAgMiss + penBlock;
    if (totalFor + totalAgainst > 0) {
        sections.push({ type: 'penalties', title: '5M Penalties',
            forGoals: penFor, forMiss: penForMiss, totalFor,
            againstGoals: penAgainst, againstMiss: penAgMiss, againstBlock: penBlock, totalAgainst });
    }

    // ── 9. Shot Zone Heat Map Summary ───────────────────────────────────────
    const shotZoneEvts = allEvents.filter(e => ['goal','shot'].includes(e.stat) && (e.shotLocation?.zone || e.shotZone != null));
    if (shotZoneEvts.length) {
        const zones = {};
        shotZoneEvts.forEach(e => {
            const z = e.shotLocation?.zone ?? e.shotZone;
            if (!zones[z]) zones[z] = { attempts: 0, goals: 0 };
            zones[z].attempts++;
            if (e.stat === 'goal') zones[z].goals++;
        });
        const zoneArr = Object.entries(zones).map(([z,d])=>({
            zone: parseInt(z), ...d, pct: Math.round(d.goals/d.attempts*100)
        })).sort((a,b)=>b.attempts-a.attempts);
        sections.push({ type: 'shot_zones', title: 'Shot Zone Summary', zones: zoneArr, total: shotZoneEvts.length });
    }

    // ── 10. Shot Map (x/y coordinates) ──────────────────────────────────────
    const shotMapEvts = allEvents.filter(e =>
        ['goal','shot','penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat)
        && e.shotLocation?.x != null && e.shotLocation?.y != null
    );
    if (shotMapEvts.length) {
        sections.push({ type: 'shot_map', title: 'Shot Map',
            shots: shotMapEvts.map(e => ({
                x: e.shotLocation.x, y: e.shotLocation.y,
                isGoal: ['goal','penalty_5m_goal_for'].includes(e.stat),
                is5m:   ['penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat),
            }))
        });
    }

    // ── 11. Game Flow (goals by period per game) ─────────────────────────────
    const flowPeriods = ['1Q','2Q','3Q','4Q','OT'];
    const gameFlow = gameResults.map(g => {
        const pMap = {};
        flowPeriods.forEach(p => {
            const pEvts = g.events.filter(e => e.period === p);
            pMap[p] = {
                wave: pEvts.filter(e => ['goal','penalty_5m_goal_for'].includes(e.stat) && isWaveEvent(e)).length,
                opp:  pEvts.filter(e => ['goals_against','penalty_5m_goal_against'].includes(e.stat)).length,
            };
        });
        return { opponent: g.opponent, date: g.date, waveScore: g.waveScore, oppScore: g.oppScore, result: g.result, periods: pMap };
    });
    const hasFlow = gameFlow.some(g => flowPeriods.some(p => g.periods[p].wave || g.periods[p].opp));
    if (hasFlow) sections.push({ type: 'game_flow', title: 'Game Flow', games: gameFlow, periods: flowPeriods });

    // ── 12. Top Performers ──────────────────────────────────────────────────
    const perfStats = [
        { key: 'assist', label: 'Top Playmaker', customIcon: '<svg viewBox="0 0 32 32" width="28" height="28"><circle cx="16" cy="16" r="15" fill="#003087" stroke="#FFC72C" stroke-width="1.5"/><text x="16" y="21" text-anchor="middle" font-family="Georgia,serif" font-weight="900" font-size="12" fill="#FFC72C">99</text></svg>' },
        { key: 'steal',          label: '🦊 Most Steals',       emoji: '🦊' },
        { key: 'block',          label: '🛡 Most Blocks',       emoji: '🛡' },
        { key: 'kickout_earned', label: '📣 Most KO Earned',    emoji: '📣' },
    ];
    const goaliePerf = goaliePlayers.map(p => {
        const saves = p.stats.save || 0;
        const ga    = p.goalsAgainst || 0;
        const sa    = saves + ga;
        return { ...p, svPct: sa > 0 ? Math.round(saves/sa*100) : null };
    }).filter(p => (p.stats.save || 0) > 0).sort((a,b) => (b.svPct||0) - (a.svPct||0));

    const topPerformers = perfStats.map(({ key, label }) => {
        const sorted = allPlayers.filter(p => p.stats[key] > 0).sort((a,b) => b.stats[key] - a.stats[key]);
        if (!sorted.length) return null;
        return { key, label, player: sorted[0], value: sorted[0].stats[key] };
    }).filter(Boolean);

    // Shooting % — min 3 shots to qualify, sorted by goals/shots
    const shootingPct = allPlayers
        .filter(p => !p.isGoalie && p.stats.shot >= 3)
        .map(p => ({ ...p, pct: Math.round(p.stats.goal / p.stats.shot * 100) }))
        .sort((a,b) => b.pct - a.pct);
    if (shootingPct.length) {
        const best = shootingPct[0];
        topPerformers.unshift({ key: 'shoot_pct', label: '🎯 Best Shooting %', player: best, value: best.pct + '%', sub: `${best.stats.goal}G / ${best.stats.shot} shots` });
    }

    if (goaliePerf.length) topPerformers.push({ key: 'save_pct', label: '🧤 Best Save %', player: goaliePerf[0], value: goaliePerf[0].svPct + '%' });
    if (topPerformers.length) sections.push({ type: 'top_performers', title: 'Top Performers', performers: topPerformers });

    // ── 13. Turnovers vs Steals ─────────────────────────────────────────────
    const teamSteals    = allEvents.filter(e => e.stat === 'steal' && isWaveEvent(e)).length;
    const teamTurnovers = allEvents.filter(e => e.stat === 'turnover' && isWaveEvent(e)).length;
    const possessionNet = teamSteals - teamTurnovers;
    const tvsPlayers = allPlayers
        .map(p => ({ name: p.name, number: p.number, steals: p.stats.steal||0, turnovers: p.stats.turnover||0, net: (p.stats.steal||0)-(p.stats.turnover||0) }))
        .filter(p => p.steals > 0 || p.turnovers > 0)
        .sort((a,b) => b.net - a.net);
    if (teamSteals + teamTurnovers > 0) {
        sections.push({ type: 'turnovers_steals', title: 'Turnovers vs Steals',
            teamSteals, teamTurnovers, possessionNet, players: tvsPlayers });
    }

    return { title, subtitle, scope, gameCount: games.length, sections,
        generatedAt: new Date().toISOString() };
}

// ── Render report HTML ──────────────────────────────────────────────────────
function renderReportHTML(data) {
    if (!data || !data.sections) return '<p style="color:var(--muted)">No data.</p>';

    const ZONE_LABELS = {1:'Top-L',2:'Left',3:'Ctr-L',4:'Center',5:'Ctr-R',6:'Right',7:'Top-R'};
    const STAT_LABELS = {goal:'Goals',assist:'Assists',shot:'Shots',steal:'Steals',block:'Blocks',kickout_earned:'KO Earned',save:'Saves'};

    let html = '';
    if (data.subtitle) html += `<div style="font-size:13px;color:var(--muted);margin-bottom:16px">${esc(data.subtitle)}</div>`;
    if (data.gameCount) html += `<div style="font-size:12px;color:var(--muted);margin-bottom:20px">${data.gameCount} game${data.gameCount!==1?'s':''} · Generated ${new Date(data.generatedAt||Date.now()).toLocaleDateString('en-CA')}</div>`;

    data.sections.forEach(sec => {
        html += `<div style="margin-bottom:24px"><div style="font-family:var(--fd);font-size:18px;color:var(--navy);margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid var(--bdr)">${esc(sec.title)}</div>`;

        if (sec.type === 'results_table') {
            html += `<div style="font-weight:700;font-size:15px;margin-bottom:8px;color:var(--navy)">${esc(sec.record)} &nbsp;·&nbsp; GF: ${sec.waveTotal} &nbsp; GA: ${sec.oppTotal}</div>`;
            html += `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead><tr style="background:var(--navy);color:#fff">
                    <th style="padding:6px 10px;text-align:left">Date</th>
                    <th style="padding:6px 10px;text-align:left">Opponent</th>
                    <th style="padding:6px 10px"><?=$clubName?></th><th style="padding:6px 10px">OPP</th>
                    <th style="padding:6px 10px">Result</th>
                </tr></thead><tbody>`;
            sec.rows.forEach((r,i) => {
                const bg = i%2===0?'#f8f9fc':'#fff';
                const rc = r.result==='W'?'#16a34a':r.result==='L'?'#ef4444':'#6b7280';
                html += `<tr style="background:${bg}">
                    <td style="padding:5px 10px">${new Date(r.date+'T12:00:00').toLocaleDateString('en-CA',{month:'short',day:'numeric'})}</td>
                    <td style="padding:5px 10px;font-weight:600">${esc(r.opponent)}</td>
                    <td style="padding:5px 10px;text-align:center;font-family:var(--fm);font-weight:700">${r.waveScore}</td>
                    <td style="padding:5px 10px;text-align:center;font-family:var(--fm);font-weight:700">${r.oppScore}</td>
                    <td style="padding:5px 10px;text-align:center;font-weight:700;color:${rc}">${r.result}</td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
        }

        else if (sec.type === 'box_score') {
            const activePeriods = sec.periods.filter(p => sec.boxScore[p].waveGoals || sec.boxScore[p].oppGoals || sec.boxScore[p].waveShots);
            if (activePeriods.length) {
                html += `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">
                    <thead><tr style="background:var(--navy);color:#fff"><th style="padding:6px 10px;text-align:left">Team</th>`;
                activePeriods.forEach(p => html += `<th style="padding:6px 10px">${p}</th>`);
                html += `<th style="padding:6px 10px">TOT</th><th style="padding:6px 10px">SH</th><th style="padding:6px 10px">SV</th></tr></thead><tbody>`;
                // WAVE row
                const waveTotal = activePeriods.reduce((s,p)=>s+sec.boxScore[p].waveGoals,0);
                const waveShots = activePeriods.reduce((s,p)=>s+sec.boxScore[p].waveShots,0);
                const waveSaves = activePeriods.reduce((s,p)=>s+sec.boxScore[p].waveSaves,0);
                html += `<tr style="background:#f0f7ff"><td style="padding:5px 10px;font-weight:700"><?=$clubName?></td>`;
                activePeriods.forEach(p => html += `<td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${sec.boxScore[p].waveGoals||'—'}</td>`);
                html += `<td style="padding:5px 10px;text-align:center;font-family:var(--fm);font-weight:700">${waveTotal}</td><td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${waveShots}</td><td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${waveSaves}</td></tr>`;
                // OPP row
                const oppTotal = activePeriods.reduce((s,p)=>s+sec.boxScore[p].oppGoals,0);
                html += `<tr><td style="padding:5px 10px;font-weight:700">Opponent</td>`;
                activePeriods.forEach(p => html += `<td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${sec.boxScore[p].oppGoals||'—'}</td>`);
                html += `<td style="padding:5px 10px;text-align:center;font-family:var(--fm);font-weight:700">${oppTotal}</td><td style="padding:5px 10px;text-align:center;font-family:var(--fm)">—</td><td style="padding:5px 10px;text-align:center;font-family:var(--fm)">—</td></tr>`;
                html += `</tbody></table></div>`;
            }
        }

        else if (sec.type === 'player_stats') {
            const allP = [...sec.fieldPlayers, ...sec.goaliePlayers].sort((a,b)=>parseInt(a.number)-parseInt(b.number));
            if (allP.length) {
                // Show G/Att instead of bare G; show other non-zero stat cols
                const nonGoalCols = sec.statKeys.filter(k => k !== 'goal' && allP.some(p=>p.stats[k]>0));
                const hasGoals = allP.some(p => p.stats.goal > 0 || p.shotsAttempted > 0);
                const COL_HDR = {assist:'A',shot:'SH',steal:'ST',block:'BL',turnover:'TO',kickout:'KO',kickout_earned:'KOE',save:'SV'};
                html += `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:11px">
                    <thead><tr style="background:var(--navy);color:#fff">
                        <th style="padding:5px 8px;text-align:left">#</th>
                        <th style="padding:5px 8px;text-align:left">Name</th>`;
                if (hasGoals) html += `<th style="padding:5px 8px">G/Att</th>`;
                nonGoalCols.forEach(k => html += `<th style="padding:5px 8px">${COL_HDR[k]||k}</th>`);
                html += `</tr></thead><tbody>`;
                allP.forEach((p,i) => {
                    const bg = i%2===0?'#f8f9fc':'#fff';
                    const goals = p.stats.goal||0;
                    const att   = p.shotsAttempted||goals;
                    const pct   = att > 0 ? Math.round(goals/att*100) : null;
                    const gAtt  = att > 0 ? `${goals}/${att}${pct!==null?' <span style="font-size:10px;color:var(--muted)">('+pct+'%)</span>':''}` : '—';
                    html += `<tr style="background:${bg}"><td style="padding:4px 8px;font-family:var(--fm);color:var(--muted)">${esc(p.number)}</td>
                        <td style="padding:4px 8px;font-weight:600">${esc(p.name)}${p.isGoalie?' 🧤':''}</td>`;
                    if (hasGoals) html += `<td style="padding:4px 8px;text-align:center;font-family:var(--fm)${goals>0?';font-weight:700;color:var(--navy)':';color:#ccc'}">${gAtt}</td>`;
                    nonGoalCols.forEach(k => {
                        const v = p.stats[k]||0;
                        html += `<td style="padding:4px 8px;text-align:center;font-family:var(--fm)${v>0?';font-weight:700;color:var(--navy)':';color:#ccc'}">${v||'—'}</td>`;
                    });
                    html += `</tr>`;
                });
                html += `</tbody></table></div>`;
            }
        }

        else if (sec.type === 'play_by_play') {
            const SCORE_STATS = new Set(['goal','penalty_5m_goal_for','goals_against','penalty_5m_goal_against']);
            html += `<div style="overflow-x:auto">`;
            sec.groups.forEach(g => {
                html += `<div style="margin-bottom:16px">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);padding:5px 10px;background:var(--bg);border-radius:6px;margin-bottom:4px">${esc(g.period)}</div>
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                    <thead><tr style="background:var(--navy);color:#fff">
                        <th style="padding:5px 10px;text-align:left">Action</th>
                        <th style="padding:5px 10px;text-align:left">Player</th>
                        <th style="padding:5px 10px;text-align:right">Score</th>
                    </tr></thead><tbody>`;
                g.rows.forEach((row, i) => {
                    const isScore = SCORE_STATS.has(row.stat);
                    const bg = isScore ? (row.isWave ? 'rgba(0,48,135,0.07)' : 'rgba(239,68,68,0.06)') : (i%2===0?'#f8f9fc':'#fff');
                    const fw = isScore ? 'font-weight:700;' : '';
                    const playerCell = row.capNumber ? `#${esc(row.capNumber)}${row.playerName ? ' '+esc(row.playerName) : ''}` : (row.playerName || '—');
                    const teamBadge = row.isWave
                        ? `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#003087;margin-right:5px;vertical-align:middle"></span>`
                        : `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#94a3b8;margin-right:5px;vertical-align:middle"></span>`;
                    html += `<tr style="background:${bg}">
                        <td style="padding:4px 10px;${fw}">${teamBadge}${esc(row.label)}</td>
                        <td style="padding:4px 10px;${fw}color:var(--muted);font-size:11px">${playerCell}</td>
                        <td style="padding:4px 10px;text-align:right;font-family:var(--fm)${isScore?';font-weight:700;color:var(--navy)':';color:#ccc'}">${isScore ? row.runningScore : '—'}</td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
            });
            html += `</div>`;
        }

        else if (sec.type === 'team_compare') {
            html += `<div style="display:grid;gap:6px">`;
            sec.rows.forEach(row => {
                const wPct = row.wavePct ?? null;
                const oPct = row.oppPct ?? null;
                // bar widths: scale to whichever side is higher
                const maxPct = Math.max(wPct??0, oPct??0, 1);
                const wBar = wPct !== null ? Math.round(wPct/maxPct*100) : null;
                const oBar = oPct !== null ? Math.round(oPct/maxPct*100) : null;
                // If no pct, use raw numeric values for bar scaling
                const wNum = parseFloat(row.waveVal) || 0;
                const oNum = parseFloat(row.oppVal)  || 0;
                const maxNum = Math.max(wNum, oNum, 1);
                const wBarNum = Math.round(wNum/maxNum*100);
                const oBarNum = Math.round(oNum/maxNum*100);
                const useBar = wBar !== null || oBar !== null;
                const wW = useBar ? (wBar??wBarNum) : wBarNum;
                const oW = useBar ? (oBar??oBarNum) : oBarNum;
                html += `<div style="display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--bdr)">
                    <div style="text-align:right">
                        <div style="font-family:var(--fm);font-weight:700;font-size:13px;color:var(--navy)">${esc(row.waveVal)}</div>
                        <div style="height:6px;border-radius:3px;background:#003087;margin-top:3px;min-width:2px;width:${Math.max(wW,2)}%"></div>
                    </div>
                    <div style="font-size:11px;color:var(--muted);text-align:center;white-space:nowrap;padding:0 4px">${esc(row.label)}</div>
                    <div style="text-align:left">
                        <div style="font-family:var(--fm);font-weight:700;font-size:13px;color:#64748b">${esc(row.oppVal)}</div>
                        <div style="height:6px;border-radius:3px;background:#94a3b8;margin-top:3px;min-width:2px;width:${Math.max(oW,2)}%"></div>
                    </div>
                </div>`;
            });
            // Team name labels
            html += `<div style="display:grid;grid-template-columns:1fr auto 1fr;gap:8px;margin-top:6px">
                <div style="text-align:right;font-size:11px;font-weight:700;color:#003087">${esc(sec.waveLabel)}</div>
                <div></div>
                <div style="text-align:left;font-size:11px;font-weight:700;color:#64748b">${esc(sec.oppLabel)}</div>
            </div></div>`;
        }

        else if (sec.type === 'leaderboards') {
            html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">`;
            Object.entries(sec.leaders).forEach(([stat, players]) => {
                html += `<div style="background:var(--bg);border-radius:8px;padding:10px">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-bottom:6px">${STAT_LABELS[stat]||stat}</div>`;
                players.forEach((p,i) => {
                    const medals = ['🥇','🥈','🥉'];
                    let valDisplay = p.stats[stat];
                    if (stat === 'goal') {
                        const att = p.shotsAttempted || p.stats[stat];
                        const pct = att > 0 ? Math.round(p.stats[stat]/att*100) : null;
                        valDisplay = `${p.stats[stat]}/${att}${pct!==null?' ('+pct+'%)':''}`;
                    }
                    html += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;font-size:12px">
                        <span>${medals[i]||'  '} ${esc(p.name)}</span>
                        <span style="font-family:var(--fm);font-weight:700;color:var(--navy)">${valDisplay}</span>
                    </div>`;
                });
                html += `</div>`;
            });
            html += `</div>`;
        }

        else if (sec.type === 'goalies') {
            html += `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead><tr style="background:var(--navy);color:#fff">
                    <th style="padding:6px 10px;text-align:left">#</th>
                    <th style="padding:6px 10px;text-align:left">Goalie</th>
                    <th style="padding:6px 10px">SV</th><th style="padding:6px 10px">GA</th>
                    <th style="padding:6px 10px">SA</th><th style="padding:6px 10px">SV%</th>
                </tr></thead><tbody>`;
            sec.goalieData.forEach((g,i) => {
                const bg = i%2===0?'#f8f9fc':'#fff';
                html += `<tr style="background:${bg}">
                    <td style="padding:5px 10px;font-family:var(--fm);color:var(--muted)">${esc(g.number)}</td>
                    <td style="padding:5px 10px;font-weight:600">${esc(g.name)}</td>
                    <td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${g.saves}</td>
                    <td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${g.goalsAgainst}</td>
                    <td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${g.shotsAgainst}</td>
                    <td style="padding:5px 10px;text-align:center;font-family:var(--fm);font-weight:700;color:var(--navy)">${g.svPct !== null ? g.svPct+'%' : '—'}</td>
                </tr>`;
            });
            html += `</tbody></table></div>`;
        }

        else if (sec.type === 'situations') {
            html += `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">`;
            sec.situations.forEach(s => {
                const pct = s.pct !== null ? s.pct : '—';
                const color = s.key==='man_up'?'#16a34a':s.key==='man_down'?'#ef4444':'#0ea5e9';
                html += `<div style="background:var(--bg);border-radius:8px;padding:12px;text-align:center;border-top:3px solid ${color}">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-bottom:6px">${esc(s.label)}</div>
                    <div style="font-family:var(--fd);font-size:28px;color:${color}">${pct}${s.pct!==null?'%':''}</div>
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">${s.goals} goals / ${s.shots} shots</div>
                </div>`;
            });
            html += `</div>`;
        }

        else if (sec.type === 'penalties') {
            const forPct     = sec.totalFor > 0     ? Math.round(sec.forGoals/sec.totalFor*100) : null;
            const againstPct = sec.totalAgainst > 0 ? Math.round(sec.againstGoals/sec.totalAgainst*100) : null;
            html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:#f0fff4;border-radius:8px;padding:14px;border-top:3px solid #16a34a">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#16a34a;text-transform:uppercase;margin-bottom:8px"><?=$clubName?> FOR</div>
                    <div style="font-family:var(--fd);font-size:32px;color:#16a34a">${forPct !== null ? forPct+'%' : '—'}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px">${sec.forGoals} goals · ${sec.forMiss} miss · ${sec.totalFor} total</div>
                </div>
                <div style="background:#fff5f5;border-radius:8px;padding:14px;border-top:3px solid #ef4444">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#ef4444;text-transform:uppercase;margin-bottom:8px"><?=$clubName?> AGAINST</div>
                    <div style="font-family:var(--fd);font-size:32px;color:#ef4444">${againstPct !== null ? againstPct+'%' : '—'}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px">${sec.againstGoals} goals · ${sec.againstMiss} miss · ${sec.againstBlock} blocked · ${sec.totalAgainst} total</div>
                </div>
            </div>`;
        }

        else if (sec.type === 'shot_zones') {
            const maxAtt = Math.max(...sec.zones.map(z=>z.attempts));
            html += `<div style="font-size:12px;color:var(--muted);margin-bottom:10px">${sec.total} total shots with location data</div>`;
            html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px">`;
            sec.zones.forEach(z => {
                const intensity = Math.round(z.attempts/maxAtt*100);
                const bg = `rgba(0,48,135,${0.05 + intensity/100*0.3})`;
                html += `<div style="background:${bg};border-radius:8px;padding:10px;text-align:center;border:1px solid var(--bdr)">
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Zone ${z.zone} · ${ZONE_LABELS[z.zone]||''}</div>
                    <div style="font-family:var(--fd);font-size:24px;color:var(--navy)">${z.attempts}</div>
                    <div style="font-size:11px;color:var(--muted)">${z.goals}G · ${z.pct}%</div>
                </div>`;
            });
            html += `</div>`;
        }

        else if (sec.type === 'shot_map') {
            // SVG pool: 100 wide x 60 tall, attack end on right (x=100)
            const W=100, H=60;
            let dots = sec.shots.map(s => {
                const cx = (s.x/100)*W, cy = (s.y/100)*H;
                const fill = s.isGoal ? '#22c55e' : '#f59e0b';
                const r = s.is5m ? 3.5 : 2.8;
                return `<circle cx="${cx.toFixed(1)}" cy="${cy.toFixed(1)}" r="${r}" fill="${fill}" fill-opacity="0.85" stroke="#fff" stroke-width="0.5"/>`;
            }).join('');
            html += `<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
                <svg viewBox="0 0 ${W} ${H}" style="width:260px;max-width:100%;border:1px solid var(--bdr);border-radius:8px;background:#dbeafe">
                    <rect x="0" y="0" width="${W}" height="${H}" fill="#dbeafe"/>
                    <!-- pool outline -->
                    <rect x="1" y="1" width="${W-2}" height="${H-2}" fill="none" stroke="#93c5fd" stroke-width="0.5"/>
                    <!-- 2m line -->
                    <line x1="${W*0.82}" y1="1" x2="${W*0.82}" y2="${H-1}" stroke="#93c5fd" stroke-width="0.5" stroke-dasharray="2,2"/>
                    <!-- 5m line -->
                    <line x1="${W*0.65}" y1="1" x2="${W*0.65}" y2="${H-1}" stroke="#bfdbfe" stroke-width="0.5" stroke-dasharray="2,2"/>
                    <!-- goal -->
                    <rect x="${W-2}" y="${H*0.35}" width="2" height="${H*0.3}" fill="#1d4ed8" rx="1"/>
                    ${dots}
                </svg>
                <div>
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;font-size:12px"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#22c55e"></span> Goal</div>
                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px;font-size:12px"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#f59e0b"></span> Shot saved</div>
                    <div style="font-size:12px;color:var(--muted)">${sec.shots.length} shots plotted<br>${sec.shots.filter(s=>s.isGoal).length} goals</div>
                </div>
            </div>`;
        }

        else if (sec.type === 'game_flow') {
            const activePeriods = sec.periods.filter(p => sec.games.some(g => g.periods[p].wave || g.periods[p].opp));
            sec.games.forEach(g => {
                const rc = g.result==='W'?'#16a34a':g.result==='L'?'#ef4444':'#6b7280';
                html += `<div style="margin-bottom:14px">
                    <div style="font-size:12px;font-weight:700;margin-bottom:6px">${esc(g.opponent)} <span style="color:${rc};font-family:var(--fm)">${g.waveScore}–${g.oppScore}</span></div>
                    <div style="display:flex;gap:6px">`;
                activePeriods.forEach(p => {
                    const w = g.periods[p].wave, o = g.periods[p].opp;
                    const maxG = Math.max(w, o, 1);
                    html += `<div style="text-align:center;min-width:40px">
                        <div style="font-size:10px;color:var(--muted);margin-bottom:3px">${p}</div>
                        <div style="display:flex;gap:2px;justify-content:center;align-items:flex-end;height:36px">
                            <div style="width:10px;background:#003087;border-radius:2px 2px 0 0;height:${Math.round(w/maxG*34)+2}px" title="Us: ${w}"></div>
                            <div style="width:10px;background:#ef4444;border-radius:2px 2px 0 0;height:${Math.round(o/maxG*34)+2}px" title="Opp: ${o}"></div>
                        </div>
                        <div style="font-size:10px;font-family:var(--fm);margin-top:2px">${w}–${o}</div>
                    </div>`;
                });
                html += `</div></div>`;
            });
        }

        else if (sec.type === 'top_performers') {
            html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">`;
            sec.performers.forEach(p => {
                const iconHtml = p.customIcon
                    ? `<div style="display:flex;justify-content:center;margin-bottom:6px">${p.customIcon}</div>`
                    : '';
                html += `<div style="background:var(--bg);border-radius:10px;padding:14px;border:1.5px solid var(--bdr);text-align:center">
                    ${iconHtml}
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">${esc(p.label)}</div>
                    <div style="font-family:var(--fd);font-size:26px;color:var(--navy);margin-bottom:2px">${esc(String(p.value))}</div>
                    <div style="font-size:13px;font-weight:700">${esc(p.player.name)}</div>
                    <div style="font-size:11px;color:var(--muted)">Cap ${esc(p.player.number)}</div>
                    ${p.sub ? `<div style="font-size:11px;color:var(--muted);margin-top:2px">${esc(p.sub)}</div>` : ''}
                </div>`;
            });
            html += `</div>`;
        }

        else if (sec.type === 'turnovers_steals') {
            const netColor = sec.possessionNet >= 0 ? '#16a34a' : '#ef4444';
            html += `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">
                <div style="background:#f0fff4;border-radius:8px;padding:12px;text-align:center;border-top:3px solid #16a34a">
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Steals</div>
                    <div style="font-family:var(--fd);font-size:32px;color:#16a34a">${sec.teamSteals}</div>
                </div>
                <div style="background:#fff5f5;border-radius:8px;padding:12px;text-align:center;border-top:3px solid #ef4444">
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Turnovers</div>
                    <div style="font-family:var(--fd);font-size:32px;color:#ef4444">${sec.teamTurnovers}</div>
                </div>
                <div style="background:var(--bg);border-radius:8px;padding:12px;text-align:center;border-top:3px solid ${netColor}">
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Net</div>
                    <div style="font-family:var(--fd);font-size:32px;color:${netColor}">${sec.possessionNet >= 0 ? '+' : ''}${sec.possessionNet}</div>
                </div>
            </div>`;
            if (sec.players.length) {
                html += `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">
                    <thead><tr style="background:var(--navy);color:#fff">
                        <th style="padding:6px 10px;text-align:left">#</th>
                        <th style="padding:6px 10px;text-align:left">Player</th>
                        <th style="padding:6px 10px">Steals</th>
                        <th style="padding:6px 10px">TO</th>
                        <th style="padding:6px 10px">Net</th>
                    </tr></thead><tbody>`;
                sec.players.forEach((p,i) => {
                    const netC = p.net > 0 ? '#16a34a' : p.net < 0 ? '#ef4444' : 'var(--txt)';
                    html += `<tr style="background:${i%2===0?'#f8f9fc':'#fff'}">
                        <td style="padding:5px 10px;font-family:var(--fm);color:var(--muted)">${esc(p.number)}</td>
                        <td style="padding:5px 10px;font-weight:600">${esc(p.name)}</td>
                        <td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${p.steals||'—'}</td>
                        <td style="padding:5px 10px;text-align:center;font-family:var(--fm)">${p.turnovers||'—'}</td>
                        <td style="padding:5px 10px;text-align:center;font-family:var(--fm);font-weight:700;color:${netC}">${p.net > 0 ? '+' : ''}${p.net}</td>
                    </tr>`;
                });
                html += `</tbody></table></div>`;
            }
        }

        html += `</div>`;
    });

    return html;
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Save as PDF ───────────────────────────────────────────────────────────────
function printReport() {
    if (!_rptData) { toast('⚠️ Generate a report first'); return; }
    const title   = document.getElementById('rpt-title').value.trim() || 'Report';
    const content = document.getElementById('rpt-preview-body').innerHTML;
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

// ── Tracker adapter ─────────────────────────────────────────────────────────
// Converts a local tracker game object (from localStorage) into the canonical
// shape expected by crunchReportData, then runs the report engine.

function trackerGameToAdminShape(game, db) {
  const homeTeam = db.teams.find(t => t.id === game.homeTeamId);
  const awayTeam = db.teams.find(t => t.id === game.awayTeamId);
  // Get players by event playerIds (not teamId — teamId may be stale across games)
  const eventPlayerIds = new Set((game.events || []).map(e => e.playerId).filter(Boolean));
  const playerById = {};
  db.players.forEach(p => { playerById[p.id] = p; });
  const wavePlayers = [...eventPlayerIds].map(pid => playerById[pid]).filter(Boolean);
  // Fall back to teamId filter if no event-based players found (new game, no events yet)
  const players = wavePlayers.length > 0
    ? wavePlayers
    : db.players.filter(p => p.teamId === game.homeTeamId);
  return {
    game_key:        game.id,
    game_date:       game.date?.slice(0, 10) || '',
    opponent:        awayTeam?.name || 'Opponent',
    tournament:      game.tournament || '',
    wave_team:       homeTeam?.name || 'WAVE',
    wave_score:      game.homeScore ?? 0,
    opp_score:       game.awayScore ?? 0,
    players:         players,
    official_events: game.events || [],
  };
}

function buildTrackerReport(games, db, title, subtitle, scope) {
  const adminGames = games.map(g => trackerGameToAdminShape(g, db));
  return crunchReportData(adminGames, title, subtitle || '', scope);
}

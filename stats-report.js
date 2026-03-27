// ═══════════════════════════════════════════════════════════════════════════════
// stats-report.js — Shared report engine for WAVE Stats Tracker
// Used by both stats-admin.php and stats-tracker.php
// ONE source of truth: edit this file, both admin and end-user reports update.
// ═══════════════════════════════════════════════════════════════════════════════

// ── Crunch all stats from games array ──────────────────────────────────────
function crunchReportData(games, title, subtitle, scope) {

    // Build player map from all games
    const playerMap = {}; // id → {name, number, isGoalie}
    games.forEach(g => (g.players || []).forEach(p => { playerMap[p.id] = p; }));

    // isWaveEvent: any event with a playerId that isn't an opp-only stat.
    // playerMap is for name lookup only — roster membership doesn't gate stats.
    const OPP_ONLY = new Set(['goals_against','penalty_5m_goal_against','shot_against']);
    const isWaveEvent = e => OPP_ONLY.has(e.stat) ? false : !!e.playerId;

    const GOAL_STATS  = new Set(['goal','penalty_5m_goal_for']);
    const SHOT_STATS  = new Set(['goal','shot']);
    const OPP_GOAL    = new Set(['goals_against','penalty_5m_goal_against']);
    const STAT_KEY_SET = new Set(['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save']);

    // ── Collect all events (copy to avoid mutating caller's data) ──────────
    const allEvents = [];
    const gameResults = [];

    games.forEach(g => {
        const evts = (g.official_events || []).map(e => ({ ...e, _game: g.game_key, _gameDate: g.game_date }));
        allEvents.push(...evts);

        const waveScore = g.wave_score ?? evts.filter(e => GOAL_STATS.has(e.stat) && isWaveEvent(e)).length;
        const oppScore  = g.opp_score  ?? evts.filter(e => OPP_GOAL.has(e.stat)).length;
        gameResults.push({
            date: g.game_date, opponent: g.opponent, tournament: g.tournament,
            waveScore, oppScore,
            result: waveScore > oppScore ? 'W' : waveScore < oppScore ? 'L' : 'T',
            events: evts,
        });
    });

    const sections = [];

    // ── 1. Game-by-game results table ───────────────────────────────────────
    if (games.length > 1) {
        const wins   = gameResults.filter(r => r.result === 'W').length;
        const losses = gameResults.filter(r => r.result === 'L').length;
        const ties   = gameResults.filter(r => r.result === 'T').length;
        sections.push({
            type: 'results_table', title: 'Results', rows: gameResults,
            record: `${wins}W–${losses}L${ties ? '–'+ties+'T' : ''}`,
            waveTotal: gameResults.reduce((s,r) => s+r.waveScore, 0),
            oppTotal:  gameResults.reduce((s,r) => s+r.oppScore,  0),
        });
    }

    // ── Single-pass stat accumulation ───────────────────────────────────────
    // Build playerStats, boxScore, team totals and penalty counts in one pass.
    const statKeys   = ['goal','assist','shot','steal','block','turnover','kickout','kickout_earned','save'];
    const playerStats = {};
    const zeroStats  = () => Object.fromEntries(statKeys.map(k => [k, 0]));

    // Seed from roster
    Object.entries(playerMap).forEach(([pid, p]) => {
        playerStats[pid] = { name: p.name, number: p.number, isGoalie: !!p.isGoalie,
            stats: zeroStats(), goalsAgainst: 0, shotsAgainst: 0,
            penalties5mFor: 0, penalties5mAgainst: 0, penalties5mBlock: 0, shotsAttempted: 0 };
    });

    // Seed any event playerIds not already in roster (ID mismatch / no-roster games)
    allEvents.forEach(e => {
        if (!e.playerId || OPP_ONLY.has(e.stat)) return;
        if (!playerStats[e.playerId]) {
            playerStats[e.playerId] = {
                name: e.playerName || ('Cap ' + (e.capNumber || '?')),
                number: String(e.capNumber || '?'),
                isGoalie: e.stat === 'save',
                stats: zeroStats(), goalsAgainst: 0, shotsAgainst: 0,
                penalties5mFor: 0, penalties5mAgainst: 0, penalties5mBlock: 0, shotsAttempted: 0,
            };
        }
        if (e.stat === 'save') playerStats[e.playerId].isGoalie = true;
    });

    // Period-keyed box score buckets
    const periods = ['1Q','2Q','3Q','4Q','OT','SO'];
    const PERIOD_IDX = Object.fromEntries(periods.map((p,i) => [p,i]));
    const boxScore = {};
    periods.forEach(p => { boxScore[p] = { waveGoals:0, oppGoals:0, waveShots:0, waveSaves:0 }; });

    // Team totals for Team Comparison and Turnovers vs Steals
    let waveGoalsTotal=0, waveShotsTotal=0, oppGoalsTotal=0, oppShotsAgainst=0;
    let waveSavesTotal=0, waveStealsTotal=0, waveTOTotal=0, waveBlocksTotal=0;
    let waveKOTotal=0, waveManUpGoals=0, wavePenGoals=0;
    let penFor=0, penForMiss=0, penAgainst=0, penBlock=0;

    // Period-indexed events for O(1) play-by-play
    const evtsByPeriod = {};
    periods.forEach(p => { evtsByPeriod[p] = []; });

    allEvents.forEach(e => {
        const wave = isWaveEvent(e);
        const bs   = e.period && boxScore[e.period];

        if (e.period) evtsByPeriod[e.period] = evtsByPeriod[e.period] || [];
        if (e.period) evtsByPeriod[e.period].push(e);

        // Box score
        if (bs) {
            if (GOAL_STATS.has(e.stat)  && wave)  { bs.waveGoals++; }
            if (OPP_GOAL.has(e.stat))              { bs.oppGoals++;  }
            if (SHOT_STATS.has(e.stat)  && wave)  { bs.waveShots++; }
            if (e.stat === 'save'       && wave)  { bs.waveSaves++; }
        }

        // Team totals
        if (wave) {
            if (GOAL_STATS.has(e.stat))              waveGoalsTotal++;
            if (SHOT_STATS.has(e.stat))              waveShotsTotal++;
            if (e.stat === 'save')                   waveSavesTotal++;
            if (e.stat === 'steal')                  waveStealsTotal++;
            if (e.stat === 'turnover')               waveTOTotal++;
            if (e.stat === 'block')                  waveBlocksTotal++;
            if (e.stat === 'kickout_earned')         waveKOTotal++;
            if (e.stat === 'goal' && e.situation === 'man_up') waveManUpGoals++;
            if (e.stat === 'penalty_5m_goal_for')    wavePenGoals++;
        }
        if (OPP_GOAL.has(e.stat))                    oppGoalsTotal++;
        if (e.stat === 'shot_against')               oppShotsAgainst++;

        // Penalty counts
        if (e.stat === 'penalty_5m_goal_for')        penFor++;
        if (e.stat === 'penalty_5m_miss_for')        penForMiss++;
        if (e.stat === 'penalty_5m_goal_against')    penAgainst++;
        if (e.stat === 'penalty_5m_block')           penBlock++;

        // Player stats
        const pid = e.playerId;
        if (!pid || !playerStats[pid]) return;
        const ps = playerStats[pid];

        if (STAT_KEY_SET.has(e.stat))              ps.stats[e.stat]++;
        if (e.stat === 'goals_against')            ps.goalsAgainst++;
        if (e.stat === 'shot_against')             ps.shotsAgainst++;
        if (e.stat === 'penalty_5m_goal_against')  ps.goalsAgainst++;
        if (e.stat === 'penalty_5m_block')         ps.penalties5mBlock++;
        if (e.stat === 'penalty_5m_goal_for') {
            ps.stats.goal++;
            ps.penalties5mFor++;
            ps.shotsAttempted++;
        }
        if (e.stat === 'penalty_5m_miss_for') {
            ps.penalties5mFor++;
            ps.shotsAttempted++;
        }
        if (SHOT_STATS.has(e.stat) && wave)        ps.shotsAttempted++;
    });

    // Correct waveShotsTotal: SHOT_STATS already counts goals, don't add penalty goals again
    // waveShotsTotal = goal + shot + penalty_5m_goal_for + penalty_5m_miss_for
    // The loop counted goal+shot, need to add penalty_5m_miss_for only (goal_for already in GOAL_STATS→waveShotsTotal)
    waveShotsTotal += penForMiss; // add missed penalties (goals counted via waveGoalsTotal already being in waveShotsTotal)
    oppShotsAgainst += oppGoalsTotal; // shots against = recorded shots + goals that got through

    // ── 2. Team Box Score ────────────────────────────────────────────────────
    const hasData = periods.some(p => boxScore[p].waveGoals || boxScore[p].oppGoals || boxScore[p].waveShots);
    if (hasData) sections.push({ type: 'box_score', title: 'Team Box Score', boxScore, periods });

    // ── 3. Player Stats & Leaderboards ──────────────────────────────────────
    const allPlayers = Object.entries(playerStats).map(([pid,p]) => ({ pid, ...p }))
        .filter(p => Object.values(p.stats).some(v => v > 0) || p.goalsAgainst > 0 || p.shotsAgainst > 0);

    const fieldPlayers  = allPlayers.filter(p => !p.isGoalie);
    const goaliePlayers = allPlayers.filter(p => p.isGoalie);

    sections.push({ type: 'player_stats', title: 'Player Stats', fieldPlayers, goaliePlayers, statKeys });

    const leaders = {};
    ['goal','assist','shot','steal','block','kickout_earned','save'].forEach(stat => {
        const sorted = allPlayers.filter(p => p.stats[stat] > 0).sort((a,b) => b.stats[stat]-a.stats[stat]).slice(0,5);
        if (sorted.length) leaders[stat] = sorted;
    });
    sections.push({ type: 'leaderboards', title: 'Leaderboards', leaders });

    // ── 4. Play-by-Play (O(n) with precomputed period buckets) ───────────────
    const PBP_LABELS = {
        goal:'Goal', assist:'Assist', shot:'Shot (missed)', save:'Save',
        steal:'Steal', block:'Block', kickout:'Exclusion', kickout_earned:'Exclusion drawn',
        turnover:'Turnover', goals_against:'Goal against', shot_against:'Shot against',
        penalty_5m_goal_for:'Penalty goal', penalty_5m_miss_for:'Penalty miss',
        penalty_5m_goal_against:'Penalty goal against', penalty_5m_block:'Penalty blocked',
    };
    // Pre-compute cumulative scores at start of each period
    const periodStartScore = {};
    let cumW = 0, cumO = 0;
    periods.forEach(p => {
        periodStartScore[p] = { w: cumW, o: cumO };
        (evtsByPeriod[p] || []).forEach(e => {
            if (GOAL_STATS.has(e.stat) && isWaveEvent(e)) cumW++;
            if (OPP_GOAL.has(e.stat)) cumO++;
        });
    });

    const pbpGroups = [];
    periods.forEach(p => {
        const pEvts = (evtsByPeriod[p] || []).slice().sort((a,b) => (a.ts||0)-(b.ts||0));
        if (!pEvts.length) return;
        let wScore = periodStartScore[p].w;
        let oScore = periodStartScore[p].o;
        const rows = pEvts.map(e => {
            const isGoal    = GOAL_STATS.has(e.stat) && isWaveEvent(e);
            const isOppGoal = OPP_GOAL.has(e.stat);
            if (isGoal)    wScore++;
            if (isOppGoal) oScore++;
            const pl = playerMap[e.playerId] || (playerStats[e.playerId]
                ? { name: playerStats[e.playerId].name, number: playerStats[e.playerId].number }
                : null);
            return {
                stat: e.stat,
                label: PBP_LABELS[e.stat] || e.stat,
                playerName: pl ? pl.name : null,
                capNumber: pl ? pl.number : (e.capNumber || (isOppGoal && e.oppNum ? e.oppNum : null)),
                isWave: !OPP_ONLY.has(e.stat) && !!e.playerId,
                isGoal: isGoal || isOppGoal,
                runningScore: `${wScore}–${oScore}`,
            };
        });
        pbpGroups.push({ period: p, rows });
    });
    if (pbpGroups.length) sections.push({ type: 'play_by_play', title: 'Play-by-Play', groups: pbpGroups });

    // ── 5. Team Comparison ────────────────────────────────────────────────────
    const wavePPConv = waveKOTotal > 0
        ? Math.round((waveManUpGoals + wavePenGoals) / waveKOTotal * 100) : null;
    const teamCompare = [
        { label: 'Goals / Attempts',
          waveVal: `${waveGoalsTotal}/${waveShotsTotal}`,
          oppVal:  `${oppGoalsTotal}/${oppShotsAgainst || '?'}`,
          wavePct: waveShotsTotal > 0 ? Math.round(waveGoalsTotal/waveShotsTotal*100) : null,
          oppPct:  oppShotsAgainst  > 0 ? Math.round(oppGoalsTotal/oppShotsAgainst*100) : null },
        ...(waveKOTotal > 0 ? [{ label: 'Powerplay Conv.',
          waveVal: `${waveManUpGoals+wavePenGoals}/${waveKOTotal}`,
          oppVal: '—', wavePct: wavePPConv, oppPct: null }] : []),
        { label: 'Saves',     waveVal: String(waveSavesTotal),  oppVal: '—', wavePct: null, oppPct: null },
        { label: 'Steals',    waveVal: String(waveStealsTotal), oppVal: '—', wavePct: null, oppPct: null },
        { label: 'Turnovers', waveVal: String(waveTOTotal),     oppVal: '—', wavePct: null, oppPct: null },
        { label: 'Blocks',    waveVal: String(waveBlocksTotal), oppVal: '—', wavePct: null, oppPct: null },
    ].filter(r => r.waveVal !== '0' && r.waveVal !== '0/0');
    if (teamCompare.length) sections.push({ type: 'team_compare', title: 'Team Comparison',
        rows: teamCompare, waveLabel: games[0]?.wave_team || 'WAVE', oppLabel: games[0]?.opponent || 'Opponent' });

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
    const situations = {
        man_up:   { label: 'Man Up',   shots: 0, goals: 0 },
        man_down: { label: 'Man Down', shots: 0, goals: 0 },
        even:     { label: 'Even',     shots: 0, goals: 0 },
    };
    allEvents.forEach(e => {
        if (!SHOT_STATS.has(e.stat) || !isWaveEvent(e)) return;
        const sit = e.situation || 'even';
        if (!situations[sit]) return;
        situations[sit].shots++;
        if (e.stat === 'goal') situations[sit].goals++;
    });
    const situArr = Object.entries(situations)
        .map(([k,v]) => ({ key:k, ...v, pct: v.shots > 0 ? Math.round(v.goals/v.shots*100) : null }))
        .filter(s => s.shots > 0);
    if (situArr.length) sections.push({ type: 'situations', title: 'Shooting by Situation', situations: situArr });

    // ── 8. 5M Penalty Breakdown ─────────────────────────────────────────────
    const totalFor     = penFor + penForMiss;
    const totalAgainst = penAgainst + penBlock;
    if (totalFor + totalAgainst > 0) {
        sections.push({ type: 'penalties', title: '5M Penalties',
            forGoals: penFor, forMiss: penForMiss, totalFor,
            againstGoals: penAgainst, againstBlock: penBlock, totalAgainst });
    }

    // ── 9. Shot Zone Heat Map Summary ───────────────────────────────────────
    const zones = {};
    let shotZoneTotal = 0;
    allEvents.forEach(e => {
        if (!SHOT_STATS.has(e.stat)) return;
        const z = e.shotLocation?.zone ?? e.shotZone;
        if (z == null) return;
        shotZoneTotal++;
        if (!zones[z]) zones[z] = { attempts: 0, goals: 0 };
        zones[z].attempts++;
        if (e.stat === 'goal') zones[z].goals++;
    });
    if (shotZoneTotal > 0) {
        const zoneArr = Object.entries(zones).map(([z,d]) => ({
            zone: parseInt(z), ...d, pct: Math.round(d.goals/d.attempts*100)
        })).sort((a,b) => b.attempts-a.attempts);
        sections.push({ type: 'shot_zones', title: 'Shot Zone Summary', zones: zoneArr, total: shotZoneTotal });
    }

    // ── 10. Shot Map (x/y coordinates) ───────────────────────────────────────
    // ── Field shot map (where on pool the shot was taken) ──────────────────
    const shotMapEvts = allEvents.filter(e =>
        ['goal','shot','penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat)
        && e.shotLocation?.x != null && e.shotLocation?.y != null
    );
    if (shotMapEvts.length) {
        sections.push({ type: 'shot_map', title: 'Shot Map (Field)',
            shots: shotMapEvts.map(e => ({
                x: e.shotLocation.x, y: e.shotLocation.y,
                isGoal: GOAL_STATS.has(e.stat),
                is5m:   ['penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat),
            }))
        });
    }

    // ── Net location maps (where ball crossed goal line) ─────────────────
    // WAVE attacking net: goals + shots where WAVE had netLocation recorded
    const waveNetEvts = allEvents.filter(e =>
        ['goal','shot','penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat)
        && isWaveEvent(e) && e.netLocation?.x != null && e.netLocation?.y != null
    );
    // Opponent attacking net: goals_against + shot_against with netLocation
    const oppNetEvts = allEvents.filter(e =>
        ['goals_against','penalty_5m_goal_against','shot_against'].includes(e.stat)
        && e.netLocation?.x != null && e.netLocation?.y != null
    );
    if (waveNetEvts.length || oppNetEvts.length) {
        sections.push({ type: 'net_map', title: 'Net Map',
            waveShots: waveNetEvts.map(e => ({
                x: e.netLocation.x, y: e.netLocation.y,
                isGoal: GOAL_STATS.has(e.stat),
            })),
            oppShots: oppNetEvts.map(e => ({
                x: e.netLocation.x, y: e.netLocation.y,
                isGoal: OPP_GOAL.has(e.stat),
            })),
            waveLabel: allEvents[0]?._game ? (allEvents.find(e=>e._game)?.wave_team || 'WAVE') : 'WAVE',
        });
    }

    // ── 11. Quarter-by-Quarter Shot + Net Maps ──────────────────────────────
    const QUARTER_PERIODS = ['1Q','2Q','3Q','4Q','OT','SO'];
    const quarterMapData = QUARTER_PERIODS.map(p => {
        const pEvts = allEvents.filter(e => e.period === p);

        // Field shots this period (WAVE only)
        const fieldShots = pEvts.filter(e =>
            ['goal','shot','penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat)
            && isWaveEvent(e)
            && e.shotLocation?.x != null && e.shotLocation?.y != null
        ).map(e => ({ x: e.shotLocation.x, y: e.shotLocation.y, isGoal: GOAL_STATS.has(e.stat), is5m: ['penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat) }));

        // WAVE attacking net this period
        const waveNet = pEvts.filter(e =>
            ['goal','shot','penalty_5m_goal_for','penalty_5m_miss_for','penalty_5m_block'].includes(e.stat)
            && isWaveEvent(e) && e.netLocation?.x != null && e.netLocation?.y != null
        ).map(e => ({ x: e.netLocation.x, y: e.netLocation.y, isGoal: GOAL_STATS.has(e.stat) }));

        // Opponent attacking net this period
        const oppNet = pEvts.filter(e =>
            ['goals_against','penalty_5m_goal_against','shot_against'].includes(e.stat)
            && e.netLocation?.x != null && e.netLocation?.y != null
        ).map(e => ({ x: e.netLocation.x, y: e.netLocation.y, isGoal: OPP_GOAL.has(e.stat) }));

        // Period stats summary
        const waveGoals = pEvts.filter(e => GOAL_STATS.has(e.stat) && isWaveEvent(e)).length;
        const waveShots = pEvts.filter(e => ['goal','shot'].includes(e.stat) && isWaveEvent(e)).length;
        const oppGoals  = pEvts.filter(e => OPP_GOAL.has(e.stat)).length;

        return { period: p, fieldShots, waveNet, oppNet, waveGoals, waveShots, oppGoals };
    }).filter(p => p.fieldShots.length > 0 || p.waveNet.length > 0 || p.oppNet.length > 0);

    if (quarterMapData.length > 0) {
        sections.push({ type: 'quarter_maps', title: 'Quarter-by-Quarter Maps', quarters: quarterMapData });
    }

    // ── 12. Game Flow ────────────────────────────────────────────────────────
    const flowPeriods = ['1Q','2Q','3Q','4Q','OT'];
    const gameFlow = gameResults.map(g => {
        const pMap = {};
        flowPeriods.forEach(p => {
            const pe = g.events.filter(e => e.period === p);
            pMap[p] = {
                wave: pe.filter(e => GOAL_STATS.has(e.stat) && isWaveEvent(e)).length,
                opp:  pe.filter(e => OPP_GOAL.has(e.stat)).length,
            };
        });
        return { opponent: g.opponent, date: g.date, waveScore: g.waveScore, oppScore: g.oppScore, result: g.result, periods: pMap };
    });
    const hasFlow = gameFlow.some(g => flowPeriods.some(p => g.periods[p].wave || g.periods[p].opp));
    if (hasFlow) sections.push({ type: 'game_flow', title: 'Game Flow', games: gameFlow, periods: flowPeriods });

    // ── 12. Top Performers ───────────────────────────────────────────────────
    const perfStats = [
        { key: 'assist',         label: 'Top Playmaker',    customIcon: '<svg viewBox="0 0 32 32" width="28" height="28"><circle cx="16" cy="16" r="15" fill="#003087" stroke="#FFC72C" stroke-width="1.5"/><text x="16" y="21" text-anchor="middle" font-family="Georgia,serif" font-weight="900" font-size="12" fill="#FFC72C">99</text></svg>' },
        { key: 'steal',          label: '🦊 Most Steals'  },
        { key: 'block',          label: '🛡 Most Blocks'  },
        { key: 'kickout_earned', label: '📣 Most KO Earned' },
    ];
    const goaliePerf = goaliePlayers
        .map(p => { const s=p.stats.save||0, g=p.goalsAgainst||0, sa=s+g; return {...p, svPct: sa>0?Math.round(s/sa*100):null}; })
        .filter(p => (p.stats.save || 0) > 0)
        .sort((a,b) => (b.svPct||0)-(a.svPct||0));

    const topPerformers = perfStats.map(({ key, label, customIcon }) => {
        const sorted = allPlayers.filter(p => p.stats[key] > 0).sort((a,b) => b.stats[key]-a.stats[key]);
        if (!sorted.length) return null;
        return { key, label, customIcon, player: sorted[0], value: sorted[0].stats[key] };
    }).filter(Boolean);

    // Best shooting % — use shotsAttempted (goals+shots+pen) as denominator, min 3 attempts
    const shootingPct = allPlayers
        .filter(p => !p.isGoalie && (p.shotsAttempted || 0) >= 3)
        .map(p => ({ ...p, pct: Math.round(p.stats.goal / p.shotsAttempted * 100) }))
        .sort((a,b) => b.pct - a.pct);
    if (shootingPct.length) {
        const best = shootingPct[0];
        topPerformers.unshift({ key: 'shoot_pct', label: '🎯 Best Shooting %', player: best,
            value: best.pct + '%', sub: `${best.stats.goal}G / ${best.shotsAttempted} att` });
    }
    if (goaliePerf.length) topPerformers.push({ key: 'save_pct', label: '🧤 Best Save %', player: goaliePerf[0], value: goaliePerf[0].svPct + '%' });
    if (topPerformers.length) sections.push({ type: 'top_performers', title: 'Top Performers', performers: topPerformers });

    // ── 13. Turnovers vs Steals ─────────────────────────────────────────────
    const tvsPlayers = allPlayers
        .map(p => ({ name: p.name, number: p.number, steals: p.stats.steal||0, turnovers: p.stats.turnover||0, net: (p.stats.steal||0)-(p.stats.turnover||0) }))
        .filter(p => p.steals > 0 || p.turnovers > 0)
        .sort((a,b) => b.net - a.net);
    if (waveStealsTotal + waveTOTotal > 0) {
        sections.push({ type: 'turnovers_steals', title: 'Turnovers vs Steals',
            teamSteals: waveStealsTotal, teamTurnovers: waveTOTotal,
            possessionNet: waveStealsTotal - waveTOTotal, players: tvsPlayers });
    }

    return { title, subtitle, scope, gameCount: games.length, sections, generatedAt: new Date().toISOString() };
}


// ── Utilities ────────────────────────────────────────────────────────────────
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Render report as HTML string ─────────────────────────────────────────────
function renderReportHTML(data) {
    if (!data || !data.sections) return '<p style="color:var(--muted)">No data.</p>';

    const ZONE_LABELS = {1:'Top-L',2:'Left',3:'Ctr-L',4:'Center',5:'Ctr-R',6:'Right',7:'Top-R'};
    const STAT_LABELS = {goal:'Goals',assist:'Assists',shot:'Shots',steal:'Steals',block:'Blocks',kickout_earned:'KO Earned',save:'Saves'};

    let html = '';
    if (data.subtitle)  html += `<div style="font-size:13px;color:var(--muted);margin-bottom:16px">${esc(data.subtitle)}</div>`;
    if (data.gameCount) html += `<div style="font-size:12px;color:var(--muted);margin-bottom:20px">${data.gameCount} game${data.gameCount!==1?'s':''} · Generated ${new Date(data.generatedAt||Date.now()).toLocaleDateString('en-CA')}</div>`;

    const sec_hdr = title => `<div style="font-family:var(--fd);font-size:18px;color:var(--navy);margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid var(--bdr)">${esc(title)}</div>`;
    const wrap    = inner  => `<div style="margin-bottom:24px">${inner}</div>`;
    const th      = (t,left) => `<th style="padding:6px 10px;text-align:${left?'left':'center'}">${t}</th>`;
    const td      = (v,opts='') => `<td style="padding:5px 10px;text-align:center;font-family:var(--fm)${opts}">${v}</td>`;
    const tdl     = (v,bold)   => `<td style="padding:5px 10px${bold?';font-weight:700':''}">${v}</td>`;
    const tblWrap = inner => `<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="background:var(--navy);color:#fff">`;
    const rowBg   = i => i%2===0 ? '#f8f9fc' : '#fff';

    function netSvg(shots, accentGoal, accentSave, label) {
        const W=300, H=160;
        const dots = shots.map(s => {
            const cx = (s.x/100*W).toFixed(1);
            const cy = (s.y/100*H).toFixed(1);
            const fill = s.isGoal ? accentGoal : accentSave;
            return `<circle cx="${cx}" cy="${cy}" r="7" fill="${fill}" fill-opacity="0.85" stroke="#fff" stroke-width="1.5"/>`;
        }).join('');
        return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;max-width:340px;border-radius:10px;display:block;border:2px solid var(--bdr)">
            <defs>
                <pattern id="ng${label}" x="0" y="0" width="15" height="15" patternUnits="userSpaceOnUse">
                    <path d="M15 0 L0 0 0 15" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="0.8"/>
                </pattern>
                <linearGradient id="nb${label}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#1e3a5f"/>
                    <stop offset="100%" stop-color="#0c1a2e"/>
                </linearGradient>
            </defs>
            <rect x="0" y="0" width="${W}" height="${H}" fill="#0ea5e9" fill-opacity="0.1" rx="8"/>
            <rect x="10" y="10" width="${W-20}" height="${H-20}" fill="url(#nb${label})" fill-opacity="0.9" rx="2"/>
            <rect x="10" y="10" width="${W-20}" height="${H-20}" fill="url(#ng${label})"/>
            <line x1="10" y1="10" x2="50" y2="35" stroke="rgba(255,255,255,0.1)" stroke-width="0.8"/>
            <line x1="${W-10}" y1="10" x2="${W-50}" y2="35" stroke="rgba(255,255,255,0.1)" stroke-width="0.8"/>
            <line x1="10" y1="${H-10}" x2="50" y2="${H-35}" stroke="rgba(255,255,255,0.1)" stroke-width="0.8"/>
            <line x1="${W-10}" y1="${H-10}" x2="${W-50}" y2="${H-35}" stroke="rgba(255,255,255,0.1)" stroke-width="0.8"/>
            <rect x="4" y="4" width="8" height="${H-8}" fill="white" rx="3"/>
            <rect x="${W-12}" y="4" width="8" height="${H-8}" fill="white" rx="3"/>
            <rect x="4" y="4" width="${W-8}" height="8" fill="white" rx="3"/>
            ${dots}
            ${shots.length===0 ? `<text x="${W/2}" y="${H/2+5}" text-anchor="middle" fill="rgba(255,255,255,0.3)" font-size="13" font-family="system-ui,sans-serif">No data</text>` : ''}
        </svg>`;
    }

    // Field shot map SVG helper — matches the existing shot_map renderer style
    function fieldSvg(shots, label) {
        const W=200, H=120;
        const dots = shots.map(s => {
            const cx=(s.x/100*W).toFixed(1), cy=(s.y/100*H).toFixed(1);
            const fill = s.isGoal ? '#22c55e' : '#f59e0b';
            const r    = s.is5m  ? 5 : 4;
            return `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}" fill-opacity="0.85" stroke="#fff" stroke-width="0.8"/>`;
        }).join('');
        return `<svg viewBox="0 0 ${W} ${H}" style="width:100%;border-radius:8px;display:block;background:#dbeafe;border:1px solid #93c5fd">
            <rect x="1" y="1" width="${W-2}" height="${H-2}" fill="none" stroke="#93c5fd" stroke-width="0.5"/>
            <line x1="${(W*0.82).toFixed(1)}" y1="1" x2="${(W*0.82).toFixed(1)}" y2="${H-1}" stroke="#93c5fd" stroke-width="0.8" stroke-dasharray="3,2"/>
            <line x1="${(W*0.65).toFixed(1)}" y1="1" x2="${(W*0.65).toFixed(1)}" y2="${H-1}" stroke="#bfdbfe" stroke-width="0.5" stroke-dasharray="3,2"/>
            <line x1="${(W*0.18).toFixed(1)}" y1="1" x2="${(W*0.18).toFixed(1)}" y2="${H-1}" stroke="#bfdbfe" stroke-width="0.5" stroke-dasharray="3,2"/>
            <line x1="${(W*0.35).toFixed(1)}" y1="1" x2="${(W*0.35).toFixed(1)}" y2="${H-1}" stroke="#93c5fd" stroke-width="0.8" stroke-dasharray="3,2"/>
            <line x1="${(W*0.5).toFixed(1)}"  y1="1" x2="${(W*0.5).toFixed(1)}"  y2="${H-1}" stroke="white" stroke-width="1" stroke-dasharray="4,3" opacity="0.6"/>
            <rect x="${W-3}" y="${(H*0.33).toFixed(1)}" width="3" height="${(H*0.34).toFixed(1)}" fill="#1d4ed8" rx="1"/>
            <rect x="0"     y="${(H*0.33).toFixed(1)}" width="3" height="${(H*0.34).toFixed(1)}" fill="#1d4ed8" rx="1"/>
            ${dots}
            ${shots.length===0 ? `<text x="${W/2}" y="${H/2+4}" text-anchor="middle" fill="#93c5fd" font-size="10" font-family="system-ui,sans-serif">No location data</text>` : ''}
        </svg>`;
    }

    data.sections.forEach(sec => {
        let inner = sec_hdr(sec.title);

        // ── results_table ────────────────────────────────────────────────────
        if (sec.type === 'results_table') {
            inner += `<div style="font-weight:700;font-size:15px;margin-bottom:8px;color:var(--navy)">${esc(sec.record)} &nbsp;·&nbsp; GF: ${sec.waveTotal} &nbsp; GA: ${sec.oppTotal}</div>`;
            inner += tblWrap() + th('Date',true) + th('Opponent',true) + th('Us') + th('Opp') + th('Result') + `</tr></thead><tbody>`;
            sec.rows.forEach((r,i) => {
                const rc = r.result==='W'?'#16a34a':r.result==='L'?'#ef4444':'#6b7280';
                inner += `<tr style="background:${rowBg(i)}">
                    ${tdl(new Date(r.date+'T12:00:00').toLocaleDateString('en-CA',{month:'short',day:'numeric'}))}
                    ${tdl(`<strong>${esc(r.opponent)}</strong>`)}
                    ${td(`<strong>${r.waveScore}</strong>`)}
                    ${td(`<strong>${r.oppScore}</strong>`)}
                    ${td(`<strong style="color:${rc}">${r.result}</strong>`)}
                </tr>`;
            });
            inner += `</tbody></table></div>`;
        }

        // ── box_score ────────────────────────────────────────────────────────
        else if (sec.type === 'box_score') {
            const ap = sec.periods.filter(p => sec.boxScore[p].waveGoals || sec.boxScore[p].oppGoals || sec.boxScore[p].waveShots);
            if (ap.length) {
                inner += tblWrap() + th('Team',true) + ap.map(p=>th(p)).join('') + th('TOT') + th('SH') + th('SV') + `</tr></thead><tbody>`;
                const wT = ap.reduce((s,p)=>s+sec.boxScore[p].waveGoals,0);
                const wS = ap.reduce((s,p)=>s+sec.boxScore[p].waveShots,0);
                const wV = ap.reduce((s,p)=>s+sec.boxScore[p].waveSaves,0);
                inner += `<tr style="background:#f0f7ff">${tdl('<strong>WAVE</strong>',true)}` + ap.map(p=>td(sec.boxScore[p].waveGoals||'—')).join('') + td(`<strong>${wT}</strong>`) + td(wS) + td(wV) + `</tr>`;
                const oT = ap.reduce((s,p)=>s+sec.boxScore[p].oppGoals,0);
                inner += `<tr>${tdl('Opponent')}` + ap.map(p=>td(sec.boxScore[p].oppGoals||'—')).join('') + td(`<strong>${oT}</strong>`) + td('—') + td('—') + `</tr>`;
                inner += `</tbody></table></div>`;
            }
        }

        // ── player_stats ─────────────────────────────────────────────────────
        else if (sec.type === 'player_stats') {
            const allP = [...(sec.fieldPlayers||[]),...(sec.goaliePlayers||[])].sort((a,b)=>parseInt(a.number)-parseInt(b.number));
            if (allP.length) {
                const nonGoalCols = sec.statKeys.filter(k => k!=='goal' && allP.some(p=>p.stats[k]>0));
                const hasGoals    = allP.some(p => p.stats.goal>0 || p.shotsAttempted>0);
                const HDR = {assist:'A',shot:'SH',steal:'ST',block:'BL',turnover:'TO',kickout:'KO',kickout_earned:'KOE',save:'SV'};
                inner += tblWrap() + th('#',true) + th('Name',true) + (hasGoals?th('G/Att'):'') + nonGoalCols.map(k=>th(HDR[k]||k)).join('') + `</tr></thead><tbody>`;
                allP.forEach((p,i) => {
                    const goals = p.stats.goal||0;
                    const att   = p.shotsAttempted||goals;
                    const pct   = att>0 ? Math.round(goals/att*100) : null;
                    const gAtt  = att>0 ? `${goals}/${att}${pct!==null?` <span style="font-size:10px;color:var(--muted)">(${pct}%)</span>`:''}` : '—';
                    inner += `<tr style="background:${rowBg(i)}">
                        <td style="padding:4px 8px;font-family:var(--fm);color:var(--muted)">${esc(p.number)}</td>
                        <td style="padding:4px 8px;font-weight:600">${esc(p.name)}${p.isGoalie?' 🧤':''}</td>`;
                    if (hasGoals) inner += `<td style="padding:4px 8px;text-align:center;font-family:var(--fm)${goals>0?';font-weight:700;color:var(--navy)':';color:#ccc'}">${gAtt}</td>`;
                    nonGoalCols.forEach(k => {
                        const v = p.stats[k]||0;
                        inner += `<td style="padding:4px 8px;text-align:center;font-family:var(--fm)${v>0?';font-weight:700;color:var(--navy)':';color:#ccc'}">${v||'—'}</td>`;
                    });
                    inner += `</tr>`;
                });
                inner += `</tbody></table></div>`;
            }
        }

        // ── leaderboards ─────────────────────────────────────────────────────
        else if (sec.type === 'leaderboards') {
            inner += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px">`;
            Object.entries(sec.leaders).forEach(([stat, players]) => {
                inner += `<div style="background:var(--bg);border-radius:8px;padding:10px">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-bottom:6px">${STAT_LABELS[stat]||stat}</div>`;
                players.forEach((p,i) => {
                    const medals = ['🥇','🥈','🥉'];
                    const att = stat==='goal' ? (p.shotsAttempted||p.stats[stat]) : null;
                    const pct = att>0 ? Math.round(p.stats[stat]/att*100) : null;
                    const val = stat==='goal' && att!=null ? `${p.stats[stat]}/${att}${pct!==null?' ('+pct+'%)':''}` : p.stats[stat];
                    inner += `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;font-size:12px">
                        <span>${medals[i]||'  '} ${esc(p.name)}</span>
                        <span style="font-family:var(--fm);font-weight:700;color:var(--navy)">${val}</span>
                    </div>`;
                });
                inner += `</div>`;
            });
            inner += `</div>`;
        }

        // ── play_by_play ──────────────────────────────────────────────────────
        else if (sec.type === 'play_by_play') {
            const SCORE_STATS = new Set(['goal','penalty_5m_goal_for','goals_against','penalty_5m_goal_against']);
            inner += `<div style="overflow-x:auto">`;
            sec.groups.forEach(g => {
                inner += `<div style="margin-bottom:16px">
                    <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);padding:5px 10px;background:var(--bg);border-radius:6px;margin-bottom:4px">${esc(g.period)}</div>
                    <table style="width:100%;border-collapse:collapse;font-size:12px">
                    <thead><tr style="background:var(--navy);color:#fff">
                        <th style="padding:5px 10px;text-align:left">Action</th>
                        <th style="padding:5px 10px;text-align:left">Player</th>
                        <th style="padding:5px 10px;text-align:right">Score</th>
                    </tr></thead><tbody>`;
                g.rows.forEach((row, i) => {
                    const isScore = SCORE_STATS.has(row.stat);
                    const bg = isScore ? (row.isWave?'rgba(0,48,135,0.07)':'rgba(239,68,68,0.06)') : rowBg(i);
                    const fw = isScore ? 'font-weight:700;' : '';
                    const dot = `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${row.isWave?'#003087':'#94a3b8'};margin-right:5px;vertical-align:middle"></span>`;
                    const player = row.capNumber ? `#${esc(row.capNumber)}${row.playerName?' '+esc(row.playerName):''}` : (row.playerName||'—');
                    inner += `<tr style="background:${bg}">
                        <td style="padding:4px 10px;${fw}">${dot}${esc(row.label)}</td>
                        <td style="padding:4px 10px;${fw}color:var(--muted);font-size:11px">${player}</td>
                        <td style="padding:4px 10px;text-align:right;font-family:var(--fm)${isScore?';font-weight:700;color:var(--navy)':';color:#ccc'}">${isScore?row.runningScore:'—'}</td>
                    </tr>`;
                });
                inner += `</tbody></table></div>`;
            });
            inner += `</div>`;
        }

        // ── team_compare ──────────────────────────────────────────────────────
        else if (sec.type === 'team_compare') {
            inner += `<div style="display:grid;gap:6px">`;
            sec.rows.forEach(row => {
                const wN = parseFloat(row.waveVal)||0, oN = parseFloat(row.oppVal)||0;
                const maxN = Math.max(row.wavePct??wN, row.oppPct??oN, 1);
                const wW = Math.max(Math.round(((row.wavePct??wN)/maxN)*100),2);
                const oW = Math.max(Math.round(((row.oppPct??oN)/maxN)*100),2);
                inner += `<div style="display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--bdr)">
                    <div style="text-align:right">
                        <div style="font-family:var(--fm);font-weight:700;font-size:13px;color:var(--navy)">${esc(row.waveVal)}</div>
                        <div style="height:6px;border-radius:3px;background:#003087;margin-top:3px;min-width:2px;width:${wW}%"></div>
                    </div>
                    <div style="font-size:11px;color:var(--muted);text-align:center;white-space:nowrap;padding:0 4px">${esc(row.label)}</div>
                    <div style="text-align:left">
                        <div style="font-family:var(--fm);font-weight:700;font-size:13px;color:#64748b">${esc(row.oppVal)}</div>
                        <div style="height:6px;border-radius:3px;background:#94a3b8;margin-top:3px;min-width:2px;width:${oW}%"></div>
                    </div>
                </div>`;
            });
            inner += `<div style="display:grid;grid-template-columns:1fr auto 1fr;gap:8px;margin-top:6px">
                <div style="text-align:right;font-size:11px;font-weight:700;color:#003087">${esc(sec.waveLabel)}</div>
                <div></div>
                <div style="text-align:left;font-size:11px;font-weight:700;color:#64748b">${esc(sec.oppLabel)}</div>
            </div></div>`;
        }

        // ── goalies ───────────────────────────────────────────────────────────
        else if (sec.type === 'goalies') {
            inner += tblWrap() + th('#',true) + th('Goalie',true) + th('SV') + th('GA') + th('SA') + th('SV%') + `</tr></thead><tbody>`;
            sec.goalieData.forEach((g,i) => {
                inner += `<tr style="background:${rowBg(i)}">
                    <td style="padding:5px 10px;font-family:var(--fm);color:var(--muted)">${esc(g.number)}</td>
                    <td style="padding:5px 10px;font-weight:600">${esc(g.name)}</td>
                    ${td(g.saves)} ${td(g.goalsAgainst)} ${td(g.shotsAgainst)}
                    ${td(g.svPct!==null?`<strong style="color:var(--navy)">${g.svPct}%</strong>`:'—')}
                </tr>`;
            });
            inner += `</tbody></table></div>`;
        }

        // ── situations ────────────────────────────────────────────────────────
        else if (sec.type === 'situations') {
            inner += `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">`;
            sec.situations.forEach(s => {
                const color = s.key==='man_up'?'#16a34a':s.key==='man_down'?'#ef4444':'#0ea5e9';
                inner += `<div style="background:var(--bg);border-radius:8px;padding:12px;text-align:center;border-top:3px solid ${color}">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-bottom:6px">${esc(s.label)}</div>
                    <div style="font-family:var(--fd);font-size:28px;color:${color}">${s.pct!==null?s.pct+'%':'—'}</div>
                    <div style="font-size:11px;color:var(--muted);margin-top:4px">${s.goals} goals / ${s.shots} shots</div>
                </div>`;
            });
            inner += `</div>`;
        }

        // ── penalties ─────────────────────────────────────────────────────────
        else if (sec.type === 'penalties') {
            const forPct  = sec.totalFor     > 0 ? Math.round(sec.forGoals/sec.totalFor*100)         : null;
            const agPct   = sec.totalAgainst > 0 ? Math.round(sec.againstGoals/sec.totalAgainst*100) : null;
            inner += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div style="background:#f0fff4;border-radius:8px;padding:14px;border-top:3px solid #16a34a">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#16a34a;text-transform:uppercase;margin-bottom:8px">WAVE FOR</div>
                    <div style="font-family:var(--fd);font-size:32px;color:#16a34a">${forPct!==null?forPct+'%':'—'}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px">${sec.forGoals} goals · ${sec.forMiss} miss · ${sec.totalFor} total</div>
                </div>
                <div style="background:#fff5f5;border-radius:8px;padding:14px;border-top:3px solid #ef4444">
                    <div style="font-size:10px;font-weight:700;letter-spacing:1px;color:#ef4444;text-transform:uppercase;margin-bottom:8px">AGAINST</div>
                    <div style="font-family:var(--fd);font-size:32px;color:#ef4444">${agPct!==null?agPct+'%':'—'}</div>
                    <div style="font-size:12px;color:var(--muted);margin-top:4px">${sec.againstGoals} goals · ${sec.againstBlock} blocked · ${sec.totalAgainst} total</div>
                </div>
            </div>`;
        }

        // ── shot_zones ────────────────────────────────────────────────────────
        else if (sec.type === 'shot_zones') {
            const maxAtt = Math.max(...sec.zones.map(z=>z.attempts));
            inner += `<div style="font-size:12px;color:var(--muted);margin-bottom:10px">${sec.total} total shots with location data</div>`;
            inner += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px">`;
            sec.zones.forEach(z => {
                const bg = `rgba(0,48,135,${0.05+z.attempts/maxAtt*0.3})`;
                inner += `<div style="background:${bg};border-radius:8px;padding:10px;text-align:center;border:1px solid var(--bdr)">
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Zone ${z.zone} · ${ZONE_LABELS[z.zone]||''}</div>
                    <div style="font-family:var(--fd);font-size:24px;color:var(--navy)">${z.attempts}</div>
                    <div style="font-size:11px;color:var(--muted)">${z.goals}G · ${z.pct}%</div>
                </div>`;
            });
            inner += `</div>`;
        }

        // ── shot_map ──────────────────────────────────────────────────────────
        else if (sec.type === 'shot_map') {
            const W=100, H=60;
            const dots = sec.shots.map(s => {
                const cx=(s.x/100*W).toFixed(1), cy=(s.y/100*H).toFixed(1);
                const fill = s.isGoal?'#22c55e':'#f59e0b';
                const r    = s.is5m?3.5:2.8;
                return `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}" fill-opacity="0.85" stroke="#fff" stroke-width="0.5"/>`;
            }).join('');
            inner += `<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
                <svg viewBox="0 0 ${W} ${H}" style="width:260px;max-width:100%;border:1px solid var(--bdr);border-radius:8px;background:#dbeafe">
                    <rect x="1" y="1" width="${W-2}" height="${H-2}" fill="none" stroke="#93c5fd" stroke-width="0.5"/>
                    <line x1="${W*0.82}" y1="1" x2="${W*0.82}" y2="${H-1}" stroke="#93c5fd" stroke-width="0.5" stroke-dasharray="2,2"/>
                    <line x1="${W*0.65}" y1="1" x2="${W*0.65}" y2="${H-1}" stroke="#bfdbfe" stroke-width="0.5" stroke-dasharray="2,2"/>
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

        // ── game_flow ─────────────────────────────────────────────────────────
        else if (sec.type === 'net_map') {
            const goalDot  = '#22c55e', saveDot = '#f59e0b';
            const oppGoalDot = '#ef4444', oppSaveDot = '#f59e0b';
            inner += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">`;
            inner += `<div>
                <div style="font-size:11px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px">WAVE Attacking</div>
                ${netSvg(sec.waveShots, goalDot, saveDot, 'w')}
                <div style="font-size:11px;color:var(--muted);margin-top:4px">${sec.waveShots.length} shots · ${sec.waveShots.filter(s=>s.isGoal).length} goals</div>
            </div>`;
            inner += `<div>
                <div style="font-size:11px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px">Opp Attacking</div>
                ${netSvg(sec.oppShots, oppGoalDot, oppSaveDot, 'o')}
                <div style="font-size:11px;color:var(--muted);margin-top:4px">${sec.oppShots.length} shots · ${sec.oppShots.filter(s=>s.isGoal).length} goals</div>
            </div>`;
            inner += `</div>
            <div style="margin-top:8px;display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--muted)">
                <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#22c55e;margin-right:4px"></span>Goal</span>
                <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#f59e0b;margin-right:4px"></span>Shot saved/missed</span>
                <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#ef4444;margin-right:4px"></span>Goal against</span>
            </div>`;
        }
        else if (sec.type === 'quarter_maps') {
            const PERIOD_LABEL = {'1Q':'1st Quarter','2Q':'2nd Quarter','3Q':'3rd Quarter','4Q':'4th Quarter','OT':'Overtime','SO':'Shoot-Out'};
            sec.quarters.forEach((q, qi) => {
                const hasField   = q.fieldShots.length > 0;
                const hasWaveNet = q.waveNet.length > 0;
                const hasOppNet  = q.oppNet.length > 0;
                const hasNet     = hasWaveNet || hasOppNet;
                // Period header
                inner += `<div style="margin-bottom:${qi < sec.quarters.length-1 ? '20px' : '0'}">`;
                inner += `<div style="font-size:13px;font-weight:700;color:var(--navy);padding:6px 10px;background:rgba(0,48,135,0.06);border-radius:8px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center">
                    <span>${PERIOD_LABEL[q.period] || q.period}</span>
                    <span style="font-size:11px;font-weight:400;color:var(--muted)">
                        WAVE: ${q.waveGoals}G / ${q.waveShots} shots &nbsp;·&nbsp; Opp: ${q.oppGoals}G
                    </span>
                </div>`;
                // Two columns: field map | net maps
                inner += `<div style="display:grid;grid-template-columns:${hasNet ? '1fr 1fr' : '1fr'};gap:12px;align-items:start">`;
                // Field map
                inner += `<div>
                    <div style="font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px">📍 Field — Shot Origins</div>
                    ${fieldSvg(q.fieldShots, 'f'+qi)}
                    <div style="font-size:10px;color:var(--muted);margin-top:3px">${q.fieldShots.length} shots · ${q.fieldShots.filter(s=>s.isGoal).length} goals plotted</div>
                </div>`;
                // Net maps (stacked: WAVE attacking on top, Opp attacking below)
                if (hasNet) {
                    inner += `<div style="display:flex;flex-direction:column;gap:10px">`;
                    inner += `<div>
                        <div style="font-size:10px;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px">🥅 WAVE Attacking Net</div>
                        ${netSvg(q.waveNet, '#22c55e', '#f59e0b', 'wn'+qi)}
                        <div style="font-size:10px;color:var(--muted);margin-top:2px">${q.waveNet.length} shots · ${q.waveNet.filter(s=>s.isGoal).length} goals</div>
                    </div>`;
                    inner += `<div>
                        <div style="font-size:10px;font-weight:700;color:#ef4444;text-transform:uppercase;letter-spacing:.7px;margin-bottom:4px">🥅 Opp Attacking Net</div>
                        ${netSvg(q.oppNet, '#ef4444', '#f59e0b', 'on'+qi)}
                        <div style="font-size:10px;color:var(--muted);margin-top:2px">${q.oppNet.length} shots · ${q.oppNet.filter(s=>s.isGoal).length} goals</div>
                    </div>`;
                    inner += `</div>`;
                }
                inner += `</div></div>`;
            });
            // Legend
            inner += `<div style="margin-top:10px;display:flex;gap:16px;flex-wrap:wrap;font-size:11px;color:var(--muted)">
                <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#22c55e;margin-right:4px"></span>Goal</span>
                <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#f59e0b;margin-right:4px"></span>Shot saved/missed</span>
                <span><span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#ef4444;margin-right:4px"></span>Goal against</span>
            </div>`;
        }
        else if (sec.type === 'game_flow') {
            const ap = sec.periods.filter(p => sec.games.some(g => g.periods[p].wave || g.periods[p].opp));
            sec.games.forEach(g => {
                const rc = g.result==='W'?'#16a34a':g.result==='L'?'#ef4444':'#6b7280';
                inner += `<div style="margin-bottom:14px">
                    <div style="font-size:12px;font-weight:700;margin-bottom:6px">${esc(g.opponent)} <span style="color:${rc};font-family:var(--fm)">${g.waveScore}–${g.oppScore}</span></div>
                    <div style="display:flex;gap:6px">`;
                ap.forEach(p => {
                    const w=g.periods[p].wave, o=g.periods[p].opp, mx=Math.max(w,o,1);
                    inner += `<div style="text-align:center;min-width:40px">
                        <div style="font-size:10px;color:var(--muted);margin-bottom:3px">${p}</div>
                        <div style="display:flex;gap:2px;justify-content:center;align-items:flex-end;height:36px">
                            <div style="width:10px;background:#003087;border-radius:2px 2px 0 0;height:${Math.round(w/mx*34)+2}px" title="Us: ${w}"></div>
                            <div style="width:10px;background:#ef4444;border-radius:2px 2px 0 0;height:${Math.round(o/mx*34)+2}px" title="Opp: ${o}"></div>
                        </div>
                        <div style="font-size:10px;font-family:var(--fm);margin-top:2px">${w}–${o}</div>
                    </div>`;
                });
                inner += `</div></div>`;
            });
        }

        // ── top_performers ────────────────────────────────────────────────────
        else if (sec.type === 'top_performers') {
            inner += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">`;
            sec.performers.forEach(p => {
                const iconHtml = p.customIcon ? `<div style="display:flex;justify-content:center;margin-bottom:6px">${p.customIcon}</div>` : '';
                inner += `<div style="background:var(--bg);border-radius:10px;padding:14px;border:1.5px solid var(--bdr);text-align:center">
                    ${iconHtml}
                    <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">${esc(p.label)}</div>
                    <div style="font-family:var(--fd);font-size:26px;color:var(--navy);margin-bottom:2px">${esc(String(p.value))}</div>
                    <div style="font-size:13px;font-weight:700">${esc(p.player.name)}</div>
                    <div style="font-size:11px;color:var(--muted)">Cap ${esc(p.player.number)}</div>
                    ${p.sub?`<div style="font-size:11px;color:var(--muted);margin-top:2px">${esc(p.sub)}</div>`:''}
                </div>`;
            });
            inner += `</div>`;
        }

        // ── turnovers_steals ──────────────────────────────────────────────────
        else if (sec.type === 'turnovers_steals') {
            const netColor = sec.possessionNet >= 0 ? '#16a34a' : '#ef4444';
            inner += `<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">
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
                    <div style="font-family:var(--fd);font-size:32px;color:${netColor}">${sec.possessionNet>=0?'+':''}${sec.possessionNet}</div>
                </div>
            </div>`;
            if (sec.players && sec.players.length) {
                inner += tblWrap() + th('#',true) + th('Player',true) + th('Steals') + th('TO') + th('Net') + `</tr></thead><tbody>`;
                sec.players.forEach((p,i) => {
                    const nc = p.net>0?'#16a34a':p.net<0?'#ef4444':'var(--txt)';
                    inner += `<tr style="background:${rowBg(i)}">
                        <td style="padding:5px 10px;font-family:var(--fm);color:var(--muted)">${esc(p.number)}</td>
                        <td style="padding:5px 10px;font-weight:600">${esc(p.name)}</td>
                        ${td(p.steals||'—')} ${td(p.turnovers||'—')}
                        ${td(`<strong style="color:${nc}">${p.net>0?'+':''}${p.net}</strong>`)}
                    </tr>`;
                });
                inner += `</tbody></table></div>`;
            }
        }

        html += wrap(inner);
    });

    return html;
}

// ── Print/PDF helper (admin only) ────────────────────────────────────────────
function printReport() {
    if (typeof _rptData === 'undefined' || !_rptData) {
        if (typeof toast === 'function') toast('⚠️ Generate a report first');
        return;
    }
    const title   = document.getElementById('rpt-title')?.value?.trim() || 'Report';
    const content = renderReportHTML(_rptData);
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head>
        <meta charset="utf-8"><title>${title}</title>
        <style>
            body { font-family: system-ui, sans-serif; font-size: 13px; color: #1a2235; padding: 24px; max-width: 900px; margin: 0 auto; }
            :root { --navy:#003087; --muted:#64748b; --bdr:#e2e8f0; --bg:#f8fafc; --fd:"Georgia",serif; --fm:monospace; --txt:#1a2235; }
            table { width:100%; border-collapse:collapse; font-size:12px; margin-bottom:8px; }
            th { background:#003087; color:#fff; padding:6px 10px; text-align:left; font-size:11px; }
            th:not(:first-child):not(:nth-child(2)) { text-align:center; }
            td { padding:5px 10px; border-bottom:1px solid #e2e8f0; }
            td:not(:first-child):not(:nth-child(2)) { text-align:center; }
            tr:nth-child(even) { background:#f8fafc; }
            @media print { body { padding:12px; } }
        </style></head><body>
        <h2 style="font-family:Georgia,serif;color:#003087;margin-bottom:4px">${title}</h2>
        ${content}
        <script>window.onload = function(){ window.print(); }<\/script>
    </body></html>`);
    win.document.close();
}

// ── Tracker adapter ──────────────────────────────────────────────────────────
// Converts a local tracker game object into the canonical crunchReportData input shape.

function trackerGameToAdminShape(game, db) {
    const homeTeam = db.teams.find(t => t.id === game.homeTeamId);
    const awayTeam = db.teams.find(t => t.id === game.awayTeamId);
    // Derive players from event playerIds (teamId may be stale across games)
    const eventPlayerIds = new Set((game.events || []).map(e => e.playerId).filter(Boolean));
    const playerById = {};
    db.players.forEach(p => { playerById[p.id] = p; });
    const fromEvents = [...eventPlayerIds].map(pid => playerById[pid]).filter(Boolean);
    // Fall back to teamId filter for new games with no events yet
    const players = fromEvents.length > 0
        ? fromEvents
        : db.players.filter(p => p.teamId === game.homeTeamId);
    return {
        game_key:        game.id,
        game_date:       game.date?.slice(0, 10) || '',
        opponent:        awayTeam?.name || 'Opponent',
        tournament:      game.tournament || '',
        wave_team:       homeTeam?.name || 'WAVE',
        wave_score:      game.homeScore ?? 0,
        opp_score:       game.awayScore ?? 0,
        players,
        official_events: game.events || [],
    };
}

function buildTrackerReport(games, db, title, subtitle, scope) {
    const adminGames = games.map(g => trackerGameToAdminShape(g, db));
    return crunchReportData(adminGames, title, subtitle || '', scope);
}

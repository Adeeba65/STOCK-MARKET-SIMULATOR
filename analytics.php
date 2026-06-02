<?php
// ============================================================
// ANALYTICS endpoints   (?a=analytics.<method>)
//   - sector_dist : PIE chart   (user's portfolio by sector)
//   - top_movers  : BAR chart   (top gainers / losers)
//   - volume      : BAR chart   (daily trade volume)
//   - networth    : LINE chart  (user's net worth over time, derived)
//   - dashboard   : aggregated KPIs
// ============================================================

function ep_dashboard(): void {
    $pdo = db();

    // System-wide KPIs (no auth needed - shown to guests too)
    $kpis = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM stocks WHERE is_active = 1)              AS active_stocks,
            (SELECT COUNT(*) FROM users WHERE role = 'user')               AS total_users,
            (SELECT COUNT(*) FROM transactions)                            AS total_trades,
            (SELECT COALESCE(SUM(total), 0) FROM transactions)             AS total_volume,
            (SELECT COALESCE(AVG(change_pct), 0) FROM (
                SELECT ((current_price - prev_close)/NULLIF(prev_close,0))*100 AS change_pct
                FROM stocks WHERE is_active = 1) x)                        AS avg_market_change_pct
         ")->fetch();

    // Top gainers / losers (BAR)
    $movers = $pdo->query(
        "SELECT symbol, company_name, current_price, prev_close,
                ((current_price - prev_close)/NULLIF(prev_close,0))*100 AS change_pct
         FROM stocks
         WHERE is_active = 1
         ORDER BY change_pct DESC")->fetchAll();

    $top5  = array_slice($movers, 0, 5);
    $bot5  = array_slice($movers, max(0, count($movers) - 5));
    $bot5  = array_reverse($bot5);

    // Sector market cap (BAR/PIE) - using current_price * total_shares as proxy
    $secCap = $pdo->query(
        "SELECT sec.name AS sector, sec.color_hex,
                COUNT(s.id)                                  AS stock_count,
                COALESCE(SUM(s.current_price * s.total_shares), 0) AS market_cap,
                AVG(((s.current_price - s.prev_close)/NULLIF(s.prev_close,0))*100) AS avg_change_pct
         FROM sectors sec
         LEFT JOIN stocks s ON s.sector_id = sec.id AND s.is_active = 1
         GROUP BY sec.id, sec.name, sec.color_hex
         ORDER BY market_cap DESC")->fetchAll();

    ok([
        'kpis'        => $kpis,
        'top_gainers' => $top5,
        'top_losers'  => $bot5,
        'sectors'     => $secCap,
    ]);
}

// PIE: user's holdings split by sector
function ep_sector_dist(): void {
    $u = current_user(true);
    $sql = "SELECT sec.name AS sector, sec.color_hex,
                   SUM(h.quantity * s.current_price) AS value
            FROM holdings h
            JOIN stocks s    ON s.id = h.stock_id
            JOIN sectors sec ON sec.id = s.sector_id
            WHERE h.user_id = ?
            GROUP BY sec.id, sec.name, sec.color_hex
            ORDER BY value DESC";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    ok($st->fetchAll());
}

// BAR: daily trade volume (last N days, system-wide)
function ep_volume(): void {
    $days = max(1, min(30, (int)($_GET['days'] ?? 7)));
    $sql = "SELECT DATE(created_at)                              AS d,
                   COUNT(*)                                      AS trades,
                   SUM(CASE WHEN tx_type='BUY'  THEN total ELSE 0 END) AS buy_volume,
                   SUM(CASE WHEN tx_type='SELL' THEN total ELSE 0 END) AS sell_volume
            FROM transactions
            WHERE created_at >= NOW() - INTERVAL $days DAY
            GROUP BY DATE(created_at)
            ORDER BY d ASC";
    ok(db()->query($sql)->fetchAll());
}

// BAR: top gainers / losers
function ep_top_movers(): void {
    $limit = max(1, min(10, (int)($_GET['limit'] ?? 5)));
    $stocks = db()->query(
        "SELECT s.symbol, s.company_name, s.current_price, s.prev_close,
                ((s.current_price - s.prev_close)/NULLIF(s.prev_close,0))*100 AS change_pct
         FROM stocks s
         WHERE s.is_active = 1
         ORDER BY change_pct DESC")->fetchAll();
    ok([
        'gainers' => array_slice($stocks, 0, $limit),
        'losers'  => array_slice(array_reverse($stocks), 0, $limit),
    ]);
}

// LINE: user net worth approximation over the same time window as price_history
function ep_networth_series(): void {
    $u     = current_user(true);
    $hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));
    $sql = "SELECT DATE_FORMAT(ph.recorded_at, '%Y-%m-%d %H:00:00') AS ts,
                   SUM(h.quantity * ph.price) + ? AS net_worth
            FROM holdings h
            JOIN (
                SELECT stock_id, recorded_at, price,
                       ROW_NUMBER() OVER (PARTITION BY stock_id, DATE_FORMAT(recorded_at,'%Y-%m-%d %H')
                                          ORDER BY recorded_at DESC) AS rn
                FROM price_history
                WHERE recorded_at >= NOW() - INTERVAL $hours HOUR
            ) ph ON ph.stock_id = h.stock_id AND ph.rn = 1
            WHERE h.user_id = ?
            GROUP BY ts
            ORDER BY ts ASC";
    $st = db()->prepare($sql);
    $st->execute([(float)$u['cash_balance'], $u['id']]);
    ok($st->fetchAll());
}

// BAR: trade activity per stock (admin overview / dashboard)
function ep_stock_activity(): void {
    $sql = "SELECT s.symbol, s.company_name,
                   COUNT(t.id)                                 AS trades,
                   COALESCE(SUM(t.quantity), 0)                AS total_shares,
                   COALESCE(SUM(t.total), 0)                   AS total_value
            FROM stocks s
            LEFT JOIN transactions t ON t.stock_id = s.id
            GROUP BY s.id, s.symbol, s.company_name
            HAVING trades > 0
            ORDER BY total_value DESC
            LIMIT 10";
    ok(db()->query($sql)->fetchAll());
}

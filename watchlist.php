<?php
// ============================================================
// WATCHLIST endpoints   (?a=watchlist.<method>)
// ============================================================

function ep_list(): void {
    $u = current_user(true);
    $sql = "SELECT w.id AS watch_id, w.added_at,
                   s.id, s.symbol, s.company_name, s.current_price, s.prev_close,
                   sec.name AS sector,
                   (s.current_price - s.prev_close)                             AS change_abs,
                   CASE WHEN s.prev_close = 0 THEN 0
                        ELSE ((s.current_price - s.prev_close)/s.prev_close)*100
                   END                                                          AS change_pct
            FROM watchlist w
            JOIN stocks  s   ON s.id = w.stock_id
            JOIN sectors sec ON sec.id = s.sector_id
            WHERE w.user_id = ?
            ORDER BY w.added_at DESC";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    ok($st->fetchAll());
}

function ep_add(): void {
    require_method('POST');
    $u   = current_user(true);
    $sid = (int)(json_input()['stock_id'] ?? 0);
    if ($sid <= 0) fail('stock_id required');
    try {
        db()->prepare('INSERT INTO watchlist (user_id, stock_id) VALUES (?, ?)')
            ->execute([$u['id'], $sid]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) ok(['already' => true]); // already in list
        throw $e;
    }
    ok(['added' => $sid]);
}

function ep_remove(): void {
    require_method('POST');
    $u   = current_user(true);
    $sid = (int)(json_input()['stock_id'] ?? $_GET['stock_id'] ?? 0);
    if ($sid <= 0) fail('stock_id required');
    db()->prepare('DELETE FROM watchlist WHERE user_id = ? AND stock_id = ?')
        ->execute([$u['id'], $sid]);
    ok(['removed' => $sid]);
}

<?php
// ============================================================
// LEADERBOARD   (?a=leaderboard.<method>) - public
// ============================================================

function ep_top(): void {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $sql = "SELECT user_id, full_name, cash_balance, holdings_value, net_worth,
                   RANK() OVER (ORDER BY net_worth DESC) AS rank_pos
            FROM v_leaderboard
            ORDER BY net_worth DESC
            LIMIT $limit";
    ok(db()->query($sql)->fetchAll());
}

function ep_me(): void {
    $u = current_user(true);
    $sql = "SELECT * FROM (
                SELECT user_id, full_name, cash_balance, holdings_value, net_worth,
                       RANK() OVER (ORDER BY net_worth DESC) AS rank_pos
                FROM v_leaderboard) r
            WHERE user_id = ?";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    $row = $st->fetch();
    if (!$row) ok(['user_id' => $u['id'], 'net_worth' => (float)$u['cash_balance'],
                   'rank_pos' => null, 'cash_balance' => (float)$u['cash_balance'],
                   'holdings_value' => 0.0]);
    ok($row);
}

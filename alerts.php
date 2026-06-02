<?php
// ============================================================
// PRICE ALERTS endpoints   (?a=alerts.<method>)
// ============================================================

function ep_list(): void {
    $u = current_user(true);
    $sql = "SELECT a.id, a.threshold_price, a.direction, a.is_triggered,
                   a.created_at, a.triggered_at,
                   s.id AS stock_id, s.symbol, s.company_name, s.current_price
            FROM price_alerts a
            JOIN stocks s ON s.id = a.stock_id
            WHERE a.user_id = ?
            ORDER BY a.is_triggered ASC, a.created_at DESC";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    ok($st->fetchAll());
}

function ep_create(): void {
    require_method('POST');
    $u   = current_user(true);
    $in  = json_input();
    $sid = (int)($in['stock_id'] ?? 0);
    $thr = (float)($in['threshold_price'] ?? 0);
    $dir = strtoupper((string)($in['direction'] ?? ''));
    if ($sid <= 0 || $thr <= 0)                       fail('stock_id and threshold_price required');
    if (!in_array($dir, ['ABOVE','BELOW'], true))     fail("direction must be 'ABOVE' or 'BELOW'");

    db()->prepare(
        'INSERT INTO price_alerts (user_id, stock_id, threshold_price, direction) VALUES (?, ?, ?, ?)')
        ->execute([$u['id'], $sid, $thr, $dir]);
    ok(['id' => (int)db()->lastInsertId()]);
}

function ep_delete(): void {
    require_method('POST');
    $u  = current_user(true);
    $id = (int)(json_input()['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) fail('id required');
    db()->prepare('DELETE FROM price_alerts WHERE id = ? AND user_id = ?')
        ->execute([$id, $u['id']]);
    ok(['deleted' => $id]);
}

// Triggered alerts that haven't been acknowledged (the UI polls this)
function ep_pending(): void {
    $u = current_user(true);
    $sql = "SELECT a.id, a.threshold_price, a.direction, a.triggered_at,
                   s.symbol, s.company_name, s.current_price
            FROM price_alerts a
            JOIN stocks s ON s.id = a.stock_id
            WHERE a.user_id = ? AND a.is_triggered = 1
            ORDER BY a.triggered_at DESC";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    ok($st->fetchAll());
}

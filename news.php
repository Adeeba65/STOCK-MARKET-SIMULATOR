<?php
// ============================================================
// NEWS endpoints   (?a=news.<method>)
// ============================================================

function ep_list(): void {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
    $sql = "SELECT n.id, n.headline, n.body, n.impact_pct, n.published_at,
                   n.stock_id, s.symbol, s.company_name
            FROM news n
            LEFT JOIN stocks s ON s.id = n.stock_id
            ORDER BY n.published_at DESC
            LIMIT $limit";
    ok(db()->query($sql)->fetchAll());
}

function ep_create(): void {
    require_admin();
    require_method('POST');
    $in = json_input();
    $head = trim((string)($in['headline'] ?? ''));
    $body = trim((string)($in['body'] ?? ''));
    $sid  = $in['stock_id'] ?? null;
    $imp  = (float)($in['impact_pct'] ?? 0);
    if ($head === '' || $body === '') fail('headline and body required');
    $sid = ($sid === null || $sid === '' || (int)$sid <= 0) ? null : (int)$sid;

    db()->prepare(
        'INSERT INTO news (stock_id, headline, body, impact_pct) VALUES (?, ?, ?, ?)')
        ->execute([$sid, $head, $body, $imp]);
    ok(['id' => (int)db()->lastInsertId()]);
}

function ep_delete(): void {
    require_admin();
    require_method('POST');
    $id = (int)(json_input()['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) fail('id required');
    db()->prepare('DELETE FROM news WHERE id = ?')->execute([$id]);
    ok(['deleted' => $id]);
}

<?php
// ============================================================
// ADMIN endpoints   (?a=admin.<method>)
// User management (admin only)
// ============================================================

function ep_users(): void {
    require_admin();
    $sql = "SELECT u.id, u.full_name, u.email, u.role, u.cash_balance, u.created_at,
                   COALESCE(SUM(t.total), 0)            AS lifetime_volume,
                   COUNT(DISTINCT t.id)                 AS trade_count,
                   COALESCE(SUM(h.quantity * s.current_price), 0) AS holdings_value
            FROM users u
            LEFT JOIN transactions t ON t.user_id = u.id
            LEFT JOIN holdings    h ON h.user_id = u.id
            LEFT JOIN stocks      s ON s.id      = h.stock_id
            GROUP BY u.id, u.full_name, u.email, u.role, u.cash_balance, u.created_at
            ORDER BY u.created_at DESC";
    ok(db()->query($sql)->fetchAll());
}

function ep_set_role(): void {
    require_admin();
    require_method('POST');
    $in   = json_input();
    $id   = (int)($in['id'] ?? 0);
    $role = (string)($in['role'] ?? '');
    if ($id <= 0)                                       fail('id required');
    if (!in_array($role, ['admin','user'], true))       fail("role must be 'admin' or 'user'");
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    ok(['updated' => $id, 'role' => $role]);
}

function ep_reset_cash(): void {
    require_admin();
    require_method('POST');
    $in  = json_input();
    $id  = (int)($in['id'] ?? 0);
    $amt = (float)($in['amount'] ?? 100000);
    if ($id <= 0) fail('id required');
    db()->prepare('UPDATE users SET cash_balance = ? WHERE id = ?')->execute([$amt, $id]);
    ok(['updated' => $id, 'cash_balance' => $amt]);
}

function ep_delete_user(): void {
    require_admin();
    require_method('POST');
    $id = (int)(json_input()['id'] ?? 0);
    if ($id <= 0) fail('id required');
    // Don't allow deleting yourself
    $me = current_user(true);
    if ((int)$me['id'] === $id) fail('Cannot delete your own admin account');
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    ok(['deleted' => $id]);
}

function ep_db_stats(): void {
    require_admin();
    $tables = ['users','stocks','sectors','price_history','holdings',
               'transactions','watchlist','price_alerts','news','sessions'];
    $rows = [];
    foreach ($tables as $t) {
        $c = (int)db()->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        $rows[] = ['table' => $t, 'rows' => $c];
    }
    ok($rows);
}

<?php
// ============================================================
// STOCKS endpoints   (?a=stocks.<method>)
// ============================================================

function ep_list(): void {
    $q      = trim((string)($_GET['q'] ?? ''));
    $sector = (int)($_GET['sector'] ?? 0);

    $where = ['s.is_active = 1'];
    $args  = [];
    if ($q !== '') {
        $where[] = '(s.symbol LIKE ? OR s.company_name LIKE ?)';
        $args[]  = "%$q%"; $args[] = "%$q%";
    }
    if ($sector > 0) {
        $where[] = 's.sector_id = ?';
        $args[]  = $sector;
    }
    $wh = implode(' AND ', $where);

    $sql = "SELECT s.id, s.symbol, s.company_name, s.sector_id, sec.name AS sector,
                   sec.color_hex AS sector_color,
                   s.current_price, s.prev_close, s.day_high, s.day_low,
                   s.volatility, s.total_shares,
                   (s.current_price - s.prev_close) AS change_abs,
                   CASE WHEN s.prev_close = 0 THEN 0
                        ELSE ((s.current_price - s.prev_close) / s.prev_close) * 100
                   END AS change_pct
            FROM stocks s
            JOIN sectors sec ON sec.id = s.sector_id
            WHERE $wh
            ORDER BY s.symbol";
    $st = db()->prepare($sql);
    $st->execute($args);
    ok($st->fetchAll());
}

function ep_get(): void {
    $id     = (int)($_GET['id'] ?? 0);
    $symbol = strtoupper(trim((string)($_GET['symbol'] ?? '')));
    if ($id <= 0 && $symbol === '') fail('id or symbol required');

    $sql = "SELECT s.*, sec.name AS sector, sec.color_hex AS sector_color
            FROM stocks s JOIN sectors sec ON sec.id = s.sector_id
            WHERE " . ($id > 0 ? 's.id = ?' : 's.symbol = ?') . ' LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute([$id > 0 ? $id : $symbol]);
    $row = $st->fetch();
    if (!$row) fail('Stock not found', 404);
    ok($row);
}

function ep_history(): void {
    $id    = (int)($_GET['id'] ?? 0);
    $hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));
    if ($id <= 0) fail('id required');

    $sql = 'SELECT recorded_at AS ts, price
            FROM price_history
            WHERE stock_id = ? AND recorded_at >= NOW() - INTERVAL ? HOUR
            ORDER BY recorded_at ASC';
    $st = db()->prepare($sql);
    $st->execute([$id, $hours]);
    ok($st->fetchAll());
}

// Trigger a price tick (admin only — or also exposed to logged-in users so
// the simulator can be advanced from the C# client without admin auth).
function ep_tick(): void {
    current_user(true); // any logged-in user can request a tick
    $id = (int)($_GET['id'] ?? 0);
    $changed = simulate_tick($id > 0 ? $id : null);
    ok($changed);
}

function ep_sectors(): void {
    ok(db()->query('SELECT id, name, color_hex FROM sectors ORDER BY name')->fetchAll());
}

// ----- ADMIN CRUD -----
function ep_create(): void {
    require_admin();
    require_method('POST');
    $in = json_input();
    $sym  = strtoupper(trim((string)($in['symbol'] ?? '')));
    $name = trim((string)($in['company_name'] ?? ''));
    $sec  = (int)($in['sector_id'] ?? 0);
    $px   = (float)($in['current_price'] ?? 0);
    $vol  = (float)($in['volatility'] ?? 0.02);
    if ($sym === '' || strlen($sym) > 16) fail('Invalid symbol');
    if ($name === '') fail('Company name required');
    if ($sec <= 0)    fail('sector_id required');
    if ($px <= 0)     fail('current_price must be > 0');

    $st = db()->prepare(
        'INSERT INTO stocks (symbol, company_name, sector_id, current_price,
                              prev_close, day_high, day_low, volatility)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    try {
        $st->execute([$sym, $name, $sec, $px, $px, $px, $px, $vol]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) fail('Symbol already exists', 409);
        throw $e;
    }
    ok(['id' => (int)db()->lastInsertId()]);
}

function ep_update(): void {
    require_admin();
    require_method('POST');
    $in   = json_input();
    $id   = (int)($in['id'] ?? 0);
    if ($id <= 0) fail('id required');
    $name = trim((string)($in['company_name'] ?? ''));
    $sec  = (int)($in['sector_id'] ?? 0);
    $px   = (float)($in['current_price'] ?? 0);
    $vol  = (float)($in['volatility'] ?? 0);
    $act  = isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1;
    if ($name === '' || $sec <= 0 || $px <= 0) fail('Invalid input');

    db()->prepare(
        'UPDATE stocks SET company_name = ?, sector_id = ?, current_price = ?,
                            volatility = ?, is_active = ? WHERE id = ?')
        ->execute([$name, $sec, $px, $vol, $act, $id]);
    ok(['updated' => $id]);
}

function ep_delete(): void {
    require_admin();
    require_method('POST');
    $id = (int)(json_input()['id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) fail('id required');
    db()->prepare('DELETE FROM stocks WHERE id = ?')->execute([$id]);
    ok(['deleted' => $id]);
}

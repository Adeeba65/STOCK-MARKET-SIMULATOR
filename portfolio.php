<?php
// ============================================================
// PORTFOLIO endpoints   (?a=portfolio.<method>)
// ============================================================

function ep_holdings(): void {
    $u = current_user(true);
    $sql = "SELECT v.*, sec.name AS sector, sec.color_hex AS sector_color
            FROM v_portfolio_value v
            JOIN stocks s   ON s.id = v.stock_id
            JOIN sectors sec ON sec.id = s.sector_id
            WHERE v.user_id = ?
            ORDER BY v.market_value DESC";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    ok($st->fetchAll());
}

function ep_summary(): void {
    $u = current_user(true);
    $pdo = db();

    $vRow = $pdo->prepare(
        "SELECT COALESCE(SUM(market_value), 0)    AS holdings_value,
                COALESCE(SUM(unrealized_pl), 0)   AS unrealized_pl,
                COUNT(*)                          AS distinct_stocks
         FROM v_portfolio_value WHERE user_id = ?");
    $vRow->execute([$u['id']]);
    $v = $vRow->fetch();

    $txRow = $pdo->prepare(
        "SELECT COUNT(*) AS total_trades,
                SUM(CASE WHEN tx_type='BUY'  THEN 1 ELSE 0 END) AS buy_count,
                SUM(CASE WHEN tx_type='SELL' THEN 1 ELSE 0 END) AS sell_count,
                COALESCE(SUM(fee), 0)                           AS fees_paid
         FROM transactions WHERE user_id = ?");
    $txRow->execute([$u['id']]);
    $tx = $txRow->fetch();

    ok([
        'cash_balance'    => (float)$u['cash_balance'],
        'holdings_value'  => (float)$v['holdings_value'],
        'net_worth'       => (float)$u['cash_balance'] + (float)$v['holdings_value'],
        'unrealized_pl'   => (float)$v['unrealized_pl'],
        'distinct_stocks' => (int)$v['distinct_stocks'],
        'total_trades'    => (int)$tx['total_trades'],
        'buy_count'       => (int)$tx['buy_count'],
        'sell_count'      => (int)$tx['sell_count'],
        'fees_paid'       => (float)$tx['fees_paid'],
    ]);
}

function ep_buy(): void {
    require_method('POST');
    $u = current_user(true);
    $in = json_input();
    $sid = (int)($in['stock_id'] ?? 0);
    $qty = (int)($in['quantity'] ?? 0);
    if ($sid <= 0 || $qty <= 0) fail('stock_id and quantity (>0) required');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM stocks WHERE id = ? AND is_active = 1 FOR UPDATE');
        $s->execute([$sid]);
        $stock = $s->fetch();
        if (!$stock) { $pdo->rollBack(); fail('Stock not found or inactive', 404); }

        $price = (float)$stock['current_price'];
        $cost  = $price * $qty;
        $fee   = round($cost * TRADING_FEE_PCT, 4);
        $total = $cost + $fee;

        $usr = $pdo->prepare('SELECT cash_balance FROM users WHERE id = ? FOR UPDATE');
        $usr->execute([$u['id']]);
        $cash = (float)$usr->fetchColumn();
        if ($cash < $total) { $pdo->rollBack(); fail('Insufficient cash. Need ' . number_format($total, 2)); }

        $pdo->prepare('UPDATE users SET cash_balance = cash_balance - ? WHERE id = ?')
            ->execute([$total, $u['id']]);

        // Upsert holding (recompute avg_buy_price weighted)
        $h = $pdo->prepare('SELECT quantity, avg_buy_price FROM holdings WHERE user_id = ? AND stock_id = ?');
        $h->execute([$u['id'], $sid]);
        $row = $h->fetch();
        if ($row) {
            $newQty = (int)$row['quantity'] + $qty;
            $newAvg = (((float)$row['avg_buy_price'] * (int)$row['quantity']) + ($price * $qty)) / $newQty;
            $pdo->prepare('UPDATE holdings SET quantity = ?, avg_buy_price = ? WHERE user_id = ? AND stock_id = ?')
                ->execute([$newQty, $newAvg, $u['id'], $sid]);
        } else {
            $pdo->prepare('INSERT INTO holdings (user_id, stock_id, quantity, avg_buy_price) VALUES (?, ?, ?, ?)')
                ->execute([$u['id'], $sid, $qty, $price]);
        }

        $pdo->prepare(
            "INSERT INTO transactions (user_id, stock_id, tx_type, quantity, unit_price, total, fee)
             VALUES (?, ?, 'BUY', ?, ?, ?, ?)")
            ->execute([$u['id'], $sid, $qty, $price, $cost, $fee]);

        $pdo->commit();
        ok(['symbol' => $stock['symbol'], 'qty' => $qty, 'price' => $price,
            'cost' => $cost, 'fee' => $fee, 'total' => $total]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ep_sell(): void {
    require_method('POST');
    $u  = current_user(true);
    $in = json_input();
    $sid = (int)($in['stock_id'] ?? 0);
    $qty = (int)($in['quantity'] ?? 0);
    if ($sid <= 0 || $qty <= 0) fail('stock_id and quantity (>0) required');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM stocks WHERE id = ? FOR UPDATE');
        $s->execute([$sid]);
        $stock = $s->fetch();
        if (!$stock) { $pdo->rollBack(); fail('Stock not found', 404); }

        $h = $pdo->prepare('SELECT quantity, avg_buy_price FROM holdings WHERE user_id = ? AND stock_id = ? FOR UPDATE');
        $h->execute([$u['id'], $sid]);
        $row = $h->fetch();
        if (!$row || (int)$row['quantity'] < $qty) { $pdo->rollBack(); fail('Not enough shares to sell'); }

        $price    = (float)$stock['current_price'];
        $proceeds = $price * $qty;
        $fee      = round($proceeds * TRADING_FEE_PCT, 4);
        $net      = $proceeds - $fee;

        $newQty = (int)$row['quantity'] - $qty;
        if ($newQty === 0) {
            $pdo->prepare('DELETE FROM holdings WHERE user_id = ? AND stock_id = ?')
                ->execute([$u['id'], $sid]);
        } else {
            $pdo->prepare('UPDATE holdings SET quantity = ? WHERE user_id = ? AND stock_id = ?')
                ->execute([$newQty, $u['id'], $sid]);
        }

        $pdo->prepare('UPDATE users SET cash_balance = cash_balance + ? WHERE id = ?')
            ->execute([$net, $u['id']]);

        $pdo->prepare(
            "INSERT INTO transactions (user_id, stock_id, tx_type, quantity, unit_price, total, fee)
             VALUES (?, ?, 'SELL', ?, ?, ?, ?)")
            ->execute([$u['id'], $sid, $qty, $price, $proceeds, $fee]);

        $pdo->commit();
        ok(['symbol' => $stock['symbol'], 'qty' => $qty, 'price' => $price,
            'proceeds' => $proceeds, 'fee' => $fee, 'net' => $net]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ep_transactions(): void {
    $u     = current_user(true);
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
    $sql = "SELECT t.id, t.tx_type, t.quantity, t.unit_price, t.total, t.fee, t.created_at,
                   s.symbol, s.company_name
            FROM transactions t
            JOIN stocks s ON s.id = t.stock_id
            WHERE t.user_id = ?
            ORDER BY t.created_at DESC
            LIMIT $limit";
    $st = db()->prepare($sql);
    $st->execute([$u['id']]);
    ok($st->fetchAll());
}

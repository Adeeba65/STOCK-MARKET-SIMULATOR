<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

function json_input(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return $_POST ?: [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function ok($data = null, array $extra = []): void {
    echo json_encode(array_merge(['ok' => true, 'data' => $data], $extra),
        JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function require_method(string $m): void {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($m)) {
        fail("Method not allowed (expected $m)", 405);
    }
}

function bearer_token(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $all = getallheaders();
        foreach ($all as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    if (preg_match('/Bearer\s+([A-Za-z0-9]+)/', $h, $m)) return $m[1];
    return null;
}

function current_user(bool $required = true): ?array {
    $t = bearer_token();
    if (!$t) {
        if ($required) fail('Unauthorized: no token', 401);
        return null;
    }
    $row = db()->prepare(
        'SELECT u.* FROM sessions s JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > NOW() LIMIT 1');
    $row->execute([$t]);
    $u = $row->fetch();
    if (!$u) {
        if ($required) fail('Unauthorized: invalid or expired token', 401);
        return null;
    }
    return $u;
}

function require_admin(): array {
    $u = current_user(true);
    if (($u['role'] ?? '') !== 'admin') fail('Admin role required', 403);
    return $u;
}

function rand_token(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function valid_email(string $e): bool {
    return (bool) filter_var($e, FILTER_VALIDATE_EMAIL);
}

// Tick simulator: random walk + news impact, also records price_history,
// updates day_high/day_low, and fires any due alerts.
function simulate_tick(?int $stockId = null): array {
    $pdo = db();
    $where = $stockId ? 'WHERE id = :sid AND is_active = 1' : 'WHERE is_active = 1';
    $stmt = $pdo->prepare("SELECT * FROM stocks $where");
    $stmt->execute($stockId ? [':sid' => $stockId] : []);
    $stocks = $stmt->fetchAll();

    $newsImpact = $pdo->query(
        "SELECT stock_id, SUM(impact_pct) AS imp FROM news
         WHERE published_at > NOW() - INTERVAL 2 HOUR AND stock_id IS NOT NULL
         GROUP BY stock_id")->fetchAll();
    $impactMap = [];
    foreach ($newsImpact as $r) $impactMap[(int)$r['stock_id']] = (float)$r['imp'];

    $updated = [];
    foreach ($stocks as $s) {
        $vol  = (float) $s['volatility'];
        $bias = ($impactMap[(int)$s['id']] ?? 0) / 100.0 * 0.05; // news nudge
        // Box-Muller for nice gaussian-ish step
        $u1 = mt_rand(1, 1000000) / 1000000;
        $u2 = mt_rand(1, 1000000) / 1000000;
        $g  = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        $step = $g * $vol + $bias;
        $newPrice = max(0.5, ((float)$s['current_price']) * (1 + $step));
        $newPrice = round($newPrice, 4);

        $high = max((float)$s['day_high'], $newPrice);
        $low  = min((float)$s['day_low'],  $newPrice);

        $up = $pdo->prepare(
            'UPDATE stocks SET current_price = ?, day_high = ?, day_low = ? WHERE id = ?');
        $up->execute([$newPrice, $high, $low, $s['id']]);

        $ins = $pdo->prepare(
            'INSERT INTO price_history (stock_id, price) VALUES (?, ?)');
        $ins->execute([$s['id'], $newPrice]);

        // Fire alerts
        $aq = $pdo->prepare(
            "UPDATE price_alerts SET is_triggered = 1, triggered_at = NOW()
             WHERE stock_id = ? AND is_triggered = 0
               AND ((direction = 'ABOVE' AND ? >= threshold_price)
                 OR (direction = 'BELOW' AND ? <= threshold_price))");
        $aq->execute([$s['id'], $newPrice, $newPrice]);

        $updated[] = ['id' => (int)$s['id'], 'symbol' => $s['symbol'], 'price' => $newPrice];
    }
    return $updated;
}

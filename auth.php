<?php
// ============================================================
// AUTH endpoints   (?a=auth.<method>)
// ============================================================

function ep_register(): void {
    require_method('POST');
    $in = json_input();
    $name = trim((string)($in['full_name'] ?? ''));
    $em   = strtolower(trim((string)($in['email'] ?? '')));
    $pw   = (string)($in['password'] ?? '');

    if ($name === '' || mb_strlen($name) < 2)     fail('Full name required (min 2 chars)');
    if (!valid_email($em))                         fail('Invalid email');
    if (strlen($pw) < 6)                           fail('Password must be at least 6 characters');

    $pdo = db();
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$em]);
    if ($exists->fetch()) fail('Email already registered', 409);

    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $ins = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, role, cash_balance)
         VALUES (?, ?, ?, 'user', 100000.00)");
    $ins->execute([$name, $em, $hash]);
    $uid = (int)$pdo->lastInsertId();

    $token = rand_token();
    $exp   = (new DateTime("+" . SESSION_TTL_HOURS . " hours"))->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$uid, $token, $exp]);

    ok([
        'token' => $token,
        'user'  => ['id' => $uid, 'full_name' => $name, 'email' => $em,
                    'role' => 'user', 'cash_balance' => 100000.00],
    ]);
}

function ep_login(): void {
    require_method('POST');
    $in = json_input();
    $em = strtolower(trim((string)($in['email'] ?? '')));
    $pw = (string)($in['password'] ?? '');
    if ($em === '' || $pw === '') fail('Email and password required');

    $pdo = db();
    $q = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $q->execute([$em]);
    $u = $q->fetch();
    if (!$u || !password_verify($pw, $u['password_hash'])) fail('Invalid credentials', 401);

    // Clean expired sessions for this user
    $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND expires_at < NOW()')
        ->execute([$u['id']]);

    $token = rand_token();
    $exp   = (new DateTime("+" . SESSION_TTL_HOURS . " hours"))->format('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO sessions (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$u['id'], $token, $exp]);

    unset($u['password_hash']);
    ok(['token' => $token, 'user' => $u]);
}

function ep_me(): void {
    $u = current_user(true);
    unset($u['password_hash']);
    ok($u);
}

function ep_logout(): void {
    require_method('POST');
    $t = bearer_token();
    if ($t) db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$t]);
    ok(['message' => 'Logged out']);
}

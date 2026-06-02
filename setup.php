<?php
// One-shot bootstrap: open in browser ONCE after importing schema.sql.
// Creates admin + demo user with properly hashed passwords.
require_once __DIR__ . '/helpers.php';

$pdo = db();

$users = [
    ['System Admin',   'admin@stocksim.local', 'admin123', 'admin', 100000.00],
    ['Demo Investor',  'demo@stocksim.local',  'user1234', 'user',  100000.00],
];

$report = [];
$ins = $pdo->prepare(
    'INSERT INTO users (full_name, email, password_hash, role, cash_balance)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)');

foreach ($users as [$name, $email, $pass, $role, $cash]) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $ins->execute([$name, $email, $hash, $role, $cash]);
    $report[] = "$role :: $email / $pass";
}

ok([
    'message' => 'Bootstrap users seeded. You can now log in from the C# client.',
    'users'   => $report,
]);

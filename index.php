<?php
// =============================================================================
// API ROUTER
// URL pattern:  http://localhost/stockapi/?a=<module>.<action>
//   e.g.        http://localhost/stockapi/?a=auth.login
//               http://localhost/stockapi/?a=stocks.list
// =============================================================================
require_once __DIR__ . '/helpers.php';

$action = $_GET['a'] ?? '';
if ($action === '') ok([
    'name'    => 'Stock Market Simulator API',
    'version' => '1.0',
    'hint'    => 'Use ?a=<module>.<action>, e.g. ?a=stocks.list',
]);

[$module, $method] = array_pad(explode('.', $action, 2), 2, '');
if (!preg_match('/^[a-z]+$/', $module) || !preg_match('/^[a-z_]+$/', $method)) {
    fail('Bad action format. Use module.method', 400);
}

$file = __DIR__ . "/endpoints/$module.php";
if (!is_file($file)) fail("Unknown module: $module", 404);
require_once $file;

$fn = "ep_{$method}";
if (!function_exists($fn)) fail("Unknown action: $module.$method", 404);

try {
    $fn();
} catch (PDOException $e) {
    fail('DB error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}

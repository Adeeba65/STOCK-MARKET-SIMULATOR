<?php
// =============================================================================
// Stock Market Simulator - API Configuration
// =============================================================================
declare(strict_types=1);

// DB connection (XAMPP defaults: user=root, no password)
const DB_HOST = '127.0.0.1';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'stock_simulator';
const DB_PORT = 3306;

// Trading fee (% per transaction)
const TRADING_FEE_PCT = 0.001; // 0.1 %

// Auth
const SESSION_TTL_HOURS = 24;

// CORS - allow the C# client (no origin) and localhost dev pages
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Errors must NOT be printed (would break JSON). Log instead.
ini_set('display_errors', '0');
error_reporting(E_ALL);

<?php
declare(strict_types=1);

$GLOBALS['pms_config'] = require __DIR__ . '/../config.php';

function cfg(string $key)
{
    return $GLOBALS['pms_config'][$key];
}

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/* ---------- Database (MySQL / MariaDB) ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c   = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'], $c['name'], $c['charset']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        error_log('PMS database connection failed: ' . $ex->getMessage());
        http_response_code(500);
        exit('Database connection failed. Check the settings in config.php and run install.php.');
    }
    return $pdo;
}

/* ---------- Helpers ---------- */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function fmt_num($n): string
{
    return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
}

function fmt_date(?string $d): string
{
    return $d ? date('d M Y', strtotime($d)) : '—';
}

function flash(?string $message = null, string $type = 'success')
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function status_badge(string $status): string
{
    $map = [
        'draft'           => ['off',  'Draft'],
        'open'            => ['on',   'Open'],
        'closed'          => ['dark', 'Closed'],
        'pending_self'    => ['warn', 'Awaiting self-review'],
        'pending_manager' => ['info', 'Awaiting manager review'],
        'completed'       => ['ok',   'Completed'],
    ];
    [$cls, $label] = $map[$status] ?? ['off', $status];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back, refresh the page and try again.');
    }
}

/* ---------- Auth ---------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function home_url(): string
{
    $u = current_user();
    return ($u && $u['role'] === 'admin') ? 'dashboard.php' : 'my_appraisals.php';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have access to this page.');
    }
    return $u;
}

function require_admin(): array
{
    return require_role('admin');
}

require __DIR__ . '/layout.php';
require __DIR__ . '/scorecard.php';

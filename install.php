<?php
// One-time installer: creates the MySQL database (if allowed), tables and the first admin.
// Run in a browser or with:  php install.php
// DELETE THIS FILE after a successful install.
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$c = cfg('db');
if (!preg_match('/^[A-Za-z0-9_]+$/', $c['name'])) {
    exit("Invalid database name in config.php.\n");
}

// 1. Create the database if the MySQL user is allowed to
try {
    $root = new PDO(sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], $c['port'], $c['charset']),
        $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec('CREATE DATABASE IF NOT EXISTS `' . $c['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "Database `{$c['name']}` is ready.\n";
} catch (PDOException $ex) {
    echo "Could not connect or create the database (" . $ex->getMessage() . ").\n";
    echo "Create it manually and check the credentials in config.php, then run this again.\n";
    exit(1);
}

$pdo = db();

// 2. Refuse to run on an installed system
try {
    $n = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($n > 0) {
        exit("Already installed ($n user(s) exist). Delete install.php.\n");
    }
} catch (PDOException $ex) {
    // users table does not exist yet: continue
}

// 3. Create tables
$sql = file_get_contents(__DIR__ . '/database/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}
echo "Tables created.\n";

// 4. First admin
$a = cfg('default_admin');
$pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)')
    ->execute([$a['name'], $a['email'], password_hash($a['password'], PASSWORD_DEFAULT), 'admin']);
echo "Admin created: {$a['email']} / {$a['password']}\n";
echo "Done. Sign in, change the password, and DELETE install.php.\n";

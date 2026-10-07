<?php

/**
 * User Management Utility for i18n-api
 */

$dbFile = __DIR__ . '/database.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

// Ensure users table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin', 'user')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

function ensureUserRoleColumn() {
    global $pdo;
    $columns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if ($column['name'] === 'role') {
            return;
        }
    }

    // Existing users previously had full access, so keep that behavior after migration.
    $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin' CHECK(role IN ('admin', 'user'))");
}

ensureUserRoleColumn();

function normalizeRole($role) {
    $role = strtolower(trim($role ?: 'user'));
    if (!in_array($role, ['admin', 'user'], true)) {
        echo "❌ Error: role must be 'admin' or 'user'.\n";
        exit(1);
    }

    return $role;
}

function createUser($username, $password, $role = 'user') {
    global $pdo;
    $username = trim($username);
    if ($username === '' || strlen($username) > 100 || strlen($password) < 8) {
        echo "❌ Error: username is required and password must have at least 8 characters.\n";
        return;
    }
    $role = normalizeRole($role);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (:user, :pass, :role)");
        $stmt->execute(['user' => $username, 'pass' => $hash, 'role' => $role]);
        echo "✅ User '$username' created successfully with role '$role'.\n";
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

function listUsers() {
    global $pdo;
    $stmt = $pdo->query("SELECT id, username, role, created_at FROM users");
    echo "ID | Username | Role  | Created At\n";
    echo "-----------------------------------\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "{$row['id']}  | {$row['username']} | {$row['role']} | {$row['created_at']}\n";
    }
}

function setUserRole($username, $role) {
    global $pdo;
    $role = normalizeRole($role);
    $currentRoleStmt = $pdo->prepare("SELECT role FROM users WHERE username = :user");
    $currentRoleStmt->execute(['user' => $username]);
    $currentRole = $currentRoleStmt->fetchColumn();
    if ($currentRole === false) {
        echo "❌ Error: user '$username' not found.\n";
        return;
    }
    if ($currentRole === 'admin' && $role !== 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
        echo "❌ Error: at least one administrator must remain.\n";
        return;
    }
    $stmt = $pdo->prepare("UPDATE users SET role = :role WHERE username = :user");
    $stmt->execute(['role' => $role, 'user' => $username]);

    if ($stmt->rowCount() === 0) {
        echo "❌ Error: user '$username' not found.\n";
        return;
    }

    echo "✅ User '$username' role updated to '$role'.\n";
}

function deleteUser($username) {
    global $pdo;
    $roleStmt = $pdo->prepare("SELECT role FROM users WHERE username = :user");
    $roleStmt->execute(['user' => $username]);
    $role = $roleStmt->fetchColumn();
    if ($role === false) {
        echo "❌ Error: user '$username' not found.\n";
        return;
    }
    if ($role === 'admin' && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() <= 1) {
        echo "❌ Error: at least one administrator must remain.\n";
        return;
    }
    $stmt = $pdo->prepare("DELETE FROM users WHERE username = :user");
    $stmt->execute(['user' => $username]);
    echo "✅ User '$username' deleted.\n";
}

$action = $argv[1] ?? '--help';

switch ($action) {
    case '--create':
    case '-c':
        if (!isset($argv[2], $argv[3])) {
            echo "Usage: php user.php --create <username> <password> [admin|user]\n";
            exit;
        }
        createUser($argv[2], $argv[3], $argv[4] ?? 'user');
        break;
    case '--list':
    case '-l':
        listUsers();
        break;
    case '--delete':
    case '-d':
        if (!isset($argv[2])) {
            echo "Usage: php user.php --delete <username>\n";
            exit;
        }
        deleteUser($argv[2]);
        break;
    case '--role':
    case '-r':
        if (!isset($argv[2], $argv[3])) {
            echo "Usage: php user.php --role <username> <admin|user>\n";
            exit;
        }
        setUserRole($argv[2], $argv[3]);
        break;
    default:
        echo "Usage:\n";
        echo "  php user.php --create <user> <pass> [admin|user]  (-c)\n";
        echo "  php user.php --list                                (-l)\n";
        echo "  php user.php --role <user> <admin|user>            (-r)\n";
        echo "  php user.php --delete <user>                       (-d)\n";
        break;
}

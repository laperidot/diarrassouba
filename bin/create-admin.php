<?php
declare(strict_types=1);

// Usage : php bin/create-admin.php "Nom complet" email@exemple.com
// Le mot de passe est demandé de façon masquée (ou lu depuis ADMIN_PASSWORD).

if (PHP_SAPI !== 'cli') {
    exit("CLI uniquement\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Db;

[$name, $email] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage : php bin/create-admin.php \"Nom complet\" email@exemple.com\n");
    exit(1);
}

$password = getenv('ADMIN_PASSWORD') ?: '';
if ($password === '') {
    echo 'Mot de passe (10 caractères min., lettres + chiffre) : ';
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $password = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
    }
    echo "\n";
}
if ($problems = Auth::passwordProblems($password)) {
    fwrite(STDERR, 'Mot de passe refusé : ' . implode(', ', $problems) . "\n");
    exit(1);
}

$db = Db::get();
$email = mb_strtolower($email);
if ($db->value('SELECT id FROM users WHERE email = ?', [$email])) {
    fwrite(STDERR, "Un utilisateur existe déjà avec cet e-mail.\n");
    exit(1);
}
$id = $db->insert('users', [
    'name' => $name, 'email' => $email, 'password_hash' => Auth::hash($password),
    'role_code' => 'admin', 'is_active' => 1, 'must_change_password' => 0,
    'created_at' => Db::now(), 'updated_at' => Db::now(),
]);
App\Core\Audit::log('user.created_cli', 'user', $id, ['role' => 'admin'], $id);
echo "Administrateur créé (#$id).\n";

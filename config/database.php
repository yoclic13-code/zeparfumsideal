<?php
/**
 * Connexion PDO sécurisée à la base MySQL.
 *
 * Valeurs par défaut = environnement local (WAMP).
 * En production (o2switch), créez config/database.local.php (non versionné) :
 * copiez database.local.example.php et renseignez vos identifiants.
 */

$localConfig = __DIR__ . '/database.local.php';
if (is_file($localConfig)) {
    require $localConfig;
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'trouvezeparfums');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_CHARSET', 'utf8mb4');
}

/**
 * Retourne une instance PDO partagée (singleton).
 */
function getDb(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die('Erreur de connexion à la base de données. Vérifiez config/database.local.php (prod) ou config/database.php (local).');
        }
    }

    return $pdo;
}

<?php
require_once __DIR__ . '/../config/database.php';

$db = getDb();

$db->exec("CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$stmt = $db->prepare(
    "INSERT INTO site_settings (setting_key, setting_value) VALUES ('hero_overlay_opacity', '0.55')
     ON DUPLICATE KEY UPDATE setting_key = setting_key"
);
$stmt->execute();

$defaults = [
    'referral_enabled' => '0',
    'referral_discount' => '50',
];

foreach ($defaults as $key => $value) {
    $stmt = $db->prepare(
        "INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_key = setting_key"
    );
    $stmt->execute(['key' => $key, 'value' => $value]);
}

echo "Migration OK\n";

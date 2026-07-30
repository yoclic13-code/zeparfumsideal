-- =========================================================
-- Trouvez Votre Parfum - Base de données
-- Import via phpMyAdmin (onglet "Importer")
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Table: perfumes
-- ---------------------------------------------------------
DROP TABLE IF EXISTS `perfume_tags`;
DROP TABLE IF EXISTS `quiz_results`;
DROP TABLE IF EXISTS `quiz_sessions`;
DROP TABLE IF EXISTS `perfumes`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `site_settings`;
DROP TABLE IF EXISTS `api_logs`;

CREATE TABLE `perfumes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_id` VARCHAR(100) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `brand` VARCHAR(150) DEFAULT NULL,
  `gender` ENUM('homme','femme','mixte') DEFAULT 'mixte',
  `release_year` SMALLINT UNSIGNED DEFAULT NULL,
  `top_notes` TEXT DEFAULT NULL COMMENT 'JSON array',
  `middle_notes` TEXT DEFAULT NULL COMMENT 'JSON array',
  `base_notes` TEXT DEFAULT NULL COMMENT 'JSON array',
  `accords` TEXT DEFAULT NULL COMMENT 'JSON array',
  `rating` DECIMAL(3,2) DEFAULT NULL,
  `votes` INT UNSIGNED DEFAULT 0,
  `longevity` VARCHAR(50) DEFAULT NULL,
  `sillage` VARCHAR(50) DEFAULT NULL,
  `image_url` VARCHAR(500) DEFAULT NULL,
  `source_url` VARCHAR(500) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `price` DECIMAL(10,2) DEFAULT NULL,
  `product_url` VARCHAR(500) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_id` (`api_id`),
  KEY `idx_brand` (`brand`),
  KEY `idx_gender` (`gender`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table: tags
-- ---------------------------------------------------------
CREATE TABLE `tags` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `label_fr` VARCHAR(150) NOT NULL,
  `type` VARCHAR(50) DEFAULT 'general' COMMENT 'family, mood, gender, occasion, season, intensity, note, general',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table: perfume_tags
-- ---------------------------------------------------------
CREATE TABLE `perfume_tags` (
  `perfume_id` INT UNSIGNED NOT NULL,
  `tag_id` INT UNSIGNED NOT NULL,
  `weight` DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`perfume_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`),
  CONSTRAINT `fk_pt_perfume` FOREIGN KEY (`perfume_id`) REFERENCES `perfumes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_tag` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table: quiz_sessions
-- ---------------------------------------------------------
CREATE TABLE `quiz_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token` VARCHAR(64) NOT NULL,
  `path_type` ENUM('quiz','favorite') NOT NULL DEFAULT 'quiz',
  `selected_perfume_id` INT UNSIGNED DEFAULT NULL,
  `answers_json` TEXT DEFAULT NULL,
  `result_perfume_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table: quiz_results
-- ---------------------------------------------------------
CREATE TABLE `quiz_results` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` INT UNSIGNED NOT NULL,
  `perfume_id` INT UNSIGNED NOT NULL,
  `score` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `position` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `reason_text` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session` (`session_id`),
  CONSTRAINT `fk_qr_session` FOREIGN KEY (`session_id`) REFERENCES `quiz_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table: api_logs
-- ---------------------------------------------------------
CREATE TABLE `api_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint` VARCHAR(255) NOT NULL,
  `query` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ok',
  `response_preview` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------
-- Table: site_settings
-- ---------------------------------------------------------
CREATE TABLE `site_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('hero_overlay_opacity', '0.55'),
('referral_enabled', '0'),
('referral_discount', '50');

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- Données de départ : tags principaux
-- =========================================================
INSERT INTO `tags` (`name`, `label_fr`, `type`) VALUES
('frais', 'Frais', 'family'),
('sucre', 'Sucré', 'family'),
('oriental', 'Oriental', 'family'),
('boise', 'Boisé', 'family'),
('floral', 'Floral', 'family'),
('musque', 'Musqué', 'family'),
('gourmand', 'Gourmand', 'family'),
('propre', 'Propre et frais', 'mood'),
('elegant', 'Élégant', 'mood'),
('seducteur', 'Séducteur', 'mood'),
('doux', 'Doux et rassurant', 'mood'),
('luxueux', 'Luxueux', 'mood'),
('original', 'Original', 'mood'),
('discret', 'Discret', 'intensity'),
('equilibre', 'Équilibré', 'intensity'),
('puissant', 'Puissant', 'intensity'),
('tres_intense', 'Très intense', 'intensity'),
('ete', 'Été', 'season'),
('hiver', 'Hiver', 'season'),
('printemps', 'Printemps', 'season'),
('automne', 'Automne', 'season'),
('toute_annee', 'Toute l\'année', 'season'),
('quotidien', 'Tous les jours', 'occasion'),
('travail', 'Travail', 'occasion'),
('soiree', 'Soirée', 'occasion'),
('mariage', 'Mariage / événement', 'occasion'),
('cadeau', 'Cadeau', 'occasion'),
('homme', 'Homme', 'gender'),
('femme', 'Femme', 'gender'),
('mixte', 'Mixte', 'gender'),
-- notes olfactives
('citrus', 'Agrumes', 'note'),
('bergamot', 'Bergamote', 'note'),
('aquatic', 'Aquatique', 'note'),
('vanilla', 'Vanille', 'note'),
('caramel', 'Caramel', 'note'),
('tonka', 'Fève tonka', 'note'),
('amber', 'Ambre', 'note'),
('oud', 'Oud', 'note'),
('spice', 'Épices', 'note'),
('incense', 'Encens', 'note'),
('woody', 'Boisé', 'note'),
('cedar', 'Cèdre', 'note'),
('sandalwood', 'Bois de santal', 'note'),
('vetiver', 'Vétiver', 'note'),
('rose', 'Rose', 'note'),
('jasmine', 'Jasmin', 'note'),
('white_floral', 'Fleurs blanches', 'note'),
('musk', 'Musc', 'note'),
('clean', 'Propre', 'note'),
('soft', 'Doux', 'note'),
('chocolate', 'Chocolat', 'note'),
('almond', 'Amande', 'note'),
('chaud', 'Chaud', 'note'),
('feminin', 'Féminin', 'note'),
('masculin', 'Masculin', 'note'),
('cremeux', 'Crémeux', 'note'),
('patchouli', 'Patchouli', 'note');

-- =========================================================
-- Quelques parfums d'exemple (pour tester sans API)
-- =========================================================
INSERT INTO `perfumes`
(`api_id`, `name`, `brand`, `gender`, `release_year`, `top_notes`, `middle_notes`, `base_notes`, `accords`, `rating`, `votes`, `longevity`, `sillage`, `image_url`, `source_url`, `description`, `price`, `product_url`, `is_active`)
VALUES
('demo-1', 'Sauvage', 'Dior', 'homme', 2015,
 '["Bergamote","Poivre de Sichuan"]', '["Lavande","Géranium"]', '["Ambroxan","Cèdre"]',
 '["frais","boisé","aromatique"]', 4.30, 152000, 'longue', 'forte',
 NULL, NULL,
 'Un sillage frais et puissant, inspiré des grands espaces.', 95.00, NULL, 1),
('demo-2', 'Baccarat Rouge 540', 'Maison Francis Kurkdjian', 'mixte', 2015,
 '["Safran","Jasmin"]', '["Fleur d\'oranger","Ambre"]', '["Bois de santal","Cèdre"]',
 '["oriental","ambré","boisé"]', 4.50, 98000, 'très longue', 'énorme',
 NULL, NULL,
 'Un chef d\'œuvre ambré et luxueux, signature inoubliable.', 320.00, NULL, 1),
('demo-3', 'Bleu de Chanel', 'Chanel', 'homme', 2010,
 '["Citron","Menthe"]', '["Gingembre","Muscade"]', '["Bois de santal","Encens"]',
 '["boisé","aromatique","frais"]', 4.40, 130000, 'longue', 'modérée',
 NULL, NULL,
 'Élégance boisée et fraîche pour l\'homme moderne.', 110.00, NULL, 1),
('demo-4', 'Good Girl', 'Carolina Herrera', 'femme', 2016,
 '["Amande","Café"]', '["Jasmin","Tubéreuse"]', '["Fève tonka","Cacao"]',
 '["gourmand","floral","sucré"]', 4.20, 76000, 'longue', 'forte',
 NULL, NULL,
 'Séduction sucrée et florale, contrastes assumés.', 105.00, NULL, 1),
('demo-5', 'Aventus', 'Creed', 'homme', 2010,
 '["Ananas","Bergamote"]', '["Bouleau","Jasmin"]', '["Musc","Bois de chêne"]',
 '["fruité","boisé","musqué"]', 4.40, 145000, 'longue', 'forte',
 NULL, NULL,
 'Puissance et réussite, un fruité-boisé iconique.', 280.00, NULL, 1),
('demo-6', 'Light Blue', 'Dolce & Gabbana', 'femme', 2001,
 '["Citron","Pomme"]', '["Bambou","Jasmin"]', '["Musc blanc","Cèdre"]',
 '["frais","fruité","aquatique"]', 4.00, 89000, 'modérée', 'modérée',
 NULL, NULL,
 'Fraîcheur méditerranéenne, légère et solaire.', 75.00, NULL, 1);

-- =========================================================
-- Mapping des notes -> tags avec poids (perfume_tags)
-- Générés automatiquement selon les notes ci-dessus
-- =========================================================
-- Sauvage (id 1): frais, boisé, propre, quotidien, homme
INSERT INTO `perfume_tags` (`perfume_id`, `tag_id`, `weight`)
SELECT p.id, t.id, w FROM perfumes p, tags t,
(SELECT 'frais' n, 2.0 w UNION SELECT 'boise',1.5 UNION SELECT 'propre',1.2 UNION SELECT 'quotidien',1.5 UNION SELECT 'homme',2.0 UNION SELECT 'bergamot',1.5 UNION SELECT 'cedar',1.2 UNION SELECT 'equilibre',1.0) x
WHERE p.api_id='demo-1' AND t.name = x.n;

-- Baccarat Rouge (id 2): oriental, luxueux, soiree, mixte, amber
INSERT INTO `perfume_tags` (`perfume_id`, `tag_id`, `weight`)
SELECT p.id, t.id, w FROM perfumes p, tags t,
(SELECT 'oriental' n, 2.0 w UNION SELECT 'luxueux',2.0 UNION SELECT 'soiree',1.8 UNION SELECT 'mixte',1.5 UNION SELECT 'amber',1.5 UNION SELECT 'boise',1.2 UNION SELECT 'puissant',1.5 UNION SELECT 'sandalwood',1.2) x
WHERE p.api_id='demo-2' AND t.name = x.n;

-- Bleu de Chanel (id 3): boise, frais, elegant, travail, homme
INSERT INTO `perfume_tags` (`perfume_id`, `tag_id`, `weight`)
SELECT p.id, t.id, w FROM perfumes p, tags t,
(SELECT 'boise' n, 1.8 w UNION SELECT 'frais',1.5 UNION SELECT 'elegant',1.8 UNION SELECT 'travail',1.5 UNION SELECT 'homme',2.0 UNION SELECT 'equilibre',1.5 UNION SELECT 'cedar',1.2 UNION SELECT 'citrus',1.2) x
WHERE p.api_id='demo-3' AND t.name = x.n;

-- Good Girl (id 4): gourmand, sucre, femme, seducteur, soiree
INSERT INTO `perfume_tags` (`perfume_id`, `tag_id`, `weight`)
SELECT p.id, t.id, w FROM perfumes p, tags t,
(SELECT 'gourmand' n, 2.0 w UNION SELECT 'sucre',1.8 UNION SELECT 'femme',2.0 UNION SELECT 'seducteur',1.5 UNION SELECT 'soiree',1.3 UNION SELECT 'tonka',1.2 UNION SELECT 'floral',1.2 UNION SELECT 'puissant',1.0) x
WHERE p.api_id='demo-4' AND t.name = x.n;

-- Aventus (id 5): boise, musque, homme, elegant, puissant
INSERT INTO `perfume_tags` (`perfume_id`, `tag_id`, `weight`)
SELECT p.id, t.id, w FROM perfumes p, tags t,
(SELECT 'boise' n, 1.8 w UNION SELECT 'musque',1.5 UNION SELECT 'homme',2.0 UNION SELECT 'elegant',1.5 UNION SELECT 'puissant',1.8 UNION SELECT 'musk',1.2 UNION SELECT 'luxueux',1.2) x
WHERE p.api_id='demo-5' AND t.name = x.n;

-- Light Blue (id 6): frais, femme, ete, propre, quotidien
INSERT INTO `perfume_tags` (`perfume_id`, `tag_id`, `weight`)
SELECT p.id, t.id, w FROM perfumes p, tags t,
(SELECT 'frais' n, 2.0 w UNION SELECT 'femme',2.0 UNION SELECT 'ete',2.0 UNION SELECT 'propre',1.8 UNION SELECT 'quotidien',1.5 UNION SELECT 'aquatic',1.2 UNION SELECT 'citrus',1.2 UNION SELECT 'discret',1.0) x
WHERE p.api_id='demo-6' AND t.name = x.n;

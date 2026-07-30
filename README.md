# Trouvez Votre Parfum

Outil élégant, style Typeform, pour aider un client à trouver le parfum qui lui correspond.
100% PHP natif + MySQL (phpMyAdmin), sans framework, sans Node, sans React.

## 1. Installation

1. Placez le projet dans votre dossier serveur (ex: `c:\wamp64\www\TROUVEZEPARFUMS`).
2. Ouvrez phpMyAdmin, créez une base nommée `trouvezeparfums` (ou autre nom de votre choix).
3. Importez le fichier `database.sql` dans cette base (onglet **Importer**).
   Ce fichier crée toutes les tables et insère des tags + 6 parfums de démonstration.

## 2. Configuration base de données

Modifiez `config/database.php` :

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'trouvezeparfums');
define('DB_USER', 'root');
define('DB_PASS', '');
```

## 3. Configuration de l'API PerfumAPI

Modifiez `config/app.php` :

```php
define('PERFUM_API_BASE_URL', 'http://localhost:3000'); // URL de votre instance PerfumAPI
define('PERFUM_API_KEY', ''); // si nécessaire
define('ADMIN_PASSWORD', 'change-me-please'); // à changer !
define('WHATSAPP_NUMBER', '33600000000'); // votre numéro WhatsApp
```

Le site fonctionne même si l'API est indisponible : les erreurs sont catchées et
loguées dans la table `api_logs`, sans jamais bloquer l'affichage.

## 4. Lancement du site

Ouvrez dans votre navigateur :

```
http://localhost/TROUVEZEPARFUMS/public/index.php
```

## 5. Fonctionnement du quiz

- **Parcours A (quiz classique)** : le client répond à 6 questions (genre, occasion,
  famille olfactive, intensité, ambiance, saison). Chaque réponse est convertie en
  tags pondérés par `QuizEngine::getTagsFromAnswers()`.
- **Parcours B (parfum aimé)** : le client recherche un parfum connu (recherche locale
  puis, si absent, import à la volée via l'API dans `public/search-perfume.php`). Il choisit
  ensuite une préférence de variation (plus sucré, plus frais...) qui ajuste les tags du
  parfum de départ.
- Dans les deux cas, `QuizEngine::rankPerfumes()` calcule un score pour chaque parfum actif
  du catalogue local et retourne les 3 meilleurs résultats avec un texte explicatif.

## 6. Fonctionnement de l'import

Depuis `/admin/import.php` (protégé par mot de passe) :

- Renseignez un `offset` et une `limite`, cliquez sur **Importer**.
- Les parfums sont récupérés depuis PerfumAPI, normalisés (tolérance aux noms de champs
  différents : `image_url`/`image`, `brand`/`house`, etc.), puis enregistrés en base
  via `ON DUPLICATE KEY UPDATE` (pas de doublons, basé sur `api_id`).
- Les tags sont générés automatiquement à partir des notes et accords de chaque parfum.
- Cliquez sur **Importer la page suivante** pour continuer la pagination.

## 7. Ajouter ses propres parfums

Deux méthodes :

1. **Via l'admin** : `/admin/perfume-edit.php?id=X` permet de modifier un parfum existant
   (notes, accords, prix, statut actif, tags avec poids au format `nom:poids`).
2. **Directement en base** : insérez une ligne dans `perfumes`, puis des lignes dans
   `perfume_tags` (ou utilisez `/admin/tags.php` pour créer de nouveaux tags au préalable).

## 8. Structure du projet

```
/config          Connexion DB + configuration générale
/includes        header/footer/fonctions partagées
/classes         PerfumeRepository, PerfumApiClient, ImportService, QuizEngine
/admin           Back-office (protégé par mot de passe)
/public          Site public (quiz, résultat, recherche AJAX)
database.sql     Schéma + données de départ
```

## 9. Sécurité

- Toutes les requêtes SQL utilisent des requêtes préparées PDO.
- Les sorties HTML sont échappées via `htmlspecialchars()`.
- L'admin est protégé par mot de passe (`ADMIN_PASSWORD` dans `config/app.php`).
- Les dossiers `/config`, `/classes`, `/includes` sont bloqués via `.htaccess`.
- La clé API n'est jamais exposée côté client : seul `search-perfume.php` (PHP serveur)
  peut appeler l'API.

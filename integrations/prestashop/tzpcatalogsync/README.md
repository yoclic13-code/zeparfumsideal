# Module PrestaShop — TZP Catalogue Sync

Synchronise en temps réel les produits de **zeparfums.com** (PrestaShop) vers le catalogue de **Trouvez Votre Parfum**.

## Installation

1. Copiez le dossier `tzpcatalogsync` dans `/modules/` de PrestaShop :
   ```
   modules/tzpcatalogsync/
   ```
2. Back-office → Modules → « TZP Catalogue Sync » → **Installer**
3. Configurez :
   - **URL webhook** : `https://zeparfumsideal.com/api/catalog-sync.php`
   - **Clé API** : la même valeur que `CATALOG_SYNC_API_KEY` dans `config/app.local.php` du site quiz
4. Activez « Tester la connexion » une fois pour valider, puis enregistrez

## Hooks utilisés

| Hook | Effet |
|------|--------|
| `actionProductSave` | Création / modification → sync |
| `actionUpdateQuantity` | Changement de stock → sync |
| `actionProductDelete` | Suppression → désactivation côté quiz |

## Côté Trouvez Votre Parfum

Dans `config/app.local.php` :

```php
define('CATALOG_SYNC_API_KEY', 'votre-cle-secrete-longue');
```

Les notes olfactives ne sont pas synchronisées depuis PrestaShop : utilisez **Import API → enrichissement** après coup.

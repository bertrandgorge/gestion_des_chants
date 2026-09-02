# Gestion des chants

Application web pour préparer et diffuser les feuilles de messe d'une paroisse.

- **Paroissiens** : scannent un QR code et lisent les chants + lectures sur leur téléphone.
- **Chantres** : préparent la feuille de messe (chants + lectures récupérées automatiquement depuis [l'API AELF](https://api.aelf.org)).
- **Administrateurs** : configurent la paroisse, les clochers et les utilisateurs.

Architecture **LAMP sans framework** (PHP 8.1+, MySQL/MariaDB, Apache), hébergeable sur O2Switch.
Front : **npm + SCSS + Bootstrap 5**, JavaScript natif (+ SortableJS).

## Développement (Docker)

```bash
docker compose up
```

- Application : http://localhost:8090
- DBGate (client SQL) : http://localhost:3000
- Mailpit (emails capturés) : http://localhost:8025

Au premier lancement l'entrypoint copie `config.docker.php` → `config.php`, installe les
dépendances Composer et applique `db/schema.sql`.

### Assets front

Les fichiers compilés (`public/assets/css/app.css`, `public/assets/js/vendor/*`) sont **commités**
pour que la production n'ait pas besoin de Node. Pour les régénérer après modification du SCSS :

```bash
npm install
npm run build      # compile le SCSS + copie les libs vendor
npm run watch      # recompilation à la volée pendant le développement
```

### Tests

```bash
docker compose exec app vendor/bin/phpunit
```

## Structure

| Dossier | Rôle |
|---|---|
| `public/` | racine web : `index.php` (contrôleur frontal), `.htaccess`, assets |
| `src/` | classes (autoload PSR-4 `App\`), `helpers.php` |
| `src/Controllers/` | un contrôleur par domaine fonctionnel |
| `src/Models/` | accès aux tables (requêtes SQL) |
| `views/` | gabarits PHP (`layout/`, `auth/`, `paroisse/`, `feuilles/`, `chant/`, `public/`, `emails/`) |
| `db/` | `schema.sql` + `migrations/*.sql` |
| `bin/migrate.php` | applique le schéma et les migrations (idempotent) |

### URLs

| URL | Interface |
|---|---|
| `/login`, `/register` | authentification (création de compte = création de paroisse) |
| `/app` | interface chantre (feuilles de messe) |
| `/admin/paroisse`, `/admin/clochers`, `/admin/utilisateurs` | administration (rôle admin) |
| `/{slug-paroisse}/{slug-clocher}` | interface paroissien (feuille en cours ou liste) |
| `/{slug-paroisse}/{slug-clocher}/{AAAA-MM-JJ-hhmm}` | feuille de messe précise |

## Déploiement O2Switch

1. Déposer les fichiers (hors `docker/`, `tests/`, `node_modules/`).
2. Faire pointer le domaine / sous-domaine sur le dossier `public/`.
3. `composer install --no-dev` (Composer disponible dans cPanel).
4. Créer la base MySQL depuis cPanel, copier `config.php.example` → `config.php` et renseigner
   base de données + SMTP.
5. `php bin/migrate.php` (terminal cPanel).
6. En production, désactiver l'affichage des erreurs (`php_flag display_errors off` dans
   `public/.htaccess` ou via le sélecteur PHP).

Les seules données de configuration à gérer sur l'hébergement sont dans `config.php`
(base de données, SMTP, URL publique).

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

Les fichiers compilés (`public/assets/css/app.css`, `public/assets/js/vendor/*`,
`public/assets/css/fonts/*`) sont **commités** pour que la production n'ait pas besoin de Node.
Pour les régénérer après modification du SCSS :

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
| `bin/migrate.php` | applique le schéma et les migrations (idempotent, via `App\Migrator`) |
| `src/Installer.php` | assistant d'installation servi tant que `config.php` n'existe pas (`/install`) |
| `bin/import_chantonseneglise.php` | importe les chants de chantonseneglise.fr (voir ci-dessous) |
| `bin/import_catechisme_emmanuel.php` | importe les chants de catechisme-emmanuel.com (répertoire Emmanuel, code IEV) |

### Import de chants (préremplissage)

Les scripts `bin/import_*.php` récupèrent des chants sur des sites de paroles et
créent des fiches « de catalogue » dans la table `chants` : `feuille_id = NULL`,
colonne `url` renseignée (fiche source / partitions, affichée **côté chantre
uniquement**). Ces chants alimentent l'autocomplétion de l'éditeur de feuille
(la version avec le plus de couplets est proposée en priorité, `chants.nb_couplets`).

Le site source étant lent, l'import se fait en **trois passes** suivies dans
`import_journal`, toutes relançables (une exécution sans `--phase` les enchaîne) :

1. `enum` — référence tous les chants du catalogue (`a-importer`), sans toucher aux fiches ;
2. `fetch` — télécharge chaque fiche → `avec-paroles` / `sans-paroles` ;
3. `import` — crée les fiches `chants` à partir des données récupérées (aucune requête).

Le `type` est toujours un slug de `App\SectionTypes::DEFAUT` (`entree`, `communion`,
`psaume`, `envoi`…), déduit du libellé du site — `entree` par défaut. La catégorie
brute est mémorisée : `--reclassify` recalcule `type`/`nom` hors-ligne après un
ajustement du mapping.

```bash
# import complet (long : plusieurs milliers de fiches, ~2 s par requête)
docker compose exec app php bin/import_chantonseneglise.php

# reprendre / fractionner la passe de téléchargement
docker compose exec app php bin/import_chantonseneglise.php --phase=fetch --limit=500

# test : lettre A uniquement
docker compose exec app php bin/import_chantonseneglise.php --letters=A
```

Relançable : chaque passe reprend où elle s'était arrêtée (`--help` pour les options,
`--refresh` pour tout recharger).

Les paroles sont remises au format de l'application (`R/` pour le refrain, `1.`,
`2.`… pour les couplets, ligne vide entre les parties). Pour re-formater des
fiches déjà importées sans retélécharger :

```bash
docker compose exec app php bin/import_chantonseneglise.php --reformat
```

#### Répertoire Emmanuel (catechisme-emmanuel.com)

Ces chants (Communauté de l'Emmanuel) portent un code **IEV** (« IEV 19-06 »)
absent de chantonseneglise.fr. On les importe **en premier**, puis on lance
chantonseneglise qui **complète** les fiches Emmanuel (cote Secli, auteur, paroles
plus complètes) au lieu de créer un doublon — le rapprochement se fait sur le code
IEV, mémorisé dans `import_journal.code_repertoire` (relevé dès que l'éditeur d'une
fiche chantonseneglise est « Éditions de l'Emmanuel »). Ces fiches passent alors au
statut `complete`.

```bash
# 1. import Emmanuel (≈140 chants, ~2 s/requête)
docker compose exec app php bin/import_catechisme_emmanuel.php

# 2. import chantonseneglise : complète les fiches Emmanuel + ajoute le reste
docker compose exec app php bin/import_chantonseneglise.php
```

Mêmes passes (`enum` / `fetch` / `import`) et mêmes options (`--phase`, `--limit`,
`--refresh`, `--reformat`, `--dry-run`) que l'import chantonseneglise.

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
4. Créer la base MySQL depuis cPanel.
5. Ouvrir le site dans un navigateur : tant que `config.php` n'existe pas, l'**assistant
   d'installation** (`/install`) s'affiche. Il permet de saisir la base de données et le
   SMTP, de tester la connexion et l'envoi d'email, puis écrit `config.php` et applique le
   schéma. Une fois `config.php` créé, `/install` n'est plus accessible.
   - Alternative manuelle : copier `config.php.example` → `config.php`, le renseigner, puis
     `php bin/migrate.php` (terminal cPanel).

L'affichage des erreurs PHP est déjà désactivé par le code (`src/bootstrap.php`) : les erreurs
sont journalisées mais jamais montrées au visiteur. Pour les afficher en local, mettre
`'debug' => true` dans `config.php` (section `app`).

Les seules données de configuration à gérer sur l'hébergement sont dans `config.php`
(base de données, SMTP, URL publique).

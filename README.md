# Gestion des chants

Application web pour préparer et diffuser les feuilles de messe d'une paroisse.

- **Paroissiens** : scannent un QR code et lisent les chants + lectures sur leur téléphone.
- **Chantres** : préparent la feuille de messe (chants + lectures récupérées automatiquement depuis [l'API AELF](https://api.aelf.org)).
- **Administrateurs** : configurent la paroisse, les clochers et les utilisateurs.


# Fonctionnalités

* Permet de créer une feuille de chant en 3 minutes chrono (contient déjà la plupart des chants)
* Import automatique AELF des lectures
* Gestion des utilisateurs en password-less
* Accès aux feuilles de messe via QRCode ou lien non périssable
* Possibilité d'imprimer ou d'exporter en word
* Possibilité d'afficher en mode présentation avec détection automatique des refrains
* Zoom dans tous les modes
* Mode sombre/clair

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
| `bin/import_chorale_pole_fontainebleau.php` | importe les chants de choralepolefontainebleau.org (répertoire de la chorale, ~750 chants) |
| `bin/import_url.php` | importe un seul chant à partir de son URL (un des trois sites ci-dessus) |
| `bin/dedup_chants.php` | regroupe les fiches de catalogue en double (même chant importé de plusieurs sources) |
| `bin/repair_import_journal.php` | répare les liens `import_journal.chant_id` ↔ `chants` (après un rechargement de base incohérent) |

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

#### Répertoires importés

```bash
# 1. import Emmanuel (≈140 chants, ~2 s/requête)
docker compose exec app php bin/import_catechisme_emmanuel.php

# 2. import chantonseneglise : complète les fiches Emmanuel + ajoute le reste
docker compose exec app php bin/import_chantonseneglise.php

# 3. import choralepolefontainebleau.org (~750 chants, ~2 s/requête)
docker compose exec app php bin/import_chorale_pole_fontainebleau.php
```

#### Dédoublonnage

Un même chant présent sur plusieurs sites crée une fiche par source. On les
regroupe sur une **clé titre + première ligne du refrain**, réduits à leurs seuls
caractères significatifs (`App\Models\Chant::cleDedup`) — ni le titre seul (des
chants homonymes existent), ni la cote Secli (souvent absente ou divergente).

- `bin/import_url.php` complète une fiche existante plutôt que d'en créer une en double ;
- les imports de masse créent les doublons puis on les regroupe après coup :

```bash
docker compose exec app php bin/dedup_chants.php --dry-run   # liste les fusions
docker compose exec app php bin/dedup_chants.php
```

L'autocomplétion de l'éditeur regroupe de toute façon les doublons résiduels sur
la même clé et propose la version la plus complète (et la plus propre).

Si la table `chants` et `import_journal` se retrouvent désynchronisées (base
rechargée depuis une sauvegarde, `chant_id` pointant vers un chant sans rapport) :

```bash
docker compose exec app php bin/repair_import_journal.php --dry-run
docker compose exec app php bin/repair_import_journal.php
```

Il relie chaque ligne de journal au bon chant (par url, puis par clé de
dédoublonnage) et recrée depuis les données conservées dans le journal les fiches
disparues — sans requête réseau.

#### Importer un seul chant par son URL

`bin/import_url.php` reconnaît l'un des trois sites, réutilise son analyse et
crée (ou met à jour / dédoublonne) la fiche correspondante :

```bash
docker compose exec app php bin/import_url.php https://www.chantonseneglise.fr/chant/14086/criez-de-joie-christ-est-ressuscite
docker compose exec app php bin/import_url.php <url> --dry-run   # analyse sans écrire
```

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
4. Créer la base et un utilisateur MySQL depuis cPanel (host à utiliser : localhost)
5. Créer un compte email pour l'envoi des emails (le host à utiliser est 'xxxx.o2switch.net' sur le port 465)
6. Ouvrir le site dans un navigateur : l'**assistant d'installation** (`/install`) s'affiche
   automatiquement. Il permet de saisir la base de données et le
   SMTP, de tester la connexion et l'envoi d'email, puis écrit `config.php` et applique le
   schéma. Si le dossier n'est pas accessible en écriture, l'assistant affiche le contenu
   exact du fichier à créer à la main. Une fois `config.php` créé, `/install` n'est plus
   accessible.
   - Alternative manuelle : copier `config.php.example` → `config.php`, le renseigner, puis
     `php bin/migrate.php` (terminal cPanel).


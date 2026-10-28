# DIARRASSOUBA IMPORT-EXPORT — gestion logistique et PWA

Application web de gestion du transport aérien de colis (Égypte → Côte d’Ivoire et Afrique de l’Ouest) :
clients, colis, dossiers de vol, suivi public, facturation au kilogramme, paiements, dépenses, livraisons,
notifications et tableau de bord. Conçue pour le téléphone d’abord, installable comme une application (PWA).

- **PHP 8.1+ sans aucune dépendance Composer** : rien à installer sur un hébergement mutualisé.
- **MySQL 5.7+ / MariaDB 10.3+** en production ; SQLite pour les essais locaux et les tests.
- Pages rendues côté serveur, JavaScript léger en amélioration progressive (l’application fonctionne sans).

Voir aussi : `docs/ARCHITECTURE.md` (choix techniques, règles métier, hypothèses) et `docs/DESIGN.md` (identité visuelle).

---

## 1. Essai local (5 minutes)

```bash
cp .env.example .env
# Dans .env : APP_ENV=local, APP_DEBUG=true, APP_URL=http://127.0.0.1:8080,
#             DB_DRIVER=sqlite, FORCE_HTTPS=false, et une APP_KEY :
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

php bin/migrate.php                                   # crée les tables
php bin/create-admin.php "Votre Nom" vous@exemple.com # demande le mot de passe
php bin/seed-demo.php                                 # optionnel : données de démonstration
php -S 127.0.0.1:8080 -t public public/index.php      # http://127.0.0.1:8080
```

### Variante XAMPP (Windows) : `http://localhost/diarrassouba/`
Le `.htaccess` de la racine du projet redirige tout vers `public/` (le code, `.env`, `storage/` restent inaccessibles).
1. Démarrez **Apache** et **MySQL** dans le panneau XAMPP ; créez une base `diarrassouba` (utf8mb4) dans phpMyAdmin.
2. `.env` : `APP_URL=http://localhost/diarrassouba`, `DB_DRIVER=mysql`, `DB_USER=root`, `DB_PASS=` (vide par défaut
   sous XAMPP), `FORCE_HTTPS=false`, et une `APP_KEY`.
3. Dans un terminal : `C:\xampp\php\php.exe bin\migrate.php`, puis `bin\create-admin.php` (et `bin\seed-demo.php` si besoin).
4. Pour les sauvegardes des pièces jointes, activez `extension=zip` dans `C:\xampp\php\php.ini`.

Les comptes de démonstration (un par rôle) ont pour mot de passe `Demo2026pass` :
`manager@`, `comptoir@`, `logistique@`, `caisse@`, `livreur@diarrassouba.test`.
Ne lancez jamais `seed-demo.php` en production (il refuse si `APP_ENV=production`).

## 2. Mise en production sur Hostinger

1. **Base de données** : hPanel → *Bases de données MySQL* → créer base + utilisateur. Notez les identifiants.
2. **Fichiers** : envoyez le projet (Gestionnaire de fichiers ou Git) **à côté** de `public_html`, par exemple
   `~/diarrassouba/`, puis copiez le **contenu** de `diarrassouba/public/` dans `public_html/`.
   `public_html/index.php` retrouve automatiquement l’application dans `../diarrassouba/`.
   (Variante : faire pointer le domaine directement sur `diarrassouba/public`.)
   Le code, `.env`, `storage/` et la base ne sont ainsi jamais accessibles depuis le web.
3. **Configuration** : copiez `.env.example` en `diarrassouba/.env` et remplissez : `APP_URL`, `APP_KEY`,
   `DB_*`, coordonnées `COMPANY_*`. Gardez `APP_DEBUG=false` et `FORCE_HTTPS=true`.
4. **HTTPS** : activez le certificat SSL gratuit dans hPanel (obligatoire pour la PWA et la caméra).
5. **Tables** :
   - avec SSH : `cd ~/diarrassouba && php bin/migrate.php` ;
   - sans SSH : phpMyAdmin → *Importer* → `database/migrations/001_init.sql`, puis exécutez
     `INSERT INTO schema_migrations (version, applied_at) VALUES ('001_init', NOW());`
     puis importez de même `database/migrations/002_settings.sql` et enregistrez la version `002_settings`
     (créez d’abord la table : `CREATE TABLE schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL);`).
6. **Premier administrateur** :
   - avec SSH : `php bin/create-admin.php "Nom" email@domaine.com` ;
   - sans SSH : mettez `INSTALL_TOKEN=` + 20 caractères aléatoires dans `.env`, ouvrez `https://votre-domaine.com/installation`,
     créez le compte, **puis videz `INSTALL_TOKEN`** (la page disparaît d’elle-même dès qu’un compte existe).
7. **Droits** : `storage/` et ses sous-dossiers doivent être accessibles en écriture par PHP (755/775).
8. **Tâches planifiées** (hPanel → *Tâches Cron*) :
   ```
   0 2 * * *   /usr/bin/php /home/UTILISATEUR/diarrassouba/bin/backup.php
   0 7 * * *   /usr/bin/php /home/UTILISATEUR/diarrassouba/bin/daily.php
   ```
9. Connectez-vous, créez les **tarifs** (Menu → Tarifs au kilogramme) puis les **utilisateurs** de l’équipe.

### Mises à jour ultérieures
Remplacez les fichiers (sans toucher à `.env` ni `storage/`), puis `php bin/migrate.php`.
Les migrations n’ajoutent que les éléments manquants : aucune donnée n’est supprimée ni réinitialisée.
Pensez à incrémenter `VERSION` dans `public/sw.js` pour que les téléphones proposent la mise à jour.

## 2 bis. Déploiement sur Vercel
Vercel n’exécute pas PHP nativement : le projet utilise le runtime communautaire **vercel-php** (`vercel.json`).
Toutes les pages passent par `api/index.php` ; CSS, JS, icônes, manifeste et service worker sont servis directement.

**À savoir avant de choisir Vercel** (le code est en lecture seule et chaque requête peut tomber sur une autre instance) :
- **Base MySQL externe obligatoire** (SQLite est impossible) : TiDB Cloud Serverless, Aiven for MySQL, Railway…
- **Sessions en base** : `SESSION_DRIVER=database`, sinon les utilisateurs seraient déconnectés au hasard.
- **Pièces jointes non conservées** : justificatifs de dépenses et preuves de livraison sont écrits dans `/tmp`, effacé à
  chaque redémarrage d’instance. Les PDF (factures, reçus, étiquettes) sont générés à la volée et ne sont pas concernés.
  Pour conserver les pièces jointes, préférez Hostinger (section 2) ou un stockage objet (évolution à prévoir).
- **Tâches planifiées** : `bin/backup.php` et `bin/daily.php` ne tournent pas sur Vercel. Lancez-les depuis un autre
  poste ou serveur, et utilisez les sauvegardes du fournisseur MySQL.

Étapes :
1. Créez la base MySQL chez le fournisseur et notez hôte, port, nom, utilisateur, mot de passe.
2. Depuis votre poste, créez les tables et le premier administrateur sur cette base (PowerShell) :
   ```
   $env:DB_HOST="…"; $env:DB_PORT="4000"; $env:DB_NAME="…"; $env:DB_USER="…"; $env:DB_PASS="…"
   C:\xampp\php\php.exe bin\migrate.php
   C:\xampp\php\php.exe bin\create-admin.php "Nom" email@domaine.com
   ```
3. Vercel → *Add New Project* → importez le dépôt GitHub. Framework : **Other**, sans commande de build.
4. *Settings → Environment Variables* : reprenez les variables de `.env.example`, au minimum `APP_ENV=production`,
   `APP_DEBUG=false`, `APP_URL=https://votre-projet.vercel.app`, `APP_KEY`, `FORCE_HTTPS=true`, `DB_DRIVER=mysql`,
   `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, **`SESSION_DRIVER=database`**, les `COMPANY_*`, et si le
   fournisseur exige une connexion chiffrée : `DB_SSL_CA=/etc/pki/tls/certs/ca-bundle.crt`.
5. *Deploy*. Chaque `git push` sur la branche principale redéploie. Après une mise à jour du schéma, relancez
   l’étape 2 (`migrate.php`) ; pensez aussi à incrémenter `VERSION` dans `public/sw.js`.

Essai local du point d’entrée Vercel : `php -S 127.0.0.1:8080 -t public api/index.php`.

## 3. Sauvegardes et restauration

`bin/backup.php` produit chaque nuit dans `storage/backups/` :
- `db-AAAAMMJJ-HHMMSS.sql.gz` : export complet de la base (structure + données) ;
- `fichiers-….zip` : pièces jointes, justificatifs et preuves de livraison.
Les fichiers de plus de `BACKUP_KEEP_DAYS` jours sont supprimés. **Copiez régulièrement ces fichiers hors du serveur**
(et gardez aussi les sauvegardes automatiques Hostinger).

Restauration :
```bash
gunzip -c storage/backups/db-20261027-020000.sql.gz | mysql -u UTILISATEUR -p NOM_BASE
unzip storage/backups/fichiers-20261027-020000.zip -d storage/uploads/
```
Sans SSH : décompressez le `.sql.gz` sur votre ordinateur et importez le `.sql` avec phpMyAdmin.
Faites un essai de restauration sur une base vide au moins une fois par trimestre.

## 4. Tests

```bash
php tests/run.php            # 35 tests métier sur une base SQLite temporaire
TEST_DB_NAME=di_test DB_USER=… DB_PASS=… php tests/run.php   # mêmes tests sur MySQL (base dédiée et VIDE)
python3 tests/e2e.py [URL]   # 41 vérifications navigateur (Playwright) sur le serveur local + démo
python3 tests/e2e_vols.py [URL]  # parcours « charger un vol » et raccourcis du tableau de bord
python3 tests/e2e_gestion.py [URL] # vol dès l’enregistrement, statut des vols, paramètres de l’entreprise
```

### Mode d’emploi express : mettre des colis dans un vol
1. **Dossiers → Nouveau dossier** : compagnie, n° de vol, destination (un dossier = un vol).
2. **Charger** — au choix :
   - **dès l’enregistrement du colis** : champ **« Vol (facultatif) »** du formulaire, qui ne propose que les vols en
     préparation vers la destination choisie (« Plus tard » si le vol n’est pas encore connu) ;
   - dans le dossier, bloc **« Charger le vol »** : scannez l’étiquette (douchette) ou tapez le code puis *Entrée* ;
     le champ reste prêt pour le colis suivant. Ou cochez dans la liste (filtre + total de poids en direct) ;
   - depuis la **fiche d’un colis** (ou après un scan) : bouton **« Mettre dans ce vol »** ;
   - raccourci **« Charger le vol »** en haut du tableau de bord.
3. **Changer le statut du vol** (« Confirmer le départ », « Confirmer l’arrivée », « Clôturer »…), au choix depuis
   le bloc **« Étape suivante »** en haut de la page du dossier, ou depuis le panneau **« Vols en cours »** du tableau
   de bord. L’étape logique suivante est présélectionnée ; *Date réelle et options* permet d’indiquer l’heure exacte.

Droits : le comptoir (agent d’enregistrement) peut **mettre** un colis dans un vol en préparation ; changer le statut
d’un vol ou retirer un colis reste réservé à la logistique et au responsable.

### Paramètres de l’entreprise (administrateur)
Menu → **Paramètres** : téléphone de l’entreprise et informations de retrait au dépôt. Ils s’appliquent immédiatement
à la page publique de suivi et aux nouveaux PDF (factures, reçus, étiquettes). Tant qu’aucune valeur n’est enregistrée,
celles de `.env` (`COMPANY_PHONE`, `COMPANY_DEPOT_INFO`) s’appliquent.

Seuls les colis de la même destination, non affectés et encore au comptoir peuvent être ajoutés ; un dossier
n’accepte des colis qu’« En préparation ». Pour corriger un oubli après le départ, repassez-le « En préparation ».
Résultats de la dernière exécution : voir `docs/ARCHITECTURE.md`, section « Vérifications effectuées ».

## 5. Application installable (PWA)
L’application s’installe comme une application native, sans store :
- **Android / Chrome / Edge (ordinateur)** : bouton **« Installer l’application »** (Menu sur mobile, bas de la barre
  latérale sur ordinateur), qui ouvre la fenêtre d’installation du navigateur, avec aperçus.
- **iPhone / iPad** : Safari → *Partager* → *Sur l’écran d’accueil* (instructions affichées dans le Menu).
- Une fois installée : icône sur l’écran d’accueil, plein écran, raccourcis (appui long sur l’icône) : *Enregistrer un
  colis*, *Scanner*, *Suivi public*. Hors connexion, une page dédiée s’affiche ; rien n’est enregistré sans réseau.

Prérequis : **HTTPS** en production (localhost est accepté pour les essais). Fichiers : `public/manifest.webmanifest`,
`public/sw.js`, `public/offline.html`, `public/assets/icons/`, `public/assets/screenshots/`.
Après une mise à jour, incrémentez `VERSION` dans `public/sw.js` : les appareils proposent alors « Mettre à jour ».
Vérification : Chrome → DevTools → *Application* → *Manifest* (aucune erreur d’installabilité).

## 6. Notifications externes
Par défaut, aucun e-mail, SMS ni message WhatsApp n’est envoyé : chaque tentative est journalisée avec le statut
« Canal non configuré » (Notifications → Envois aux clients). Pour les activer :
- **E-mail** : `MAIL_ENABLED=true` et `MAIL_FROM` = une adresse du domaine créée dans hPanel (fonction `mail()` d’Hostinger).
- **SMS** : `SMS_DRIVER=webhook` + `SMS_WEBHOOK_URL` / `SMS_WEBHOOK_TOKEN` d’une passerelle acceptant `{"to","message"}`
  (agrégateur local, ou petit relais vers l’API SMS d’Orange CI, MTN…).
- **WhatsApp** : API Cloud officielle de Meta — compte WhatsApp Business vérifié, `WHATSAPP_TOKEN`,
  `WHATSAPP_PHONE_NUMBER_ID` et un modèle approuvé (`WHATSAPP_TEMPLATE`) contenant un paramètre `{{1}}`.
Le résultat réel de chaque envoi (envoyé / échec + message d’erreur) est enregistré.

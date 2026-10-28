# Architecture, règles métier et vérifications

## 1. Audit de départ
- Aucun projet existant n’a été fourni (dossier vide) ; la capture `docs/reference-dashboard.png` n’était pas jointe.
  Le tableau de bord a donc été conçu à partir des besoins listés dans le cahier des charges uniquement.
- Environnement cible : hébergement PHP mutualisé (Hostinger), MySQL/MariaDB, sans accès garanti à SSH ni à Composer.
  Variante : Vercel avec le runtime vercel-php, MySQL externe et sessions en base (README, section 2 bis).
- Contraintes de l’environnement de développement : pas d’accès à Packagist, npm ni Google Fonts.
  D’où le choix « zéro dépendance » (moteur PDF et code-barres écrits pour le projet).

## 2. Choix techniques
| Besoin | Choix | Raison |
|---|---|---|
| Langage serveur | PHP 8.1+ natif | Disponible partout chez Hostinger, aucune installation. |
| Base | MySQL/MariaDB (prod), SQLite (tests) | Schéma unique écrit en MySQL, adapté automatiquement pour SQLite. |
| Interface | Rendu serveur + JS progressif | Rapide sur réseau mobile faible, fonctionne même si le JS échoue. |
| PDF | `App\Core\Pdf` (PDF 1.4, Helvetica WinAnsi) | Factures, reçus, étiquettes ; accents français et €. |
| Code-barres | Code 128 (`App\Core\Barcode`) | Vérifié contre l’implémentation de référence ReportLab ; lisible par la caméra (BarcodeDetector) et les douchettes. |
| PWA | `manifest.webmanifest`, `sw.js`, `offline.html` | Installable, cache limité aux fichiers statiques publics. |

### Organisation du code
```
public/            point d'entrée unique (index.php), CSS, JS, icônes, service worker
app/Core/          noyau : Db, Router, Auth, Session/CSRF, Validator, Money, Pdf, Barcode, Audit, RateLimiter, Upload, Signer
app/Services/      logique métier (une classe par domaine) — aucune requête SQL dans les vues
app/Controllers/   contrôle des permissions + appel des services + rendu
app/Views/         gabarits PHP (échappement systématique via e())
database/migrations/  schéma versionné (table schema_migrations)
bin/               migrate, create-admin, backup, daily, seed-demo
storage/           fichiers téléversés, sessions, journaux, sauvegardes (hors web)
tests/             run.php (métier) et e2e.py (navigateur)
```

## 3. Modèle de données
Tables : `users`, `roles`, `clients`, `parcels`, `parcel_attachments`, `shipments`, `shipment_parcels`,
`tracking_events`, `tariffs`, `invoices`, `invoice_items`, `invoice_installments`, `payments`, `expenses`,
`expense_attachments`, `deliveries`, `delivery_proofs`, `notifications`, `audit_logs`, plus tables techniques
`sequences`, `login_attempts`, `rate_limits`, `schema_migrations`.

- **Montants** : entiers en unités mineures (`*_minor`) — le franc CFA n’a pas de décimales, EUR/USD/EGP en centimes.
  Aucun calcul monétaire en virgule flottante.
- **Poids** : entiers en grammes (`*_g`).
- **Dates** : stockées en UTC, affichées à l’heure d’Abidjan (`APP_TIMEZONE`).
- **Contraintes clés** : `UNIQUE(tracking_code)`, `UNIQUE(shipment_parcels.parcel_id, active_flag)` (un seul dossier actif
  par colis), `UNIQUE(invoice_items.parcel_id, billing_lock)` (une seule facture active par colis),
  `UNIQUE(payments.method, reference)`, `UNIQUE(payments.form_token)`, `UNIQUE(payments.reversal_of_id)`,
  `UNIQUE(expenses.dedupe_key)`, `UNIQUE(invoices.number)`.
- **Conservation** : rien n’est supprimé physiquement sauf un brouillon de facture. Clients archivés, factures annulées,
  paiements contrepassés, dépenses annulées restent consultables. Clés étrangères sans suppression en cascade sur les
  données financières.
- **Transactions** : création de facture, validation, avoir, paiement, annulation de paiement, numérotation, affectation
  de colis à un dossier — avec verrouillage de ligne (`SELECT … FOR UPDATE`) sous MySQL.

## 4. Règles métier appliquées (et hypothèses à valider)

### Colis
- Code de suivi `DI-XXXX-XXXX` : 8 caractères aléatoires (alphabet sans I, L, O, U), environ 1 100 milliards de
  combinaisons, unicité garantie par la base. Saisie tolérante (minuscules, sans tirets, O→0).
- Poids facturable = poids pesé arrondi aux **100 g supérieurs** (`BILLING_WEIGHT_STEP_G`), puis minimum du tarif.
  *Hypothèse : à confirmer avec l’entreprise (certaines agences arrondissent au 0,5 kg ou au kg).*
- Montant = poids facturable × prix/kg, arrondi au plus proche dans la devise. Calculé côté serveur uniquement.
- Le prix/kg est copié dans le colis à l’enregistrement : changer la grille n’affecte pas les colis existants.
- Sans droit financier, l’agent ne peut pas modifier le prix : celui de la grille s’impose.
- Poids, prix et devise sont figés dès que le colis est sur une facture (brouillon compris).
- À l’enregistrement, un expéditeur/destinataire saisi dont le téléphone existe déjà réutilise la fiche existante.

### Statuts
- Parcours : Enregistré → En attente → Expédié → En transit → Arrivé → Disponible au dépôt → En cours de livraison → Livré.
- Autorisé : avancer d’une étape, reculer d’une étape (correction), passer en incident (Anomalie, Bloqué, Livraison
  échouée, Vérification requise), sortir d’incident vers une étape du parcours.
- **« Livré ou récupéré » n’est posé que par le module Livraisons**, après saisie du nom de la personne qui reçoit
  et cochage de la confirmation.
- Le changement de statut d’un dossier ne s’applique aux colis que si l’agent le demande, et uniquement aux colis dont
  la transition est cohérente : les colis en incident ou déjà plus avancés sont listés et laissés tels quels.

### Dossiers d’expédition
- Statuts : En préparation → Parti → En transit → Arrivé → Clôturé (ou Annulé si vide).
- Colis ajoutés uniquement en préparation, de même pays de destination, non affectés ailleurs.
- « Frais de fret prévus » = devis informatif. **Le fret payé s’enregistre en dépense « Fret aérien »** : seule la dépense
  compte dans la marge, ce qui évite la double comptabilisation.

### Facturation
- Facture individuelle (un colis) ou groupée (plusieurs colis d’un client, éventuellement d’un même dossier).
- Le client facturé peut être l’expéditeur ou le destinataire (paiement au départ ou à l’arrivée).
- Une même facture = une seule devise. Pas de conversion automatique.
- Brouillon : modifiable (frais par ligne, taxe, échéance, échéancier), non numéroté, non compté dans le CA, supprimable.
- Validation : numéro continu annuel `FAC-AAAA-NNNNN`, instantané du client, montants figés.
- Correction d’une facture validée : annulation (si aucun paiement) ou avoir `AVO-AAAA-NNNNN` ; les colis redeviennent
  facturables ; motif obligatoire et journalisé.
- Taxe : 0 % par défaut (`DEFAULT_TAX_RATE_BP`). *À fixer avec le comptable de l’entreprise.*
- Échéancier (acompte / plusieurs échéances) : la somme doit égaler le total pour valider.

### Paiements
- Montant > 0 et ≤ solde restant ; référence obligatoire sauf espèces ; référence unique par moyen ; jeton de
  formulaire unique contre le double clic.
- Statut de facture recalculé : Validée → Partiellement payée → Payée.
- Correction = écriture de contrepassation négative liée (une seule par paiement), motif obligatoire.
- Tous les paiements sont « saisie manuelle ». Le statut « confirmé par le prestataire » existe dans le modèle mais
  n’est jamais attribué sans intégration réelle (aucune confirmation simulée).

### Dépenses
- Catégories du cahier des charges ; billets d’avion (référence + compagnie obligatoires) distincts du fret aérien.
- Rattachement à un dossier ou aux frais généraux.
- Anti-doublon : référence de billet unique, référence de pièce fournisseur unique par catégorie, alerte si même
  catégorie/montant/date/dossier (confirmation explicite requise).
- Marge estimée par dossier = facturé (factures actives) − dépenses liées, par devise, présentée comme estimation.

### Suivi public `/suivi`
- Sans compte. Affiche : code, statut, trajet, poids (si autorisé par colis), date de prise en charge, historique
  horodaté avec commentaires publics, arrivée estimée, instructions de retrait, coordonnées de l’entreprise.
- N’affiche jamais : notes internes, montants, factures, téléphone, nom complet (« Konan A. »), nom des agents.
- Limitation : 30 recherches / 10 min / adresse IP (`TRACKING_RATE_LIMIT`), réponse identique pour un code
  inexistant, pages non indexées et non mises en cache.

### Rôles
| Rôle | Peut |
|---|---|
| Administrateur | Tout, dont utilisateurs et paramètres de l’entreprise |
| Responsable | Opérations, finances, annulations, avoirs, tarifs, journal |
| Agent d’enregistrement | Clients, colis, étiquettes, statuts ; mettre un colis dans un vol en préparation |
| Agent logistique | Dossiers, statuts, livraisons, dépenses |
| Comptable / caissier | Factures, paiements, reçus, dépenses |
| Agent de livraison | Ses livraisons uniquement (démarrer, confirmer, signaler un échec) |
Contrôle exclusivement côté serveur (`Auth::require`), matrice dans `app/Core/Domain.php`.

## 5. Sécurité
Mots de passe Argon2id (ou bcrypt), sessions `HttpOnly` / `SameSite=Lax` / `Secure` en HTTPS, régénération à la
connexion, expiration après inactivité, invalidation des autres sessions au changement de mot de passe, blocage
15 min après 5 échecs, mot de passe provisoire à changer. Requêtes préparées partout, échappement HTML systématique,
CSRF sur tous les POST, en-têtes CSP/X-Frame-Options/nosniff, téléversements contrôlés par type réel et taille,
stockés hors web avec nom aléatoire et servis après contrôle des droits. Liens de partage signés HMAC et expirables.
Journal d’audit sans données personnelles (identifiants, montants, motifs).

## 6. PWA et hors connexion
Le service worker met en cache uniquement CSS, JS, icônes, polices et la page hors ligne. Pages, API, PDF et pièces
jointes passent toujours par le réseau. Hors connexion : bandeau d’avertissement, envoi de formulaire bloqué avec
message explicite (rien n’est annoncé comme enregistré), page dédiée en navigation. Mise à jour proposée par un
bandeau (« Mettre à jour ») et non imposée.
*Choix assumé : pas de saisie hors connexion avec synchronisation différée, pour ne jamais créer de doublon
de colis ou de paiement. Peut être ajouté plus tard si le besoin terrain le justifie.*

## 7. Vérifications effectuées (27/10/2026)
- `php tests/run.php` : **33/33** tests réussis (montants, arrondis, unicité des codes, transitions, dossier actif
  unique, facturation individuelle et groupée, double facturation, annulation/avoir, taxe et échéancier, paiements
  partiels, solde, trop-perçu, double envoi, référence dupliquée, contrepassation, dépenses et doublons, marge,
  retrait/livraison/échec, permissions, notifications non simulées, indicateurs, liens signés, PDF, journal).
- `python3 tests/e2e.py` (Chromium) : **41/41** vérifications réussies — refus CSRF, redirections et 403 par rôle,
  parcours complet mobile (colis → étiquette → dossier → départ → facture → paiement partiel → reçu → partage),
  suivi public (données masquées, codes inconnus, format invalide, normalisation), lien de partage falsifié refusé,
  manifeste et icônes, service worker actif, page hors ligne, cache sans donnée privée, aucune erreur JS,
  limitation à 429 après 30 recherches.
- Critique visuelle : captures à 390 px et 1280 px de toutes les pages (`docs/screenshots/`). Défauts trouvés et
  corrigés : débordements horizontaux (grille, fil d’activité, axe du graphique, tableau financier, frais de facture),
  icônes d’alerte non dimensionnées, attribut `hidden` ignoré par le sélecteur client, marque masquée sur la page
  publique mobile, bug JS (champ « method » masquant `form.method`, qui désactivait l’anti double-clic sur le
  formulaire de paiement).
- Validation du code-barres : séquence de barres identique à ReportLab ; PDF vérifiés avec `pdftotext`.

### Revue du 27/10/2026 (Windows, XAMPP : Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32)
- Tests métier **33/33** sur SQLite et sur MariaDB ; e2e **41/41** via le serveur PHP intégré, puis via Apache dans
  le sous-dossier `/diarrassouba` (SQLite puis MariaDB) ; exploration automatique de toutes les pages pour les
  6 rôles (≈ 740 pages + identifiants et paramètres invalides) : aucune erreur 500 ni avertissement PHP.
- Sauvegarde MariaDB restaurée dans une base vide : 23 tables identiques (CHECKSUM TABLE).
- Corrigé : chemin SQLite absolu Windows (`C:\…`) non reconnu ; agent de livraison voyant la liste de tous les colis
  et les étiquettes hors de ses tournées ; code 419 (non standard) transformé en 500 par Apache → 403 ;
  `.htaccess` racine absent (sous XAMPP, `.env`, `storage/`, `tests/` étaient servis) + détection du sous-dossier ;
  sauvegarde sans `SET NAMES utf8mb4` (accents corrompus à la restauration avec le client `mysql`) ; absence de
  l’extension zip signalée au lieu d’être ignorée ; séparateurs `\` dans le zip ; `/utilisateurs/{id}` inexistant → 404.

## 8. Limitations connues
- **MySQL** : vérifié sur MariaDB 10.4 (XAMPP). Faire tout de même un premier `bin/migrate.php` sur une base vide
  Hostinger avant d’y mettre des données (version MySQL/MariaDB du serveur à confirmer).
- **Polices** chargées depuis Google Fonts (Archivo, Public Sans) ; non chargeables dans l’environnement de test,
  donc les captures montrent la police de secours. Possibilité de les héberger localement.
- **QR code** non généré (aucune bibliothèque téléchargeable) : étiquettes en Code 128, également lisible par
  le scanner de l’application. Le scanner caméra lit aussi les QR codes si un jour ils sont ajoutés.
- **Scanner caméra** : API BarcodeDetector disponible sur Chrome Android ; ailleurs, saisie manuelle ou douchette.
- **SMS / WhatsApp / e-mail** : architecture prête, envoi réel impossible sans identifiants de prestataire.
- **Paiements en ligne** (Mobile Money, carte) : non intégrés, saisie manuelle uniquement.
- **Multi-devises** : pas de conversion ; indicateurs présentés séparément par devise.
- **Taxes, arrondi du poids, mentions légales** : valeurs par défaut à confirmer par l’entreprise et son comptable.

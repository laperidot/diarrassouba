# Identité visuelle — « Air Waybill »

## Intention
DIARRASSOUBA transporte des colis par avion, du Caire vers l'Afrique de l'Ouest. L'interface emprunte son
vocabulaire aux objets du fret aérien que l'équipe manipule tous les jours :

- la **lettre de transport aérien** (LTA / AWB) : cases numérotées, libellés en petites capitales à chasse fixe,
  filets pointillés ;
- le **tableau des départs à palettes** (type Solari) : chiffres jaunes sur tuiles noires fendues ;
- la **piste de nuit** et l'**orange aviation** des gilets, balises et bandes de sécurité ;
- les **balises de navigation** (losanges) qui jalonnent une route aérienne.

Le résultat doit rester un outil de comptoir rapide, lisible debout, téléphone à la main.

## Pièces signature
| Élément | Où | Description |
|---|---|---|
| Barre latérale « cockpit » | Bureau ≥ 1024 px | Toujours sombre. Marque, route CAI → ABJ, navigation par groupes (Opérations, Finances, Station), carte utilisateur. Réduite aux icônes entre 1024 et 1439 px pour laisser la place aux tableaux. |
| Horloges des escales | Barre supérieure (bureau) | Heure d'Abidjan et du Caire (fuseaux réels, heure d'été comprise), mises à jour par `app.js`. |
| Tableau des départs | Tableau de bord | Le rail de route sur fond nuit ; les compteurs sont des palettes jaunes. |
| Rail de route | Tableau de bord, fiche colis, suivi public | Huit balises en losange ; tronçon aérien en pointillés bleus ; étape courante en orange, qui pulse doucement. |
| Étiquette de soute | Carte héros (colis, dossier), suivi public, connexion | Fond nuit, bande de sécurité orange/noir, codes IATA en très grand. |
| Cases de LTA | Formulaires | Chaque `fieldset` est numéroté (01, 02…) par compteur CSS, libellés en capitales à chasse fixe. |
| Tampons de statut | Partout | Capitales à chasse fixe, cadre fin, losange ; une seule famille de couleur par statut. |
| Manifeste | Tableaux | En-têtes en capitales à chasse fixe, filet épais sous l'en-tête, montants en chasse fixe. |

## Couleurs (tokens dans `public/assets/css/app.css`)
Les composants n'utilisent que des tokens sémantiques, redéfinis pour chaque thème.

| Token | Jour (papier kraft) | Nuit (tarmac) | Usage |
|---|---|---|---|
| `--bg` | #EEE9DF + quadrillage | #0C0D10 + quadrillage | fond de page |
| `--surface` / `-2` / `-3` | #FFFCF6 / #F8F3EA / #EFE8DB | #15171C / #111317 / #1E2128 | feuillets, en-têtes de tableau, survol |
| `--text` / `--muted` | #15171C / #6A6356 | #F1ECE2 / #A29D92 | texte (contraste AA) |
| `--orange` | #FF5A1F | #FF6A2E | **action décisive** (`.btn-go`), marque, étape courante |
| `--orange-ink` | #B53A0A | #FF8C5C | liens et textes orange (AA) |
| `--primary` | #15171C | #F1ECE2 | action standard (`.btn-primary`), inversée la nuit |
| `--night` | #121419 | #08090B | barre latérale, tableau des départs, cartes héros |
| `--flap` | #F6C744 | #F6C744 | chiffres des palettes, horloges |

Statuts : `teal` = remis / encaissé (vert « autorisé »), `blue` = en l'air, `amber` = attend une action au sol,
`coral` = incident ou solde dû, `ground` = neutre. Chaque famille a une variante `-ink` (texte) et `-soft` (fond).
Les anciens noms (`--ink`, `--paper`, `--card`, `--line`…) restent des alias.

## Typographie
- **Barlow Condensed** (700–800) : titres, en capitales pour les `h1` ; chiffres clés ; codes IATA.
- **IBM Plex Sans** : texte courant et champs.
- **IBM Plex Mono** : codes de suivi, montants, dates, libellés de champs, en-têtes de tableau, tampons.

## Mode jour / nuit
- `public/assets/js/theme.js`, chargé dans `<head>` **sans `defer`** : pose `data-theme` avant le premier rendu (pas de flash).
- Choix enregistré dans le navigateur (`localStorage`, clé `di-theme`) : *Auto* (suit le système, en direct), *Jour*, *Nuit*.
- Bascule soleil / lune dans la barre supérieure, la connexion et la page publique ; choix à trois positions dans Menu → Apparence.
- Sans JavaScript : la préférence du système s'applique (CSS `prefers-color-scheme`).
- Impression et manifeste : toujours en clair. Les PDF (facture, reçu, étiquette) reprennent la palette : bande orange, teintes kraft.

## Mise en page
- Mobile d'abord : barre supérieure (marque, titre, thème, notifications) et barre d'onglets sombre en bas
  (Accueil, Colis, **Scanner** en losange orange, Vols, Menu).
- Bureau : barre latérale + barre supérieure translucide avec recherche rapide de colis.
- Colonnes `grid-main` côte à côte seulement à partir de 1180 px ; en dessous, les panneaux s'empilent.
- Les tableaux défilent horizontalement plutôt que d'être rognés.

## Mouvement
Discret et utile : pulsation de la balise courante, routes animées sur la page de connexion, apparition des messages.
Tout est désactivé avec `prefers-reduced-motion`.

## Icône de l'application
Monogramme « DI » orange sur fond nuit, route pointillée terminée par une balise jaune, bande de sécurité.
`assets/icons/icon.svg` + PNG 192/512 et version *maskable* (zone de sécurité respectée).

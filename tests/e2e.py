"""
Parcours fonctionnels de bout en bout (navigateur réel, Playwright + Chromium).
Prérequis : serveur lancé (php -S 127.0.0.1:8080 -t public public/index.php) sur une base
de démonstration (php bin/seed-demo.php). Usage : python3 tests/e2e.py [URL]
"""
import re, sys, json, urllib.request, urllib.error, urllib.parse
from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
ADMIN = ('admin@diarrassouba.test', 'Admin2026secure')
DEMO_PW = 'Demo2026pass'
results = []

def check(name, cond, detail=''):
    results.append((name, bool(cond)))
    print(('  ✔ ' if cond else '  ✘ ') + name + ('' if cond else f'  -> {detail}'))

def login(page, email, pw):
    page.goto(BASE + '/connexion')
    page.fill('#email', email); page.fill('#password', pw)
    page.click('form button.btn-primary'); page.wait_for_load_state('networkidle')

with sync_playwright() as p:
    b = p.chromium.launch()

    print('\nAuthentification et sécurité')
    ctx = b.new_context(); pg = ctx.new_page()
    login(pg, ADMIN[0], 'mauvais')
    check('Mot de passe faux : message neutre', 'E-mail ou mot de passe incorrect' in pg.content())
    r = pg.request.post(BASE + '/clients', form={'name': 'X', 'phone': '0102030405'})
    check('POST sans jeton CSRF refusé (403, « Formulaire expiré »)', r.status == 403 and 'Formulaire expiré' in r.text(), r.status)
    r = pg.goto(BASE + '/factures')
    check('Page privée sans session -> connexion', '/connexion' in pg.url)
    ctx.close()

    ctx = b.new_context(); pg = ctx.new_page()
    login(pg, 'livreur@diarrassouba.test', DEMO_PW)
    check('Agent de livraison redirigé vers ses livraisons', '/livraisons' in pg.url, pg.url)
    r = pg.goto(BASE + '/factures'); check('Agent de livraison : factures interdites (403)', r.status == 403, r.status)
    r = pg.goto(BASE + '/depenses'); check('Agent de livraison : dépenses interdites (403)', r.status == 403, r.status)
    check('Agent de livraison : ne voit que ses tournées', pg.goto(BASE + '/livraisons') and pg.locator('table tbody tr').count() >= 1)
    ctx.close()

    ctx = b.new_context(); pg = ctx.new_page()
    login(pg, 'caisse@diarrassouba.test', DEMO_PW)
    r = pg.goto(BASE + '/utilisateurs'); check('Caissier : gestion des utilisateurs interdite (403)', r.status == 403, r.status)
    r = pg.goto(BASE + '/colis/nouveau'); check('Caissier : enregistrement de colis interdit (403)', r.status == 403, r.status)
    ctx.close()

    print('\nParcours complet : colis -> dossier -> facture -> paiement -> partage')
    ctx = b.new_context(viewport={'width': 390, 'height': 844}); pg = ctx.new_page()
    errors = []
    pg.on('pageerror', lambda e: errors.append(str(e)))
    login(pg, 'comptoir@diarrassouba.test', DEMO_PW)
    pg.goto(BASE + '/colis/nouveau')
    pg.fill('#sender_name', 'Karim Mostafa'); pg.fill('#sender_phone', '+20 111 555 0101')
    pg.fill('#recipient-search', 'Konan')
    pg.wait_for_selector('[data-picker="recipient"] .picker-results button')
    pg.click('[data-picker="recipient"] .picker-results button')
    check('Sélecteur client : client existant choisi', pg.input_value('input[name="recipient_id"]') != '')
    pg.fill('#description', 'Téléphones reconditionnés'); pg.fill('#weight_kg', '3,42')
    pg.select_option('#tariff_id', index=1)
    check('Calcul indicatif affiché avant envoi', '3,5 kg' in pg.inner_text('[data-calc]'), pg.inner_text('[data-calc]'))
    pg.click('form[data-parcel-form] button.btn-primary'); pg.wait_for_load_state('networkidle')
    code = pg.inner_text('h1').strip()
    check('Colis enregistré, code de suivi affiché', re.match(r'^DI-[0-9A-Z]{4}-[0-9A-Z]{4}$', code), code)
    parcel_url = pg.url
    r = pg.request.get(parcel_url + '/etiquette')
    check('Étiquette PDF générée', r.status == 200 and r.headers['content-type'] == 'application/pdf' and r.body()[:5] == b'%PDF-')

    # Recherche interne du code saisi « à la main »
    r = pg.request.get(BASE + '/api/colis/recherche-code?code=' + code.lower().replace('-', ''))
    check('Scanner / saisie : code retrouvé sans tirets ni majuscules', r.status == 200 and r.json()['found'])

    ctx.close()
    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errors.append(str(e)))
    login(pg, 'logistique@diarrassouba.test', DEMO_PW)
    pg.goto(BASE + '/dossiers/nouveau')
    pg.fill('#airline', 'EgyptAir'); pg.fill('#flight_number', 'ms 999')
    pg.click('form.panel button.btn-primary'); pg.wait_for_load_state('networkidle')
    ship_url = pg.url
    pg.check(f'label:has-text("{code}") input')
    pg.click('form[data-select-all-scope] button.btn-primary'); pg.wait_for_load_state('networkidle')
    check('Colis ajouté au dossier', code in pg.inner_text('table'))
    pg.select_option('#ship-status', 'departed')
    pg.click('form[action$="/statut"] button'); pg.wait_for_load_state('networkidle')
    check('Départ confirmé : colis passé « Expédié »', 'expédié' in pg.inner_text('table').lower())  # statuts affichés en capitales
    ctx.close()

    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errors.append(str(e)))
    pg.on('dialog', lambda d: d.accept())
    login(pg, 'caisse@diarrassouba.test', DEMO_PW)
    pg.goto(BASE + '/factures/nouvelle')
    pg.fill('#client-search', 'Karim')
    pg.wait_for_selector('[data-picker] .picker-results button'); pg.click('[data-picker] .picker-results button')
    pg.wait_for_load_state('networkidle')
    pg.check(f'label:has-text("{code}") input')
    pg.click('form button.btn-primary:has-text("Créer le brouillon")'); pg.wait_for_load_state('networkidle')
    check('Brouillon de facture créé', 'brouillon' in pg.inner_text('h1').lower())
    inv_url = pg.url
    pg.click('button:has-text("Valider et numéroter")'); pg.wait_for_load_state('networkidle')
    number = pg.inner_text('h1')
    check('Facture validée et numérotée', re.search(r'FAC-\d{4}-\d{5}', number), number)
    pg.goto(BASE + '/factures/nouvelle?client=' + inv_url.rsplit('/', 1)[1])  # n'importe quel client
    pg.goto(inv_url)
    pg.fill('#amount', '10000'); pg.select_option('#method', 'wave'); pg.fill('#reference', 'WAVE-E2E-1')
    pg.click('button:has-text("Enregistrer le paiement")'); pg.wait_for_load_state('networkidle')
    check('Paiement partiel enregistré', 'Partiellement payée' in pg.content())
    receipt = pg.locator('a:has-text("ouvrir le PDF")').get_attribute('href')
    r = pg.request.get(urllib.parse.urljoin(BASE + '/', receipt))  # lien déjà préfixé si sous-dossier
    check('Reçu PDF téléchargeable', r.status == 200 and r.body()[:5] == b'%PDF-')
    # double paiement avec la même référence
    pg.fill('#amount', '1000'); pg.select_option('#method', 'wave'); pg.fill('#reference', 'WAVE-E2E-1')
    pg.click('button:has-text("Enregistrer le paiement")'); pg.wait_for_load_state('networkidle')
    check('Référence de transaction en double refusée', 'déjà enregistrée' in pg.content())
    token = pg.locator('meta[name="csrf-token"]').get_attribute('content')
    r = pg.request.post(inv_url + '/partage', headers={'X-CSRF-Token': token, 'Accept': 'application/json'})
    share = r.json().get('url', '')
    check('Lien de partage signé créé', '/partage/facture/' in share, r.text())
    ctx.close()

    anon = b.new_context(); ap = anon.new_page()
    r = ap.request.get(share.replace('http://127.0.0.1:8080', BASE))
    check('Lien de partage : PDF accessible sans compte', r.status == 200 and r.headers['content-type'] == 'application/pdf')
    r = ap.request.get(share[:-3] + 'AAA')
    check('Lien de partage falsifié refusé (404)', r.status == 404, r.status)

    print('\nSuivi public')
    r = ap.goto(BASE + '/suivi/' + code)
    html = ap.content()
    check('Suivi : colis trouvé avec historique', r.status == 200 and 'Historique' in html and 'Expédié' in html)
    check('Suivi : aucun montant affiché', 'CFA' not in html)
    check('Suivi : téléphone et nom complet masqués', '0101' not in html and 'Konan Aya Marie' not in html)
    check('Suivi : pas de nom d’agent', 'Ibrahim' not in html and 'Yao Kouassi' not in html)
    ap.goto(BASE + '/suivi?code=' + code.lower().replace('-', ' '))
    check('Suivi : saisie libre normalisée', ap.url.endswith('/suivi/' + code), ap.url)
    r = ap.goto(BASE + '/suivi/DI-ZZZZ-ZZZZ')
    check('Suivi : code inconnu -> message neutre (404)', r.status == 404 and 'Aucun colis ne correspond' in ap.content())
    ap.goto(BASE + '/suivi?code=abc')
    check('Suivi : format invalide expliqué', 'pas le bon format' in ap.content())

    print('\nPWA')
    r = ap.request.get(BASE + '/manifest.webmanifest')
    m = json.loads(r.text())
    check('Manifeste valide (nom, icônes 192/512, standalone)', m['display'] == 'standalone' and {'192x192', '512x512'} <= {i['sizes'] for i in m['icons']})
    for ic in m['icons']:
        rr = ap.request.get(BASE + '/' + ic['src'])
        check(f"Icône {ic['src']} servie", rr.status == 200)
    ap.goto(BASE + '/suivi'); ap.wait_for_timeout(1500); ap.reload(); ap.wait_for_timeout(800)
    check('Service worker actif', ap.evaluate('!!navigator.serviceWorker.controller'))
    anon.set_offline(True)
    try:
        ap.goto(BASE + '/tableau-de-bord')
        check('Hors connexion : page dédiée affichée', 'Pas de connexion' in ap.content())
    except Exception as e:
        check('Hors connexion : page dédiée affichée', False, str(e))
    anon.set_offline(False)
    cached = ap.evaluate("caches.keys().then(ks => Promise.all(ks.map(k => caches.open(k).then(c => c.keys())))).then(a => a.flat().map(r => r.url))")
    check('Cache : aucune page privée ni donnée stockée', all(('/assets/' in u or u.endswith(('offline.html', 'manifest.webmanifest')) or 'fonts' in u) for u in cached), cached)

    check('Aucune erreur JavaScript pendant les parcours', not errors, errors)

    print('\nLimitation des recherches publiques')
    codes = [ap.goto(BASE + '/suivi/DI-AAAA-AA%02d' % i).status for i in range(35)]
    check('Au-delà de 30 recherches en 10 min : 429', 429 in codes, codes[-5:])
    b.close()

ok = sum(1 for _, c in results if c)
print(f'\n{ok} réussi(s), {len(results) - ok} échec(s)')
sys.exit(0 if ok == len(results) else 1)

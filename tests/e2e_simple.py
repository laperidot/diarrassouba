"""
Simplicité d'usage, rôle par rôle, sur la démonstration complète (bin/seed-demo.php) :
grille tarifaire choisie d'office, remise au guichet en une étape, livreur sans détour, encaissement direct.
Playwright + Chromium. Usage : python3 tests/e2e_simple.py [URL]
"""
import re, sys
from playwright.sync_api import sync_playwright
B = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
res = []

def check(n, c, d=''):
    res.append(bool(c)); print(('  ✔ ' if c else '  ✘ ') + n + ('' if c else f'  -> {d}'))

def login(pg, who):
    pg.goto(B + '/connexion'); pg.fill('#email', who + '@diarrassouba.test'); pg.fill('#password', 'Demo2026pass')
    pg.click('form button.btn-primary'); pg.wait_for_load_state('networkidle')

with sync_playwright() as p:
    b = p.chromium.launch()
    errs = []
    def page(w=1280, h=900):
        ctx = b.new_context(viewport={'width': w, 'height': h}); pg = ctx.new_page()
        pg.on('pageerror', lambda e: errs.append(str(e)))
        return ctx, pg
    def dt_of(pg):  # « Départ prévu 05/11 02:30 » -> « 1105 »
        m = re.search(r'(\d\d)/(\d\d)', pg.inner_text('.route-cities'))
        return m.group(2) + m.group(1) if m else ''
    tariff_name = lambda pg:pg.evaluate("(s => s.options[s.selectedIndex].text)(document.getElementById('tariff_id'))")

    print('\nComptoir : grille tarifaire choisie d’office')
    ctx, pg = page(390, 844)
    login(pg, 'comptoir')
    pg.goto(B + '/colis/nouveau')
    check('Le Caire → Abidjan : grille Standard (la moins chère) déjà choisie', tariff_name(pg).startswith('Standard Le Caire → Abidjan'), tariff_name(pg))
    check('Prix au kilo rempli', pg.input_value('#rate').replace(' ', '').replace(' ', '') == '6500', pg.input_value('#rate'))
    pg.fill('#weight_kg', '10')
    check('Montant indicatif calculé sans rien choisir', '65' in pg.inner_text('[data-calc-amount]'), pg.inner_text('[data-calc-amount]'))
    pg.select_option('#destination_country', 'SN')
    check('Destination Sénégal : la grille suit (Dakar)', 'Dakar' in tariff_name(pg), tariff_name(pg))
    pg.select_option('#destination_country', 'FR')
    check('Trajet sans prix enregistré : « Autre prix », prix vidé', tariff_name(pg) == 'Autre prix (à taper)' and pg.input_value('#rate') == '')
    pg.select_option('#destination_country', 'CI')
    pg.click('#tariff_id'); pg.keyboard.press('ArrowUp'); pg.keyboard.press('Enter')   # choix manuel (événement réel)
    manual = tariff_name(pg)
    pg.select_option('#destination_country', 'ML')
    check('Choix manuel de l’agent conservé au changement de destination', tariff_name(pg) == manual, (manual, tariff_name(pg)))
    ctx.close()

    print('\nLogistique : remise au guichet en une étape')
    ctx, pg = page()
    login(pg, 'logistique')
    deps = pg.evaluate("""[...document.querySelectorAll('.flights > li')].filter(li => /préparation/i.test((li.querySelector('.status') || {}).textContent || ''))
        .map(li => li.innerText.match(/départ (\\d\\d)\\/(\\d\\d)/)).filter(Boolean).map(m => m[2] + m[1]).sort()""")
    pg.click('.quick a:has-text("Charger le vol")'); pg.wait_for_load_state('networkidle')
    check('« Charger le vol » ouvre le vol qui part le plus tôt', deps and dt_of(pg) == deps[0], (deps, dt_of(pg)))
    pg.goto(B + '/livraisons')
    check('Livraisons : téléphone cliquable sur chaque ligne', pg.locator('table a.tel[href^="tel:"]').count() >= 1)
    btn = pg.locator('aside a:has-text("Remettre")').first
    check('« Prêts à remettre » : bouton Remettre', btn.count() == 1)
    btn.click(); pg.wait_for_load_state('networkidle')
    code = pg.inner_text('h1').strip()
    check('Ouvre la fiche du colis sur le bloc de remise', pg.url.endswith('#remise') and pg.locator('form.handover').count() == 1, pg.url)
    check('Nom du destinataire prérempli', pg.input_value('#ho_name') != '')
    pg.click('form.handover button'); pg.wait_for_load_state('networkidle')
    check('Case de confirmation exigée (rien n’est remis sans elle)', pg.locator('form.handover').count() == 1 and 'Livré' not in pg.inner_text('.hero-card, main'))
    pg.fill('#ho_id', 'CNI …7788'); pg.check('form.handover input[name="confirm"]')
    pg.click('form.handover button'); pg.wait_for_load_state('networkidle')
    check('Colis remis en une étape : « Remis au client »', 'Colis remis' in pg.inner_text('main') and 'Remis au client' in pg.inner_text('main'), pg.inner_text('main')[:200])
    pg.goto(B + '/suivi/' + code)
    check('Suivi public à jour', 'remis au client' in pg.inner_text('body').lower())
    ctx.close()

    print('\nLivreur : tout depuis la liste')
    ctx, pg = page(390, 844)
    login(pg, 'livreur')
    pg.goto(B + '/livraisons')
    check('Téléphone du destinataire visible et cliquable', pg.locator('a.tel[href^="tel:"]').count() >= 1)
    start = pg.locator('button:has-text("Je pars livrer")').first
    check('Bouton « Je pars livrer » directement sur la carte', start.count() == 1 and start.bounding_box()['height'] >= 44)
    start.click(); pg.wait_for_load_state('networkidle')
    check('Démarrée : arrive sur la confirmation de remise', pg.locator('#recipient_name').count() == 1 and 'en route' in pg.inner_text('main'))
    pg.goto(B + '/livraisons')
    check('Livraison en cours : « C’est remis » sur la carte', pg.locator('a:has-text("C’est remis")').count() >= 1)
    check('Pas de débordement horizontal', not pg.evaluate('document.documentElement.scrollWidth > innerWidth'))
    ctx.close()

    print('\nCaisse : encaisser sans chercher')
    ctx, pg = page()
    login(pg, 'caisse')
    pg.click('.quick a:has-text("Encaisser")'); pg.wait_for_load_state('networkidle')
    check('Raccourci « Encaisser » : filtre « Pas encore payées » actif', pg.inner_text('.chips [aria-current="true"]').lower().startswith('pas encore payées'))
    check('Compteurs sur les filtres', pg.locator('.chips .chip-count').count() >= 2)
    pg.click('.chips a:has-text("En retard")'); pg.wait_for_load_state('networkidle')
    check('Factures échues signalées', pg.locator('table .tag-late').count() >= 1)
    pg.click('table a:has-text("Encaisser")'); pg.wait_for_load_state('networkidle')
    check('« Encaisser » ouvre le paiement avec le reste dû prérempli', pg.url.endswith('#amount') and pg.input_value('#amount') not in ('', '0'), pg.url)
    ctx.close()

    print('\nNouveautés : rôles clients, paiement des colis, référence facultative, documents')
    ctx, pg = page()
    login(pg, 'manager')
    check('Fond de page blanc (thème jour)', pg.evaluate("getComputedStyle(document.body).backgroundColor") == 'rgb(255, 255, 255)', pg.evaluate("getComputedStyle(document.body).backgroundColor"))
    pg.goto(B + '/clients')
    check('Clients : filtres Tous / Expéditeurs / Destinataires', pg.locator('.chips a').count() == 3 and pg.locator('.chips .chip-count').count() == 3)
    pg.click('.chips a:has-text("Expéditeurs")'); pg.wait_for_load_state('networkidle')
    roles = pg.locator('td.roles').all_inner_texts()
    check('Filtre « Expéditeurs » : chaque client a envoyé un colis', roles and all('EXPÉDITEUR' in t.upper() for t in roles), roles[:5])
    pg.click('.chips a:has-text("Destinataires")'); pg.wait_for_load_state('networkidle')
    roles = pg.locator('td.roles').all_inner_texts()
    check('Filtre « Destinataires » : chaque client a reçu un colis', roles and all('DESTINATAIRE' in t.upper() for t in roles), roles[:5])
    pg.goto(B + '/colis')
    check('Colis : colonne « Paiement »', 'paiement' in pg.inner_text('table thead').lower() and pg.locator('td.pay-cell').count() >= 1)
    for state, label in [('paid', 'Payé'), ('unbilled', 'Non facturé'), ('overdue', 'En retard')]:
        pg.goto(B + '/colis?paiement=' + state)
        cells = [t.strip() for t in pg.locator('td.pay-cell .status').all_inner_texts()]
        check(f'Filtre paiement « {label} »', cells and all(c.lower().startswith(label.lower()) for c in cells), cells[:5])
    pg.goto(B + '/colis?paiement=paid')
    check('Le badge de paiement ouvre la facture', pg.locator('td.pay-cell a.status[href*="/factures/"]').count() >= 1)
    pg.goto(B + '/factures?status=open'); pg.click('table a:has-text("Encaisser")'); pg.wait_for_load_state('networkidle')
    pg.fill('#amount', '1000'); pg.select_option('#method', 'orange_money'); pg.fill('#reference', '')
    pg.click('form:has(#amount) button.btn-go'); pg.wait_for_load_state('networkidle')
    check('Paiement Mobile Money accepté sans référence', 'Paiement enregistré' in pg.inner_text('main') and 'obligatoire' not in pg.inner_text('main'), pg.inner_text('main')[:200])
    pg.goto(B + '/factures?status=open'); pg.click('table a.row-link >> nth=0'); pg.click('main a[href*="/clients/"] >> nth=0'); pg.wait_for_load_state('networkidle')
    href = pg.evaluate("(a => a && a.href)(document.querySelector('a[href*=\"/releve\"]'))")
    r = pg.request.get(href) if href else None
    check('Relevé de compte PDF depuis la fiche client', r is not None and r.ok and r.body()[:5] == b'%PDF-', href)
    check('Fiche client : boutons « Partager le relevé » et WhatsApp', pg.locator('#releve button:has-text("Partager le relevé")').count() == 1 and pg.locator('#releve [data-share-via="whatsapp"]').count() == 1)
    data = pg.evaluate("""async () => { const b = document.querySelector('#releve [data-share-endpoint]');
        const r = await fetch(b.dataset.shareEndpoint, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, credentials: 'same-origin' });
        return r.json(); }""")
    check('Message prêt : bonjour, totaux, lien', data.get('text', '').startswith('Bonjour ') and 'Déjà payé' in data['text'] and data['url'] in data['text'], data)
    check('Numéro WhatsApp international du client', (data.get('phone') or '').isdigit() and len(data['phone']) >= 11, data.get('phone'))
    pub = b.new_context(viewport={'width': 390, 'height': 844}).new_page()   # client : sans session
    pub.on('pageerror', lambda e: errs.append(str(e)))
    pub.goto(data['url'])
    check('Lien public : relevé mobile (en-tête, client, 3 totaux, versements)', pub.locator('.sh-head').count() == 1 and pub.locator('.sh-tile').count() >= 3 and 'historique des versements' in pub.inner_text('body').lower(), pub.inner_text('body')[:200])
    check('Lien public : pas de débordement horizontal', not pub.evaluate('document.documentElement.scrollWidth > innerWidth'))
    rp = pub.request.get(pub.evaluate("document.querySelector('.sh-btn').href"))
    check('Lien public : PDF du relevé', rp.ok and rp.body()[:5] == b'%PDF-')
    pub.goto(data['url'][:-3] + 'xyz')
    check('Lien falsifié refusé', 'invalide' in pub.inner_text('body') or 'expiré' in pub.inner_text('body'))
    pub.context.close()
    adm = b.new_context().new_page(); adm.goto(B + '/connexion'); adm.fill('#email', 'admin@diarrassouba.test'); adm.fill('#password', 'Admin2026secure')
    adm.click('form button.btn-primary'); adm.wait_for_load_state('networkidle'); adm.goto(B + '/parametres'); pg = adm
    check('Paramètres : adresse et e-mail de l’entreprise modifiables', pg.locator('#company_address').count() == 1 and pg.locator('#company_email').count() == 1)
    ctx.close()

    print('\nFluidité : pages préchargées, filtres immédiats, place conservée, suite proposée')
    ctx, pg = page()
    login(pg, 'manager')
    resp = pg.goto(B + '/colis')
    rules = pg.request.get(B + '/prechargement.json')
    check('Pages préchargées au survol (règles servies au bon format)', resp.headers.get('speculation-rules') and rules.ok and 'speculationrules+json' in rules.headers.get('content-type', ''))
    pg.select_option('select[name="statut"]', 'registered'); pg.wait_for_load_state('networkidle')
    check('Filtre appliqué dès le choix (sans bouton « Filtrer »)', 'statut=registered' in pg.url, pg.url)
    pg.goto(pg.evaluate("document.querySelector('table a.row-link').href") + '?suite=1')
    check('Après l’enregistrement d’un colis : « Et maintenant ? » avec étiquette et colis suivant', pg.locator('.next-parcel a').count() == 3)
    pg.click('.next-parcel a:has-text("Autre colis de")'); pg.wait_for_load_state('networkidle')
    check('« Autre colis » : expéditeur et destination déjà remplis', pg.locator('[data-clear]').count() >= 1 and pg.input_value('#destination_country') != '', pg.url)
    ctx.close()
    ctx, pg = page(390, 844)
    login(pg, 'caisse')
    pg.goto(B + '/factures?status=open'); pg.click('table a:has-text("Encaisser")'); pg.wait_for_load_state('networkidle')
    pg.fill('#amount', '999999999'); pg.select_option('#method', 'cash'); pg.click('form:has(#amount) button.btn-go'); pg.wait_for_load_state('networkidle')
    check('Erreur : curseur placé directement sur le champ à corriger', pg.evaluate("document.activeElement && document.activeElement.id") == 'amount')
    pg.fill('#amount', '500'); pg.select_option('#method', 'cash')
    pg.evaluate("document.getElementById('amount').scrollIntoView({block:'center'})")
    pg.click('form:has(#amount) button.btn-go'); pg.wait_for_load_state('networkidle')
    check('Après un paiement : on reste au même endroit de la page', pg.evaluate('scrollY') > 120, pg.evaluate('scrollY'))
    check('Message de confirmation visible même en bas de page', pg.locator('.toast-stack .flash-success').count() >= 1)
    ctx.close()
    check('Aucune erreur JavaScript', not errs, errs)
    b.close()
print(f'\n{sum(res)} réussi(s), {len(res) - sum(res)} échec(s)')

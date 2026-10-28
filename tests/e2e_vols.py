"""
Parcours « affecter des colis à un vol » et raccourcis du tableau de bord (Playwright + Chromium).
Prérequis : comme tests/e2e.py (serveur local + démonstration). Usage : python3 tests/e2e_vols.py [URL]
"""
import re, sys
from playwright.sync_api import sync_playwright
B = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
res = []
def check(n, c, d=''):
    res.append(bool(c)); print(('  ✔ ' if c else '  ✘ ') + n + ('' if c else f'  -> {d}'))

def login(pg, email, pw='Demo2026pass'):
    pg.goto(B + '/connexion'); pg.fill('#email', email); pg.fill('#password', pw)
    pg.click('form button.btn-primary'); pg.wait_for_load_state('networkidle')

def new_parcel(pg, desc, kg='4,2'):
    pg.goto(B + '/colis/nouveau')
    pg.fill('#sender_name', 'Test Expéditeur'); pg.fill('#sender_phone', '+20 100 000 ' + str(abs(hash(desc)) % 10000).zfill(4))
    pg.fill('#recipient_name', 'Test Destinataire'); pg.fill('#recipient_phone', '07 00 00 ' + str(abs(hash(desc + 'r')) % 10000).zfill(4))
    pg.fill('#description', desc); pg.fill('#weight_kg', kg)
    pg.select_option('#tariff_id', index=1)
    pg.click('form[data-parcel-form] button.btn-primary'); pg.wait_for_load_state('networkidle')
    return pg.inner_text('h1').strip(), pg.url

with sync_playwright() as p:
    b = p.chromium.launch()
    errs = []
    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errs.append(str(e)))

    # --- Comptoir : deux nouveaux colis
    login(pg, 'comptoir@diarrassouba.test')
    code1, url1 = new_parcel(pg, 'Colis test scan A')
    code2, url2 = new_parcel(pg, 'Colis test fiche B')
    check('Colis de test créés', code1.startswith('DI-') and code2.startswith('DI-'), (code1, code2))
    ctx.close()

    # --- Logistique : dossier en préparation, chargement par scan
    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errs.append(str(e)))
    login(pg, 'logistique@diarrassouba.test')
    if pg.locator('.quick a:has-text("Charger le vol")').count() == 0:
        # Aucun vol en préparation (base déjà utilisée) : le raccourci propose d'en préparer un
        check('Tableau de bord : raccourci « Préparer un vol » sans dossier ouvert', pg.locator('.quick a:has-text("Préparer un vol")').count() == 1)
        pg.click('.quick a:has-text("Préparer un vol")'); pg.wait_for_load_state('networkidle')
        pg.fill('#airline', 'EgyptAir'); pg.fill('#flight_number', 'MS 901')
        pg.click('form.panel button.btn-primary'); pg.wait_for_load_state('networkidle')
        pg.goto(B + '/tableau-de-bord'); pg.wait_for_load_state('networkidle')
    check('Tableau de bord : raccourci « Charger le vol »', pg.locator('.quick a:has-text("Charger le vol")').count() == 1)
    pg.click('.quick a:has-text("Charger le vol")'); pg.wait_for_load_state('networkidle')
    ship_url = pg.url.split('#')[0]
    check('Raccourci -> dossier en préparation, bloc « Charger le vol » en haut', pg.locator('#charger').is_visible())
    order = pg.evaluate("[...document.querySelectorAll('#charger, .next-step')].map(e => e.id || e.className.split(' ').pop())")
    check('« Étape suivante » puis « Charger le vol », tous deux en haut de page', order == ['next-step', 'charger'], order)
    check('Frise d’étapes : Préparation en cours', 'PRÉPARATION' in pg.inner_text('.steps li.now').upper())

    # Scan douchette : code tapé en minuscules sans tirets + Entrée
    pg.fill('#scan-code', code1.lower().replace('-', '')); pg.press('#scan-code', 'Enter'); pg.wait_for_load_state('networkidle')
    flash = pg.inner_text('main')
    check('Scan : colis ajouté, total affiché', 'ajouté au dossier' in flash and 'total' in flash, flash[:200])
    check('Scan : colis visible dans la liste du dossier', code1 in pg.inner_text('table'))
    check('Scan : champ prêt pour le colis suivant (focus)', pg.evaluate("document.activeElement && document.activeElement.id") == 'scan-code')
    pg.fill('#scan-code', code1); pg.press('#scan-code', 'Enter'); pg.wait_for_load_state('networkidle')
    check('Re-scan du même colis : averti « déjà dans ce dossier »', 'déjà dans ce dossier' in pg.inner_text('main'))
    pg.fill('#scan-code', 'DI-ZZZZ-ZZZZ'); pg.press('#scan-code', 'Enter'); pg.wait_for_load_state('networkidle')
    check('Code inconnu : message clair', 'Aucun colis ne porte le code' in pg.inner_text('main'))

    # Liste à cocher : compteur et filtre
    if pg.locator('[data-pick-list] label.pick').count():
        first = pg.locator('[data-pick-list] label.pick').first
        first.locator('input').check()
        check('Liste : compteur colis + poids', re.search(r'1 colis sélectionné · [\d,]+ kg', pg.inner_text('[data-pick-count]')), pg.inner_text('[data-pick-count]'))
        first.locator('input').uncheck()
    else:
        check('Liste : vide (tous chargés), message explicatif', 'Aucun autre colis en attente' in pg.inner_text('#charger'))
    ctx.close()

    # --- Fiche colis : « Mettre dans ce vol »
    ctx = b.new_context(viewport={'width': 390, 'height': 844}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errs.append(str(e)))
    login(pg, 'logistique@diarrassouba.test')
    pg.goto(url2); pg.wait_for_load_state('networkidle')
    check('Fiche colis (mobile) : bloc « Mettre dans un vol »', pg.locator('.load-panel .load-choice').count() >= 1)
    pg.click('.load-panel .load-choice'); pg.wait_for_load_state('networkidle')
    check('Un appui : retour sur la fiche, colis dans le vol', pg.url.startswith(url2) and 'Colis mis dans le vol' in pg.inner_text('main'), pg.url)
    check('Bloc retiré une fois le colis chargé', pg.locator('.load-panel').count() == 0)
    check('Dossier indiqué sur la fiche', re.search(r'EXP-\d{4}-\d{4}', pg.inner_text('.dl')))
    ctx.close()

    # --- Aucun vol ouvert : création guidée avec destination préremplie
    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errs.append(str(e)))
    login(pg, 'logistique@diarrassouba.test')
    pg.goto(ship_url); pg.select_option('#ship-status', 'departed'); pg.click('form[action$="/statut"] button'); pg.wait_for_load_state('networkidle')
    check('Départ confirmé : chargement verrouillé, explication affichée', pg.locator('.notice').count() == 1 and pg.locator('#charger').count() == 0)
    ctx.close()
    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    login(pg, 'comptoir@diarrassouba.test'); code3, url3 = new_parcel(pg, 'Colis test sans vol C'); ctx.close()
    ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
    pg.on('pageerror', lambda e: errs.append(str(e)))
    login(pg, 'logistique@diarrassouba.test')
    pg.goto(url3); pg.wait_for_load_state('networkidle')
    link = pg.locator('.load-panel a:has-text("Créer le dossier du vol")')
    check('Sans vol ouvert : bouton « Créer le dossier du vol »', link.count() == 1)
    link.click(); pg.wait_for_load_state('networkidle')
    check('Formulaire : destination préremplie', pg.input_value('#destination_country') == 'CI' and pg.input_value('#destination_city') != '')
    ctx.close()

    # --- Raccourcis par rôle
    for email, expect, absent in [('caisse@diarrassouba.test', 'Encaisser', 'Charger le vol'), ('comptoir@diarrassouba.test', 'Enregistrer un colis', 'Encaisser')]:
        ctx = b.new_context(viewport={'width': 390, 'height': 844}); pg = ctx.new_page()
        login(pg, email)
        t = pg.inner_text('.quick')
        check(f'Raccourcis {email.split("@")[0]} : « {expect} » présent, « {absent} » absent', expect in t and absent not in t, t)
        ctx.close()

    check('Aucune erreur JavaScript', not errs, errs)
    b.close()
print(f'{sum(res)}/{len(res)} réussis')

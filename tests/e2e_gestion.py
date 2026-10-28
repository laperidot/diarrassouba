"""
Vol choisi dès l'enregistrement d'un colis, changement de statut d'un dossier (tableau de bord et page dossier),
paramètres de l'entreprise (administrateur). Playwright + Chromium.
Prérequis : comme tests/e2e.py (serveur local + démonstration). Usage : python3 tests/e2e_gestion.py [URL]
"""
import re, sys
from playwright.sync_api import sync_playwright
B = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8080'
ADMIN = ('admin@diarrassouba.test', 'Admin2026secure')
DEMO_PW = 'Demo2026pass'
res = []

def check(n, c, d=''):
    res.append(bool(c)); print(('  ✔ ' if c else '  ✘ ') + n + ('' if c else f'  -> {d}'))

def login(pg, email, pw=DEMO_PW):
    pg.goto(B + '/connexion'); pg.fill('#email', email); pg.fill('#password', pw)
    pg.click('form button.btn-primary'); pg.wait_for_load_state('networkidle')

def fill_parcel(pg, desc):
    n = str(abs(hash(desc)) % 10000).zfill(4)
    pg.fill('#sender_name', 'Expéditeur ' + n); pg.fill('#sender_phone', '+20 100 111 ' + n)
    pg.fill('#recipient_name', 'Destinataire ' + n); pg.fill('#recipient_phone', '07 11 22 ' + n)
    pg.fill('#description', desc); pg.fill('#weight_kg', '3,5'); pg.select_option('#tariff_id', index=1)

def new_flight(pg, flight, dest='CI'):
    pg.goto(B + '/dossiers/nouveau'); pg.select_option('#destination_country', dest)
    pg.fill('#airline', 'EgyptAir'); pg.fill('#flight_number', flight)
    pg.click('form.panel button.btn-primary'); pg.wait_for_load_state('networkidle')
    return pg.url, pg.inner_text('h1').strip()

with sync_playwright() as p:
    b = p.chromium.launch()
    errs = []
    def page(w=1280, h=900):
        ctx = b.new_context(viewport={'width': w, 'height': h}); pg = ctx.new_page()
        pg.on('pageerror', lambda e: errs.append(str(e)))
        return ctx, pg

    print('\nVol choisi dans le formulaire du colis')
    ctx, pg = page()
    login(pg, 'logistique@diarrassouba.test')
    ship_url, ship_ref = new_flight(pg, 'MS 711')
    _, sn_ref = new_flight(pg, 'MS 712', 'SN')
    pg.goto(B + '/dossiers/nouveau'); pg.select_option('#destination_country', 'SN')
    check('Pays Sénégal choisi : ville d’arrivée = Dakar (suit le pays)', pg.input_value('#destination_city') == 'Dakar', pg.input_value('#destination_city'))
    pg.fill('#destination_city', 'Yamoussoukro'); pg.select_option('#destination_country', 'ML')
    check('Ville saisie à la main : conservée au changement de pays', pg.input_value('#destination_city') == 'Yamoussoukro')
    ctx.close()

    ctx, pg = page(390, 844)
    login(pg, 'comptoir@diarrassouba.test')   # le comptoir peut charger un vol en préparation
    pg.goto(B + '/colis/nouveau'); pg.wait_for_load_state('networkidle')
    opts = lambda: [t.strip().split('\n')[0] for t in pg.locator('.flight-option:visible').all_inner_texts()]
    check('Formulaire : liste des vols en préparation', pg.locator('[data-flight-pick]').count() == 1)
    check('Destination Côte d’Ivoire : vol CI proposé, vol Sénégal masqué', any(ship_ref in o for o in opts()) and not any(sn_ref in o for o in opts()), opts())
    pg.select_option('#destination_country', 'SN')
    check('Destination changée : vols mis à jour sans recharger', any(sn_ref in o for o in opts()) and not any(ship_ref in o for o in opts()), opts())
    pg.check(f'.flight-option:has-text("{sn_ref}") input')
    pg.select_option('#destination_country', 'CI')
    check('Vol d’une autre destination décoché automatiquement', pg.locator('input[name="shipment_id"][value=""]').is_checked())
    fill_parcel(pg, 'Colis direct au vol')
    pg.check(f'.flight-option:has-text("{ship_ref}") input')
    pg.click('form[data-parcel-form] button.btn-primary'); pg.wait_for_load_state('networkidle')
    code = pg.inner_text('h1').strip()
    check('Colis enregistré ET mis dans le vol choisi', f'mis dans le vol {ship_ref}' in pg.inner_text('main') and ship_ref in pg.inner_text('.dl'), pg.inner_text('main')[:200])
    pg.goto(B + '/colis/nouveau'); fill_parcel(pg, 'Colis sans vol')
    pg.click('form[data-parcel-form] button.btn-primary'); pg.wait_for_load_state('networkidle')
    check('« Plus tard » : colis enregistré, sans vol', 'Pas encore affecté' in pg.inner_text('.dl'))
    check('Comptoir : pas de bouton de changement de statut d’un vol', (pg.goto(ship_url) and pg.locator('form.status-form').count() == 0))
    check('Comptoir : peut charger (bloc « Charger le vol »), pas retirer', pg.locator('#charger').count() == 1 and pg.locator('button:has-text("Retirer")').count() == 0)
    ctx.close()

    print('\nStatut d’un dossier depuis le tableau de bord et la page dossier')
    ctx, pg = page()
    login(pg, 'logistique@diarrassouba.test')
    check('Tableau de bord : panneau « Vols en cours »', pg.locator('#flights-title').count() == 1 and ship_ref in pg.inner_text('.flights'))
    item = pg.locator(f'.flights > li:has-text("{ship_ref}")')
    check('Étape suivante présélectionnée : « Confirmer le départ »', item.locator('select').evaluate('s => s.options[s.selectedIndex].text') == 'Confirmer le départ')
    item.locator('button:has-text("Valider")').click(); pg.wait_for_load_state('networkidle')
    check('Départ confirmé depuis le tableau de bord, on y reste', pg.url.rstrip('/').endswith('/tableau-de-bord') and f'{ship_ref} : parti' in pg.inner_text('main'), pg.url)
    check('Colis du vol passés « Expédié »', 'expédié' in (pg.goto(B + '/colis?q=' + code) and pg.inner_text('table').lower()))
    pg.goto(B + '/tableau-de-bord')
    item = pg.locator(f'.flights > li:has-text("{sn_ref}")')
    item.locator('button:has-text("Valider")').click(); pg.wait_for_load_state('networkidle')
    check('Vol vide : départ refusé avec message clair, retour au tableau de bord', 'Ajoutez au moins un colis' in pg.inner_text('main') and pg.url.rstrip('/').endswith('/tableau-de-bord'))
    pg.goto(ship_url); pg.wait_for_load_state('networkidle')
    order = pg.evaluate("[...document.querySelectorAll('.steps, .next-step, .panel.flush')].map(e => e.className.split(' ').pop())")
    check('Page dossier : « Étape suivante » juste sous la frise', order[:2] == ['steps', 'next-step'], order)
    check('Page dossier : arrivée présélectionnée après le départ', pg.evaluate("(s => s.options[s.selectedIndex].text)(document.getElementById('ship-status'))") == 'Confirmer l’arrivée')
    pg.click('.next-step .status-more summary'); pg.fill('#ship-when', '2026-10-28T09:15')
    pg.click('.next-step form.status-form button.btn-primary'); pg.wait_for_load_state('networkidle')
    check('Arrivée confirmée depuis la page dossier, date réelle prise en compte', 'arrivé' in pg.inner_text('main').lower() and '28/10/2026 09:15' in pg.inner_text('.dl'), pg.inner_text('.dl')[:300])
    ctx.close()

    print('\nParamètres de l’entreprise')
    ctx, pg = page()
    login(pg, 'manager@diarrassouba.test')
    check('Responsable : paramètres interdits (403)', pg.goto(B + '/parametres').status == 403)
    ctx.close()
    ctx, pg = page()
    login(pg, *ADMIN)
    check('Admin : lien « Paramètres » dans la navigation', pg.locator('.sidebar a[href$="/parametres"]').count() == 1)
    pg.goto(B + '/parametres')
    pg.fill('#company_phone', 'appelez-nous'); pg.click('form.form button.btn-primary'); pg.wait_for_load_state('networkidle')
    check('Téléphone invalide refusé, champ signalé', pg.locator('#company_phone[aria-invalid="true"]').count() == 1)
    pg.fill('#company_phone', '+225 07 77 88 99 00')
    pg.fill('#company_depot_info', 'Dépôt de Treichville, lundi au samedi 8 h - 18 h')
    check('Aperçu client mis à jour en direct', 'Treichville' in pg.inner_text('[data-preview="COMPANY_DEPOT_INFO"]'))
    pg.click('form.form button.btn-primary'); pg.wait_for_load_state('networkidle')
    check('Paramètres enregistrés', 'Paramètres enregistrés' in pg.inner_text('main') and pg.input_value('#company_phone') == '+225 07 77 88 99 00')
    pg.goto(B + '/suivi/' + code)
    check('Suivi public : nouveau téléphone affiché', '+225 07 77 88 99 00' in pg.inner_text('body'))
    r = pg.request.get(B + '/colis/1/etiquette')
    check('Étiquette PDF générée avec les nouveaux paramètres', r.status == 200 and r.body()[:5] == b'%PDF-')
    ctx.close()

    check('Aucune erreur JavaScript', not errs, errs)
    b.close()
print(f'\n{sum(res)} réussi(s), {len(res) - sum(res)} échec(s)')

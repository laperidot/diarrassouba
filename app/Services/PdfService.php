<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Domain;
use App\Core\Money;
use App\Core\Pdf;
use App\Core\Settings;

/** Mise en page des documents PDF : factures/avoirs, reçus, relevés de compte, étiquettes colis. */
final class PdfService
{
    // Palette « Air Waybill », version document (voir docs/DESIGN.md) : bandeau nuit à coupe oblique, filets orange, feuillet blanc
    private const INK = '#15171C';
    private const MUTED = '#5E6470';
    private const RULE = '#E3E6EB';
    private const WHITE = '#FFFFFF';
    private const NIGHT = '#14171D';          // bandeaux, en-têtes de tableau, étiquettes de bloc
    private const ON_NIGHT_MUTED = '#A9AEB8';
    private const BAND = '#FF5A1F';           // filets, pan de la colonne montant, total
    private const BAND_DIM = '#6B2E15';       // second filet, sur fond nuit
    private const ACCENT = '#B53A0A';         // orange aviation, version texte sur blanc
    private const ZEBRA = '#F4F5F7';          // lignes alternées
    private const POSITIVE = '#0C7550';       // solde réglé
    private const SETTLED = '#2CC28A';        // bloc « soldé »
    private const NEGATIVE = '#B12A1F';       // solde dû

    /**
     * Bandeau de tête : société sur fond nuit à gauche, titre et numéro du document à droite,
     * filets orange obliques ; un liseré orange prolonge la coupe sous le bandeau.
     * Retourne l'ordonnée sous le liseré.
     */
    private function header(Pdf $pdf, string $docTitle, string $number, float $m = 40, float $h = 104): float
    {
        $w = $pdf->pageWidth();
        $small = $h < 100;   // format A5 (reçu) : bandeau plus bas, coupe décalée pour laisser place au nom
        $x = $w * ($small ? 0.62 : 0.55);
        $s = $h / 2; // décalage horizontal de la coupe
        $pdf->rect(0, 0, $w, $h, self::NIGHT);
        $pdf->polygon([[$x, 0], [$x + 22, 0], [$x + 22 - $s, $h], [$x - $s, $h]], self::BAND);
        $pdf->polygon([[$x + 32, 0], [$x + 38, 0], [$x + 38 - $s, $h], [$x + 32 - $s, $h]], self::BAND_DIM);
        $pdf->polygon([[0, $h], [$x - $s, $h], [$x - $s - 5, $h + 10], [0, $h + 10]], self::BAND);

        $pdf->font('B', $small ? 12 : 15);
        $pdf->text($m, $small ? 20 : 26, (string) Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT'), self::WHITE);
        $contact = trim(Settings::get('COMPANY_PHONE') . '   ' . Settings::get('COMPANY_EMAIL', ''));
        $lines = $small ? [Settings::get('COMPANY_ADDRESS'), $contact] : [
            Config::get('COMPANY_TAGLINE', 'Transport aérien de colis — Égypte, Côte d’Ivoire, Afrique de l’Ouest'),
            Settings::get('COMPANY_ADDRESS'), $contact, Settings::get('COMPANY_LEGAL'),
        ];
        $pdf->font('R', $small ? 7.5 : 8);
        $y = $small ? 39 : 48;
        foreach (array_filter($lines) as $line) {
            foreach ($pdf->wrap((string) $line, $x - $s - $m - 30) as $l) {
                if ($y > $h - 16) {
                    break 2;
                }
                $pdf->text($m, $y, $l, self::ON_NIGHT_MUTED);
                $y += $small ? 10 : 11;
            }
        }

        $size = $small ? 20 : 24;
        do {
            $pdf->font('B', $size);
        } while ($pdf->textWidth($docTitle) > $w - $m - $x - 30 && --$size > 12);
        $pdf->textRight($w - $m, $small ? 18 : 24, $docTitle, self::BAND);
        $pdf->font('B', $small ? 9.5 : 10.5);
        $pdf->textRight($w - $m, $small ? 48 : 58, $number, self::WHITE);
        return $h + 10;
    }

    /** Page suivante d'un long document : liseré de tête réduit ; le pied de la page quittée annonce la suite. */
    private function continuation(Pdf $pdf, string $title): float
    {
        $this->footer($pdf, 'Suite page suivante.');
        $pdf->addPage();
        $w = $pdf->pageWidth();
        $x = $w * 0.55;
        $pdf->rect(0, 0, $w, 30, self::NIGHT);
        $pdf->polygon([[$x, 0], [$x + 22, 0], [$x + 7, 30], [$x - 15, 30]], self::BAND);
        $pdf->font('B', 9.5);
        $pdf->text(40, 10, $title . ' (suite)', self::WHITE);
        return 56;
    }

    /** Pied de page : note au-dessus, bandeau nuit avec pan orange oblique (téléphone), e-mail et adresse de suivi. */
    private function footer(Pdf $pdf, string $note = '', float $m = 40): void
    {
        $w = $pdf->pageWidth();
        $bottom = $pdf->pageHeight();
        $h = 28;
        if ($note !== '') {
            $pdf->font('R', 7.5);
            $lines = $pdf->wrap($note, $w - 2 * $m);
            $ny = $bottom - $h - 16 - 9 * (count($lines) - 1);
            foreach ($lines as $l) {
                $pdf->text($m, $ny, $l, self::MUTED);
                $ny += 9;
            }
        }
        $pdf->rect(0, $bottom - $h, $w, $h, self::NIGHT);
        $ox = $w * 0.38;
        $pdf->polygon([[0, $bottom - $h], [$ox, $bottom - $h], [$ox - $h / 2, $bottom], [0, $bottom]], self::BAND);
        $pdf->font('B', 8.5);
        $pdf->text($m, $bottom - $h + 10, (string) Settings::get('COMPANY_PHONE'), self::NIGHT);
        $pdf->font('R', 7.5);
        $track = 'Suivi : ' . preg_replace('#^https?://#', '', absolute_url('suivi'));
        $right = implode('   ·   ', array_filter([Settings::get('COMPANY_EMAIL'), $track]));
        if ($pdf->textWidth($right) > $w - $ox - $m - 10) {
            $right = $track;
        }
        $pdf->textRight($w - $m, $bottom - $h + 10.5, $right, self::WHITE);
    }

    /** Étiquette de bloc en capitales : blanc sur fond nuit, ombre orange décalée. Retourne l'ordonnée du contenu. */
    private function tag(Pdf $pdf, float $x, float $y, string $label): float
    {
        $label = mb_strtoupper($label);
        $pdf->font('B', 7.5);
        $tw = $pdf->textWidth($label) + 14;
        $pdf->rect($x + 3, $y + 3, $tw, 15, self::BAND);
        $pdf->rect($x, $y, $tw, 15, self::NIGHT);
        $pdf->text($x + 7, $y + 4.2, $label, self::WHITE);
        return $y + 27;
    }

    /** Bloc client : étiquette, nom, coordonnées. Retourne l'ordonnée sous le bloc. */
    private function party(Pdf $pdf, float $x, float $y, float $width, string $label, array $c): float
    {
        $y = $this->tag($pdf, $x, $y, $label);
        $pdf->font('B', 12.5);
        foreach (array_slice($pdf->wrap((string) $c['name'], $width), 0, 2) as $l) {
            $pdf->text($x, $y, $l, self::INK);
            $y += 15;
        }
        $pdf->font('R', 8.5);
        $y += 2;
        foreach (array_filter([
            'Client ' . ($c['code'] ?? ''), $c['phone'] ?? null, $c['email'] ?? null,
            trim(($c['address'] ?? '') . ' ' . ($c['city'] ?? '')),
        ]) as $l) {
            $pdf->text($x, $y, $l, self::MUTED);
            $y += 11.5;
        }
        return $y;
    }

    /** Références du document, puces orange, alignées sur la marge droite. */
    private function meta(Pdf $pdf, float $y, array $meta, float $m = 40, float $width = 210): float
    {
        $w = $pdf->pageWidth();
        foreach ($meta as $k => $v) {
            $pdf->rect($w - $m - $width, $y + 2.2, 5, 5, self::BAND);
            $pdf->font('R', 8.5);
            $pdf->text($w - $m - $width + 12, $y, (string) $k, self::MUTED);
            $pdf->font('B', 8.5);
            $pdf->textRight($w - $m, $y, (string) $v, self::INK);
            $y += 14;
        }
        return $y;
    }

    /**
     * En-tête de tableau : bande nuit, dernière colonne sur un pan orange à coupe oblique.
     * $cols : [libellé, x, 'L'|'R'] (x = bord gauche, ou bord droit pour 'R') ; $panX : début du pan orange.
     */
    private function tableHead(Pdf $pdf, float $y, array $cols, float $panX, float $l = 40, ?float $r = null): float
    {
        $r ??= $pdf->pageWidth() - $l;
        $pdf->rect($l, $y, $r - $l, 22, self::NIGHT);
        $pdf->polygon([[$panX + 11, $y], [$r, $y], [$r, $y + 22], [$panX, $y + 22]], self::BAND);
        $pdf->font('B', 7.5);
        $last = count($cols) - 1;
        foreach (array_values($cols) as $i => [$label, $x, $align]) {
            $label = mb_strtoupper($label);
            $color = $i === $last ? self::NIGHT : self::WHITE;
            $align === 'R' ? $pdf->textRight($x, $y + 7.5, $label, $color) : $pdf->text($x, $y + 7.5, $label, $color);
        }
        return $y + 22;
    }

    /** Ligne de total mise en avant : pan à coupe oblique (orange, ou vert quand le solde est nul), texte nuit. */
    private function totalBlock(Pdf $pdf, float $x, float $y, float $r, string $label, string $amount, string $fill = self::BAND): float
    {
        $h = 26;
        $pdf->polygon([[$x + 10, $y], [$r, $y], [$r, $y + $h], [$x, $y + $h]], $fill);
        $pdf->font('B', 9.5);
        $pdf->text($x + 16, $y + 9, mb_strtoupper($label), self::NIGHT);
        $pdf->font('B', 12);
        $pdf->textRight($r - 10, $y + 8, $amount, self::NIGHT);
        return $y + $h;
    }

    /** Ligne de récapitulatif (libellé à gauche, montant à droite, filet dessous). */
    private function sumRow(Pdf $pdf, float $x, float $y, float $r, string $label, string $amount, string $color = self::INK, bool $bold = false): float
    {
        $pdf->font($bold ? 'B' : 'R', $bold ? 10 : 9);
        $pdf->text($x, $y, $label, $bold ? self::INK : self::MUTED);
        $pdf->textRight($r - 10, $y, $amount, $color);
        $pdf->line($x, $y + 14, $r, $y + 14, self::RULE, 0.5);
        return $y + 20;
    }

    /** Zone de signature à droite. */
    private function signature(Pdf $pdf, float $y, float $r, string $caption): void
    {
        $pdf->line($r - 170, $y, $r, $y, self::INK, 0.6);
        $pdf->font('R', 7.5);
        $pdf->textCenter($r - 85, $y + 5, $caption, self::MUTED);
    }

    public function invoice(array $inv, array $items, array $payments, array $installments): string
    {
        $isCredit = $inv['kind'] === 'credit_note';
        $cur = $inv['currency'];
        $name = ($isCredit ? 'Annulation ' : 'Facture ') . ($inv['number'] ?? 'brouillon');
        $pdf = new Pdf();
        $pdf->setTitle($name);
        $pdf->addPage();
        $l = 40;
        $r = $pdf->pageWidth() - 40;
        $title = $isCredit ? 'ANNULATION' : ($inv['status'] === 'draft' ? 'BROUILLON' : 'FACTURE');
        $y = $this->header($pdf, $title, 'N° ' . ($inv['number'] ?? 'pas encore attribué')) + 24;

        // Client (instantané figé à la validation) et références
        $client = $inv['client_snapshot'] ? json_decode($inv['client_snapshot'], true) : [
            'name' => $inv['client_name'], 'code' => $inv['client_code'], 'phone' => $inv['client_phone'],
            'address' => $inv['client_address'], 'city' => $inv['client_city'], 'email' => $inv['client_email'],
        ];
        $meta = ['Date' => d($inv['issue_date'] ?? null)];
        if ($isCredit) {
            $meta['Annule la facture'] = $inv['credit_of_number'] ?? '—';
        } else {
            $meta['À payer avant le'] = d($inv['due_date']);
            if ($inv['payment_terms']) {
                $meta['Paiement'] = Domain::PAYMENT_TERMS[$inv['payment_terms']] ?? '';
            }
        }
        if ($inv['shipment_reference']) {
            $meta['Vol'] = $inv['shipment_reference'];
        }
        $y = max($this->party($pdf, $l, $y, 260, $isCredit ? 'Pour' : 'Facturé à', $client), $this->meta($pdf, $y + 4, $meta)) + 20;

        // Lignes : une par colis, lignes alternées
        $cols = [['Colis', $l + 10, 'L'], ['Contenu', 128, 'L'], ['Poids', 382, 'R'], ['Prix / kilo', 450, 'R'], ['Prix', $r - 10, 'R']];
        $y = $this->tableHead($pdf, $y, $cols, 462);
        foreach (array_values($items) as $n => $it) {
            $pdf->font('R', 8.5);
            $desc = array_slice($pdf->wrap((string) $it['description'], 190), 0, 3);
            $fees = (int) $it['fees_minor'] !== 0;
            $rh = 27 + (count($desc) - 1) * 11 + ($fees ? 12 : 0);
            if ($y + $rh > 700) {
                $y = $this->tableHead($pdf, $this->continuation($pdf, $name), $cols, 462);
            }
            if ($n % 2 === 1) {
                $pdf->rect($l, $y, $r - $l, $rh, self::ZEBRA);
            }
            $ty = $y + 7;
            $pdf->font('B', 8.5);
            $pdf->text($l + 10, $ty, (string) ($it['tracking_code'] ?? '—'), self::INK);
            $pdf->textRight($r - 10, $ty, Money::format((int) $it['transport_minor'], $cur, false), self::INK);
            $pdf->font('R', 8.5);
            $pdf->text(128, $ty, $desc[0], self::INK);
            $pdf->textRight(382, $ty, kg((int) $it['billable_weight_g']), self::INK);
            $pdf->textRight(450, $ty, Money::format((int) $it['rate_per_kg_minor'], $cur, false), self::INK);
            $ty += 13;
            foreach (array_slice($desc, 1) as $line) {
                $pdf->text(128, $ty, $line, self::MUTED);
                $ty += 11;
            }
            if ($fees) {
                $pdf->font('R', 8);
                $pdf->text(128, $ty, '+ ' . ($it['fees_label'] ?: 'Frais supplémentaires'), self::MUTED);
                $pdf->textRight($r - 10, $ty, Money::format((int) $it['fees_minor'], $cur, false), self::MUTED);
            }
            $y += $rh;
        }
        $pdf->line($l, $y, $r, $y, self::NIGHT, 1.2);

        // Totaux (à droite)
        $y += 18;
        if ($y > 590) {
            $y = $this->continuation($pdf, $name);
        }
        $top = $y;
        $tx = 345;
        $y = $this->sumRow($pdf, $tx, $y, $r, 'Transport', Money::format((int) $inv['subtotal_minor'], $cur));
        if ((int) $inv['fees_minor'] !== 0) {
            $y = $this->sumRow($pdf, $tx, $y, $r, 'Frais en plus', Money::format((int) $inv['fees_minor'], $cur));
        }
        if ((int) $inv['tax_rate_bp'] > 0) {
            $rate = rtrim(rtrim(number_format($inv['tax_rate_bp'] / 100, 2, ',', ''), '0'), ',');
            $y = $this->sumRow($pdf, $tx, $y, $r, 'Taxe ' . $rate . ' %', Money::format((int) $inv['tax_minor'], $cur));
        }
        $y = $this->totalBlock($pdf, $tx - 14, $y, $r, $isCredit ? 'Total annulé' : 'Total à payer', Money::format((int) $inv['total_minor'], $cur)) + 14;
        if (!$isCredit && $inv['status'] !== 'draft') {
            $balance = in_array($inv['status'], ['credited', 'cancelled'], true) ? 0 : (int) $inv['total_minor'] - (int) $inv['paid_minor'];
            $y = $this->sumRow($pdf, $tx, $y, $r, 'Déjà payé', Money::format((int) $inv['paid_minor'], $cur));
            $y = $this->sumRow($pdf, $tx, $y, $r, 'Reste à payer', Money::format($balance, $cur), $balance > 0 ? self::NEGATIVE : self::POSITIVE, true);
        }

        // Règlement, échéancier et paiements reçus (à gauche)
        $ly = $top;
        if (!$isCredit) {
            $ly = $this->tag($pdf, $l, $ly, 'Comment payer');
            $pdf->font('R', 8.5);
            $ly = $pdf->paragraph($l, $ly, 270, (string) Settings::get('COMPANY_PAYMENT_INFO', 'Espèces, Mobile Money ou virement.'), 1.35, self::INK);
            if ($inv['number']) {
                $ly = $pdf->paragraph($l, $ly, 270, 'Donnez le numéro ' . $inv['number'] . ' quand vous payez.', 1.35, self::MUTED);
            }
            $ly += 10;
        }
        foreach ([['Paiement en plusieurs fois', $installments], ['Paiements reçus', $payments]] as [$label, $list]) {
            if (!$list) {
                continue;
            }
            $ly = $this->tag($pdf, $l, $ly, $label);
            $pdf->font('R', 8.5);
            foreach ($list as $row) {
                $text = isset($row['receipt_number'])
                    ? dt($row['paid_at'], 'd/m/Y') . '  ' . $row['receipt_number'] . '  ' . (Domain::PAYMENT_METHODS[$row['method']] ?? $row['method'])
                    : d($row['due_date']) . ($row['label'] ? '  ' . $row['label'] : '');
                $pdf->text($l, $ly, $text, self::INK);
                $pdf->textRight($l + 270, $ly, Money::format((int) $row['amount_minor'], $cur), self::INK);
                $ly += 12.5;
            }
            $ly += 10;
        }

        $y = max($y, $ly) + 8;
        if ($inv['status'] === 'cancelled' || $inv['status'] === 'credited') {
            $pdf->font('B', 10);
            $y = $pdf->paragraph($l, $y, $r - $l, ($inv['status'] === 'credited' ? 'Facture annulée (facture d’annulation faite)' : 'Facture annulée') . ' le ' . dt($inv['cancelled_at'], 'd/m/Y') . ' : ' . ($inv['cancel_reason'] ?? ''), 1.35, self::NEGATIVE) + 6;
        }
        if ($inv['notes']) {
            $pdf->font('R', 8.5);
            $y = $pdf->paragraph($l, $y, $r - $l, (string) $inv['notes'], 1.35, self::MUTED) + 6;
        }
        if ($y > 730) {
            $y = $this->continuation($pdf, $name);
        }
        $pdf->font('B', 11);
        $pdf->text($l, $y + 14, 'Merci de votre confiance.', self::INK);
        $this->signature($pdf, $y + 36, $r, 'Cachet et signature');
        $this->footer($pdf, 'Montants en ' . (Domain::CURRENCIES[$cur]['label'] ?? $cur) . '. Prix au kilo : le poids est arrondi aux ' . Config::int('BILLING_WEIGHT_STEP_G', 100) . ' g du dessus.');
        return $pdf->output();
    }

    public function receipt(array $pay, array $inv): string
    {
        $pdf = new Pdf(419.53, 595.28); // A5
        $reversal = (int) $pay['amount_minor'] < 0;
        $pdf->setTitle('Reçu ' . $pay['receipt_number']);
        $pdf->addPage();
        $m = 28;
        $r = $pdf->pageWidth() - $m;
        $y = $this->header($pdf, $reversal ? 'ANNULATION' : 'REÇU', 'N° ' . $pay['receipt_number'], $m, 84) + 20;

        // Montant
        $pdf->rect($m, $y, $r - $m, 58, self::ZEBRA);
        $pdf->rect($m, $y, 5, 58, self::BAND);
        $pdf->font('R', 8);
        $pdf->text($m + 18, $y + 11, $reversal ? 'Montant annulé' : 'Montant reçu', self::MUTED);
        $pdf->textRight($r - 14, $y + 11, dt($pay['paid_at'], 'd/m/Y à H:i'), self::MUTED);
        $pdf->font('B', 22);
        $pdf->text($m + 18, $y + 25, Money::format(abs((int) $pay['amount_minor']), $pay['currency']), self::INK);
        $y += 74;

        // Détail, lignes alternées
        $rows = [
            'Client' => $pay['client_name'] . ' (' . $pay['client_code'] . ')',
            'Facture' => (string) $pay['invoice_number'],
            'Payé par' => Domain::PAYMENT_METHODS[$pay['method']] ?? $pay['method'],
        ];
        if ($pay['reference']) {
            $rows['N° de transaction'] = $pay['reference'];
        }
        $rows['Enregistré'] = ($pay['confirmation'] === 'provider' ? 'Confirmé par l’opérateur' : 'À la caisse') . ', par ' . ($pay['recorded_by_name'] ?? '—');
        if ($reversal) {
            $rows['Annule le reçu'] = (string) $pay['reversal_of_receipt'];
        }
        $y = $this->tag($pdf, $m, $y, $reversal ? 'Détail de l’annulation' : 'Détail du paiement');
        $n = 0;
        foreach ($rows as $k => $v) {
            $pdf->font('B', 9);
            $lines = $pdf->wrap($v, $r - $m - 130);
            $rh = 10 + count($lines) * 12;
            if ($n++ % 2 === 0) {
                $pdf->rect($m, $y, $r - $m, $rh, self::ZEBRA);
            }
            foreach ($lines as $i => $line) {
                $pdf->text($m + 120, $y + 6 + $i * 12, $line, self::INK);
            }
            $pdf->font('R', 8.5);
            $pdf->text($m + 10, $y + 6.5, $k, self::MUTED);
            $y += $rh;
        }

        // Situation de la facture
        $balance = in_array($inv['status'], ['credited', 'cancelled'], true) ? 0 : (int) $inv['total_minor'] - (int) $inv['paid_minor'];
        $y += 16;
        $tx = $m + 130;
        $y = $this->sumRow($pdf, $tx, $y, $r, 'Total facture', Money::format((int) $inv['total_minor'], $inv['currency']));
        $y = $this->sumRow($pdf, $tx, $y, $r, 'Déjà payé en tout', Money::format((int) $inv['paid_minor'], $inv['currency']));
        $y = $this->totalBlock($pdf, $tx - 14, $y, $r, $balance > 0 ? 'Reste à payer' : 'Tout est payé', Money::format($balance, $inv['currency']), $balance > 0 ? self::BAND : self::SETTLED);
        if ($pay['notes']) {
            $pdf->font('R', 8);
            $y = $pdf->paragraph($m, $y + 14, $r - $m, (string) $pay['notes'], 1.35, self::MUTED) - 14;
        }
        $this->signature($pdf, min($y + 50, $pdf->pageHeight() - 80), $r, 'Pour la caisse');
        $this->footer($pdf, 'Fait le ' . dt(gmdate('Y-m-d H:i:s')) . '. Gardez ce reçu.', $m);
        return $pdf->output();
    }

    /** Relevé de compte client (données : InvoiceService::statement), un tableau par devise. */
    public function statement(array $client, array $data): string
    {
        $name = 'Relevé de compte ' . $client['code'];
        $pdf = new Pdf();
        $pdf->setTitle($name);
        $pdf->addPage();
        $l = 40;
        $r = $pdf->pageWidth() - 40;
        $y = $this->header($pdf, 'RELEVÉ', 'Client ' . $client['code']) + 24;
        $accounts = $data['accounts'];
        $meta = [
            'Fait le' => d($data['today']),
            'Période' => $data['from'] ? 'du ' . d($data['from']) . ' au ' . d($data['to']) : 'jusqu’au ' . d($data['to']),
        ];
        if ($accounts) {
            $meta[count($accounts) > 1 ? 'Monnaies' : 'Monnaie'] = implode(', ', array_map(fn ($c) => Domain::CURRENCIES[$c]['symbol'] ?? $c, array_keys($accounts)));
        }
        $y = max($this->party($pdf, $l, $y, 260, 'Client', $client), $this->meta($pdf, $y + 4, $meta)) + 20;
        if (!$accounts) {
            $pdf->font('R', 10);
            $pdf->text($l, $y, 'Pas encore de facture ni de paiement pour ce client.', self::MUTED);
        }

        $cols = [['Date', $l + 10, 'L'], ['Quoi', 102, 'L'], ['N°', 292, 'L'], ['À payer', 418, 'R'], ['Payé', 486, 'R'], ['Reste', $r - 10, 'R']];
        foreach ($accounts as $cur => $a) {
            if ($y > 560) {
                $y = $this->continuation($pdf, $name);
            }
            if (count($accounts) > 1) {
                $pdf->font('B', 10.5);
                $pdf->text($l, $y, 'En ' . (Domain::CURRENCIES[$cur]['label'] ?? $cur), self::ACCENT);
                $y += 20;
            }
            // Synthèse en quatre cases ; la dernière (solde) en pan orange
            $period = $data['from'] !== null;
            $boxes = [
                [$period ? 'Facturé sur la période' : 'Total à payer', $a['invoiced'], self::INK],
                [$period ? 'Payé sur la période' : 'Déjà payé', $a['settled'], self::INK],
                ['Dont en retard', $a['overdue'], $a['overdue'] > 0 ? self::NEGATIVE : self::INK],
                [$a['balance'] < 0 ? 'En votre faveur' : ($a['balance'] > 0 ? 'Reste à payer' : 'Tout est payé'), abs($a['balance']), self::NIGHT],
            ];
            $bw = ($r - $l - 3 * 8) / 4;
            foreach ($boxes as $i => [$label, $amount, $color]) {
                $bx = $l + $i * ($bw + 8);
                if ($i === 3) {
                    $pdf->polygon([[$bx + 10, $y], [$bx + $bw, $y], [$bx + $bw, $y + 50], [$bx, $y + 50]], $a['balance'] > 0 ? self::BAND : self::SETTLED);
                } else {
                    $pdf->rect($bx, $y, $bw, 50, self::ZEBRA);
                    $pdf->rect($bx, $y, $bw, 3, self::NIGHT);
                }
                $pdf->font('R', 7.5);
                $pdf->text($bx + 12, $y + 12, mb_strtoupper($label), $i === 3 ? self::NIGHT : self::MUTED);
                $pdf->font('B', 12);
                $pdf->text($bx + 12, $y + 27, Money::format((int) $amount, $cur), $color);
            }
            $y += 66;

            // Mouvements avec solde progressif
            $y = $this->tableHead($pdf, $y, $cols, 494);
            $rows = $a['rows'];
            if ($period) {
                array_unshift($rows, ['date' => $data['from'], 'label' => 'Reste d’avant cette période', 'piece' => '', 'amount' => 0, 'balance' => $a['opening'], 'carry' => true]);
            }
            foreach ($rows as $n => $mv) {
                $pdf->font('R', 8.5);
                $lines = array_slice($pdf->wrap((string) $mv['label'], 182), 0, 2);
                $rh = 12 + count($lines) * 11;
                if ($y + $rh > 740) {
                    $y = $this->tableHead($pdf, $this->continuation($pdf, $name), $cols, 494);
                }
                if ($n % 2 === 1) {
                    $pdf->rect($l, $y, $r - $l, $rh, self::ZEBRA);
                }
                $ty = $y + 6.5;
                $pdf->text($l + 10, $ty, d($mv['date']), self::INK);
                $pdf->font(empty($mv['carry']) ? 'R' : 'B', 8.5);
                foreach ($lines as $i => $line) {
                    $pdf->text(102, $ty + $i * 11, $line, self::INK);
                }
                $pdf->font('R', 8);
                $pdf->text(292, $ty + 0.5, (string) $mv['piece'], self::MUTED);
                $pdf->font('R', 8.5);
                if ($mv['amount'] > 0) {
                    $pdf->textRight(418, $ty, Money::format($mv['amount'], $cur, false), self::INK);
                } elseif ($mv['amount'] < 0) {
                    $pdf->textRight(486, $ty, Money::format(-$mv['amount'], $cur, false), self::POSITIVE);
                }
                $pdf->font('B', 8.5);
                $pdf->textRight($r - 10, $ty, Money::format((int) $mv['balance'], $cur, false), self::INK);
                $y += $rh;
            }
            $pdf->line($l, $y, $r, $y, self::NIGHT, 1.2);
            $y = $this->totalBlock($pdf, 300, $y + 12, $r, 'Reste au ' . d($data['to']), Money::format($a['balance'], $cur), $a['balance'] > 0 ? self::BAND : self::SETTLED) + 22;

            // Factures restant à régler
            if ($a['open']) {
                if ($y + 40 > 740) {
                    $y = $this->continuation($pdf, $name);
                }
                $y = $this->tag($pdf, $l, $y, 'Factures pas encore payées');
                foreach ($a['open'] as $o) {
                    if ($y > 750) {
                        $y = $this->continuation($pdf, $name);
                    }
                    $pdf->font('B', 8.5);
                    $pdf->text($l, $y, (string) $o['number'], self::INK);
                    $pdf->font('R', 8.5);
                    $pdf->text($l + 110, $y, 'émise le ' . d($o['issue_date']), self::MUTED);
                    $pdf->text($l + 210, $y, $o['due_date'] ? 'échéance ' . d($o['due_date']) . ($o['late'] ? ' (échue)' : '') : 'sans échéance', $o['late'] ? self::NEGATIVE : self::MUTED);
                    $pdf->font('B', 8.5);
                    $pdf->textRight($r - 10, $y, Money::format($o['due'], $cur), $o['late'] ? self::NEGATIVE : self::INK);
                    $pdf->line($l, $y + 14, $r, $y + 14, self::RULE, 0.5);
                    $y += 20;
                }
                $y += 14;
            }
        }
        $this->footer($pdf, '« À payer » : vos factures. « Payé » : vos paiements. « Reste » : ce qu’il reste à payer après chaque ligne (négatif = en votre faveur). ' . Settings::get('COMPANY_PAYMENT_INFO', ''));
        return $pdf->output();
    }

    /** Étiquette 100 × 150 mm (imprimante thermique ou A4 découpé). */
    public function label(array $p): string
    {
        $pdf = new Pdf(283.46, 425.2);
        $pdf->setTitle('Étiquette ' . $p['tracking_code']);
        $pdf->addPage();
        $w = $pdf->pageWidth();
        $pdf->font('B', 9);
        $pdf->text(14, 14, (string) Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT'), self::INK);
        $pdf->font('R', 7.5);
        $pdf->textRight($w - 14, 15, (string) Settings::get('COMPANY_PHONE'), self::INK);
        $pdf->line(14, 30, $w - 14, 30, '#000000', 1.2);

        $from = \App\Core\Domain::iata($p['origin_city'], $p['origin_country']);
        $to = \App\Core\Domain::iata($p['destination_city'], $p['destination_country']);
        $pdf->font('B', 40);
        $pdf->text(14, 40, $from, '#000000');
        $pdf->textRight($w - 14, 40, $to, '#000000');
        $pdf->line(14 + $pdf->textWidth($from) + 10, 62, $w - 24 - $pdf->textWidth($to), 62, '#000000', 1.5);
        $pdf->font('R', 8);
        $pdf->text(14, 86, (string) ($p['origin_city'] ?: country_name($p['origin_country'])), '#000000');
        $pdf->textRight($w - 14, 86, (string) ($p['destination_city'] ?: country_name($p['destination_country'])), '#000000');
        $pdf->line(14, 102, $w - 14, 102, '#000000', 0.6);

        $pdf->font('R', 7.5);
        $pdf->text(14, 110, 'DESTINATAIRE', '#000000');
        $pdf->font('B', 13);
        $y = $pdf->paragraph(14, 122, $w - 28, (string) $p['recipient_name'], 1.15, '#000000');
        $pdf->font('R', 10);
        $pdf->text(14, $y + 2, (string) ($p['delivery_contact_phone'] ?: $p['recipient_phone']), '#000000');
        $pdf->font('R', 7.5);
        $pdf->text(14, $y + 22, 'Expéditeur : ' . $p['sender_name'], '#000000');
        $pdf->line(14, 214, $w - 14, 214, '#000000', 0.6);

        $pdf->font('R', 7.5);
        $pdf->text(14, 222, 'POIDS', '#000000');
        $pdf->text(110, 222, 'PIÈCES', '#000000');
        $pdf->text(170, 222, 'REÇU LE', '#000000');
        $pdf->font('B', 14);
        $pdf->text(14, 234, kg((int) $p['weight_g']), '#000000');
        $pdf->text(110, 234, (string) $p['pieces'], '#000000');
        $pdf->font('B', 11);
        $pdf->text(170, 236, dt($p['received_at'], 'd/m/Y'), '#000000');
        if ($p['shipment_reference']) {
            $pdf->font('R', 8);
            $pdf->text(14, 258, 'Dossier ' . $p['shipment_reference'] . ($p['flight_number'] ? '   Vol ' . $p['flight_number'] : ''), '#000000');
        }
        $pdf->line(14, 274, $w - 14, 274, '#000000', 0.6);

        $pdf->barcode(20, 288, $w - 40, 70, $p['tracking_code']);
        $pdf->font('B', 18);
        $pdf->textCenter($w / 2, 366, $p['tracking_code'], '#000000');
        $pdf->font('R', 7.5);
        $pdf->textCenter($w / 2, 396, 'Suivi : ' . preg_replace('#^https?://#', '', absolute_url('suivi')), '#000000');
        return $pdf->output();
    }
}

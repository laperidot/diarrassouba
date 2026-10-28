<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Domain;
use App\Core\Money;
use App\Core\Pdf;
use App\Core\Settings;

/** Mise en page des documents PDF : factures/avoirs, reçus, étiquettes colis. */
final class PdfService
{
    // Palette « Air Waybill » (voir docs/DESIGN.md)
    private const INK = '#121419';
    private const MUTED = '#6A6356';
    private const RULE = '#E2D9C8';
    private const ACCENT = '#B53A0A';   // numéros de document (orange aviation, version texte)
    private const BAND = '#FF5A1F';     // bande de tête
    private const TINT = '#F5F0E6';     // fonds de zones (kraft)
    private const POSITIVE = '#0C7550'; // solde réglé
    private const NEGATIVE = '#B12A1F'; // solde dû

    private function header(Pdf $pdf, string $docTitle, string $number, array $meta): float
    {
        $w = $pdf->pageWidth();
        $pdf->rect(0, 0, $w, 6, self::BAND);
        $pdf->font('B', 15);
        $pdf->text(40, 34, (string) Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT'), self::INK);
        $pdf->font('R', 8.5);
        $y = 54;
        foreach (array_filter([
            Config::get('COMPANY_TAGLINE', 'Transport aérien de colis — Égypte, Côte d’Ivoire, Afrique de l’Ouest'),
            Config::get('COMPANY_ADDRESS'),
            trim(Settings::get('COMPANY_PHONE') . '   ' . Config::get('COMPANY_EMAIL', '')),
            Config::get('COMPANY_LEGAL'),
        ]) as $line) {
            $pdf->text(40, $y, $line, self::MUTED);
            $y += 11.5;
        }
        $pdf->font('B', 18);
        $pdf->textRight($w - 40, 30, $docTitle, self::INK);
        $pdf->font('B', 11);
        $pdf->textRight($w - 40, 54, $number, self::ACCENT);
        $pdf->font('R', 8.5);
        $my = 70;
        foreach ($meta as $k => $v) {
            $pdf->textRight($w - 40, $my, "$k : $v", self::MUTED);
            $my += 11.5;
        }
        $y = max($y, $my) + 14;
        $pdf->line(40, $y, $w - 40, $y, self::RULE, 0.8);
        return $y + 16;
    }

    private function footer(Pdf $pdf, string $text): void
    {
        $pdf->font('R', 7.5);
        $pdf->line(40, $pdf->pageHeight() - 46, $pdf->pageWidth() - 40, $pdf->pageHeight() - 46, self::RULE);
        $pdf->text(40, $pdf->pageHeight() - 38, $text, self::MUTED);
    }

    public function invoice(array $inv, array $items, array $payments, array $installments): string
    {
        $isCredit = $inv['kind'] === 'credit_note';
        $cur = $inv['currency'];
        $pdf = new Pdf();
        $pdf->setTitle(($isCredit ? 'Avoir ' : 'Facture ') . ($inv['number'] ?? 'brouillon'));
        $pdf->addPage();
        $meta = [
            'Date' => d($inv['issue_date'] ?? null),
        ];
        if (!$isCredit) {
            $meta['Échéance'] = d($inv['due_date']);
        } else {
            $meta['Sur facture'] = $inv['credit_of_number'] ?? '—';
        }
        if ($inv['shipment_reference']) {
            $meta['Dossier'] = $inv['shipment_reference'];
        }
        $title = $isCredit ? 'AVOIR' : ($inv['status'] === 'draft' ? 'BROUILLON' : 'FACTURE');
        $y = $this->header($pdf, $title, $inv['number'] ?? 'Non numérotée', $meta);

        // Client (instantané figé à la validation)
        $client = $inv['client_snapshot'] ? json_decode($inv['client_snapshot'], true) : [
            'name' => $inv['client_name'], 'code' => $inv['client_code'], 'phone' => $inv['client_phone'],
            'address' => $inv['client_address'], 'city' => $inv['client_city'], 'email' => $inv['client_email'],
        ];
        $pdf->font('R', 8);
        $pdf->text(40, $y, 'Facturé à', self::MUTED);
        $pdf->font('B', 11);
        $pdf->text(40, $y + 12, (string) $client['name'], self::INK);
        $pdf->font('R', 9);
        $cy = $y + 28;
        foreach (array_filter([
            'Client ' . ($client['code'] ?? ''), $client['phone'] ?? null, $client['email'] ?? null,
            trim(($client['address'] ?? '') . ' ' . ($client['city'] ?? '')),
        ]) as $l) {
            $pdf->text(40, $cy, $l, self::INK);
            $cy += 12;
        }
        if ($inv['payment_terms']) {
            $pdf->font('R', 8);
            $pdf->text(330, $y, 'Modalité de paiement', self::MUTED);
            $pdf->font('B', 9.5);
            $pdf->text(330, $y + 12, Domain::PAYMENT_TERMS[$inv['payment_terms']] ?? '', self::INK);
        }
        $y = max($cy, $y + 50) + 14;

        // Tableau des lignes
        $cols = [40 => 'Colis', 128 => 'Désignation', 330 => 'Poids fact.', 395 => 'Tarif / kg', 470 => 'Montant'];
        $right = 555;
        $pdf->rect(40, $y, $right - 40, 20, self::TINT);
        $pdf->font('B', 8);
        foreach ($cols as $x => $label) {
            in_array($x, [330, 395, 470], true) ? $pdf->textRight($x === 470 ? $right - 6 : $x + 58, $y + 6, $label, self::MUTED) : $pdf->text($x + 6, $y + 6, $label, self::MUTED);
        }
        $y += 26;
        foreach ($items as $it) {
            if ($y > 700) {
                $this->footer($pdf, 'Suite page suivante');
                $pdf->addPage();
                $y = 50;
            }
            $pdf->font('B', 8.5);
            $pdf->text(46, $y, (string) ($it['tracking_code'] ?? '—'), self::INK);
            $pdf->font('R', 8.5);
            $descLines = $pdf->wrap((string) $it['description'], 195);
            $pdf->text(134, $y, $descLines[0], self::INK);
            $pdf->textRight(388, $y, kg((int) $it['billable_weight_g']), self::INK);
            $pdf->textRight(453, $y, Money::format((int) $it['rate_per_kg_minor'], $cur, false), self::INK);
            $pdf->textRight($right - 6, $y, Money::format((int) $it['transport_minor'], $cur, false), self::INK);
            $y += 13;
            foreach (array_slice($descLines, 1, 2) as $l) {
                $pdf->text(134, $y, $l, self::MUTED);
                $y += 11;
            }
            if ((int) $it['fees_minor'] !== 0) {
                $pdf->font('R', 8);
                $pdf->text(134, $y, '+ ' . ($it['fees_label'] ?: 'Frais supplémentaires'), self::MUTED);
                $pdf->textRight($right - 6, $y, Money::format((int) $it['fees_minor'], $cur, false), self::MUTED);
                $y += 12;
            }
            $pdf->line(40, $y + 2, $right, $y + 2, self::RULE, 0.4);
            $y += 9;
        }

        // Totaux
        $y += 8;
        $tx = 360;
        $rows = [['Transport', (int) $inv['subtotal_minor']]];
        if ((int) $inv['fees_minor'] !== 0) {
            $rows[] = ['Frais supplémentaires', (int) $inv['fees_minor']];
        }
        if ((int) $inv['tax_rate_bp'] > 0) {
            $rows[] = ['Taxe ' . rtrim(rtrim(number_format($inv['tax_rate_bp'] / 100, 2, ',', ''), '0'), ',') . ' %', (int) $inv['tax_minor']];
        }
        $pdf->font('R', 9);
        foreach ($rows as [$label, $v]) {
            $pdf->text($tx, $y, $label, self::MUTED);
            $pdf->textRight($right - 6, $y, Money::format($v, $cur), self::INK);
            $y += 15;
        }
        $pdf->rect($tx - 8, $y - 2, $right - $tx + 8, 26, self::INK);
        $pdf->font('B', 11);
        $pdf->text($tx, $y + 6, $isCredit ? 'Total de l’avoir' : 'Total à payer', '#FFFFFF');
        $pdf->textRight($right - 6, $y + 6, Money::format((int) $inv['total_minor'], $cur), '#FFFFFF');
        $y += 36;
        if (!$isCredit && $inv['status'] !== 'draft') {
            $pdf->font('R', 9);
            $pdf->text($tx, $y, 'Déjà réglé', self::MUTED);
            $pdf->textRight($right - 6, $y, Money::format((int) $inv['paid_minor'], $cur), self::INK);
            $y += 15;
            $pdf->font('B', 10);
            $balance = in_array($inv['status'], ['credited', 'cancelled'], true) ? 0 : (int) $inv['total_minor'] - (int) $inv['paid_minor'];
            $pdf->text($tx, $y, 'Solde restant', self::INK);
            $pdf->textRight($right - 6, $y, Money::format($balance, $cur), $balance > 0 ? self::NEGATIVE : self::POSITIVE);
            $y += 18;
        }

        // Échéancier et règlements (colonne gauche)
        $ly = $y - (count($rows) * 15 + 70);
        $ly = max($ly, $y - 120);
        $leftY = $ly;
        if ($installments) {
            $pdf->font('B', 8.5);
            $pdf->text(40, $leftY, 'Échéancier', self::INK);
            $leftY += 13;
            $pdf->font('R', 8.5);
            foreach ($installments as $ins) {
                $pdf->text(40, $leftY, d($ins['due_date']) . ($ins['label'] ? '  ' . $ins['label'] : ''), self::INK);
                $pdf->textRight(300, $leftY, Money::format((int) $ins['amount_minor'], $cur), self::INK);
                $leftY += 12;
            }
            $leftY += 8;
        }
        if ($payments) {
            $pdf->font('B', 8.5);
            $pdf->text(40, $leftY, 'Règlements reçus', self::INK);
            $leftY += 13;
            $pdf->font('R', 8.5);
            foreach ($payments as $p) {
                $pdf->text(40, $leftY, dt($p['paid_at'], 'd/m/Y') . '  ' . $p['receipt_number'] . '  ' . (Domain::PAYMENT_METHODS[$p['method']] ?? $p['method']), self::INK);
                $pdf->textRight(300, $leftY, Money::format((int) $p['amount_minor'], $cur), self::INK);
                $leftY += 12;
            }
        }
        $y = max($y, $leftY) + 20;
        if ($inv['status'] === 'cancelled' || $inv['status'] === 'credited') {
            $pdf->font('B', 10);
            $pdf->text(40, $y, ($inv['status'] === 'credited' ? 'Facture annulée par avoir' : 'Facture annulée') . ' le ' . dt($inv['cancelled_at'], 'd/m/Y') . ' : ' . ($inv['cancel_reason'] ?? ''), self::NEGATIVE);
            $y += 18;
        }
        if ($inv['notes']) {
            $pdf->font('R', 8.5);
            $y = $pdf->paragraph(40, $y, 515, (string) $inv['notes'], 1.35, self::MUTED) + 6;
        }
        if ($pay = Config::get('COMPANY_PAYMENT_INFO')) {
            $pdf->font('R', 8.5);
            $pdf->paragraph(40, $y, 515, 'Règlement : ' . $pay, 1.35, self::MUTED);
        }
        $this->footer($pdf, 'Montants exprimés en ' . (Domain::CURRENCIES[$cur]['label'] ?? $cur) . '. Facturation au kilogramme : poids facturable arrondi aux ' . Config::int('BILLING_WEIGHT_STEP_G', 100) . ' g supérieurs.');
        return $pdf->output();
    }

    public function receipt(array $pay, array $inv): string
    {
        $pdf = new Pdf(419.53, 595.28); // A5
        $reversal = (int) $pay['amount_minor'] < 0;
        $pdf->setTitle('Reçu ' . $pay['receipt_number']);
        $pdf->addPage();
        $w = $pdf->pageWidth();
        $pdf->rect(0, 0, $w, 6, self::BAND);
        $pdf->font('B', 12);
        $pdf->text(30, 26, (string) Config::get('APP_NAME', 'DIARRASSOUBA IMPORT-EXPORT'), self::INK);
        $pdf->font('R', 8);
        $pdf->text(30, 42, trim(Settings::get('COMPANY_PHONE') . '   ' . Config::get('COMPANY_ADDRESS', '')), self::MUTED);
        $pdf->font('B', 16);
        $pdf->text(30, 78, $reversal ? 'Annulation de paiement' : 'Reçu de paiement', self::INK);
        $pdf->font('B', 11);
        $pdf->text(30, 100, $pay['receipt_number'], self::ACCENT);

        $pdf->rect(30, 128, $w - 60, 64, self::TINT);
        $pdf->font('R', 8.5);
        $pdf->text(44, 140, $reversal ? 'Montant annulé' : 'Montant reçu', self::MUTED);
        $pdf->font('B', 22);
        $pdf->text(44, 156, Money::format(abs((int) $pay['amount_minor']), $pay['currency']), self::INK);

        $rows = [
            'Date' => dt($pay['paid_at']),
            'Client' => $pay['client_name'] . ' (' . $pay['client_code'] . ')',
            'Facture' => (string) $pay['invoice_number'],
            'Moyen de paiement' => Domain::PAYMENT_METHODS[$pay['method']] ?? $pay['method'],
            'Référence' => $pay['reference'] ?: '—',
            'Saisie' => ($pay['confirmation'] === 'provider' ? 'Confirmée par le prestataire' : 'Enregistrée manuellement') . ' par ' . ($pay['recorded_by_name'] ?? '—'),
        ];
        if ($reversal) {
            $rows['Annule le reçu'] = (string) $pay['reversal_of_receipt'];
        }
        $y = 212;
        foreach ($rows as $k => $v) {
            $pdf->font('R', 8.5);
            $pdf->text(30, $y, $k, self::MUTED);
            $pdf->font('B', 9);
            foreach ($pdf->wrap($v, $w - 170) as $i => $line) {
                $pdf->text(140, $y + $i * 12, $line, self::INK);
            }
            $y += 22;
            $pdf->line(30, $y - 7, $w - 30, $y - 7, self::RULE, 0.4);
        }
        $balance = in_array($inv['status'], ['credited', 'cancelled'], true) ? 0 : (int) $inv['total_minor'] - (int) $inv['paid_minor'];
        $y += 6;
        $pdf->font('R', 9);
        $pdf->text(30, $y, 'Total facture', self::MUTED);
        $pdf->textRight($w - 30, $y, Money::format((int) $inv['total_minor'], $inv['currency']), self::INK);
        $y += 15;
        $pdf->text(30, $y, 'Total réglé à ce jour', self::MUTED);
        $pdf->textRight($w - 30, $y, Money::format((int) $inv['paid_minor'], $inv['currency']), self::INK);
        $y += 15;
        $pdf->font('B', 10);
        $pdf->text(30, $y, 'Solde restant', self::INK);
        $pdf->textRight($w - 30, $y, Money::format($balance, $inv['currency']), $balance > 0 ? self::NEGATIVE : self::POSITIVE);
        if ($pay['notes']) {
            $pdf->font('R', 8);
            $pdf->paragraph(30, $y + 26, $w - 60, (string) $pay['notes'], 1.35, self::MUTED);
        }
        $pdf->font('R', 7.5);
        $pdf->text(30, $pdf->pageHeight() - 30, 'Document émis le ' . dt(gmdate('Y-m-d H:i:s')) . '. Conservez ce reçu.', self::MUTED);
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

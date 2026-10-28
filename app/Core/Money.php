<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Montants en unités mineures entières (centimes ; le franc CFA n'a pas de décimales).
 * Poids en grammes entiers. Aucun calcul monétaire en virgule flottante.
 */
final class Money
{
    public static function exp(string $currency): int
    {
        return Domain::CURRENCIES[$currency]['exp'] ?? 2;
    }

    /** Analyse une saisie utilisateur (« 12 500 », « 12500,50 », « 1.234,5 ») en unités mineures. */
    public static function parse(?string $input, string $currency): ?int
    {
        $s = trim((string) $input);
        if ($s === '') {
            return null;
        }
        $s = str_replace(["\u{202F}", "\u{00A0}", ' '], '', $s);
        if (!preg_match('/^-?\d+([.,]\d+)?$/', $s) && !preg_match('/^-?\d{1,3}([.,]\d{3})+([.,]\d+)?$/', $s)) {
            return null;
        }
        // Le dernier séparateur est décimal s'il est suivi de 1 ou 2 chiffres (ou plus si exp > 2).
        if (preg_match('/^(-?[\d.,]*?)[.,](\d{1,2})$/', $s, $m) && self::exp($currency) > 0) {
            $int = preg_replace('/[.,]/', '', $m[1]);
            $dec = str_pad($m[2], self::exp($currency), '0');
        } else {
            $int = preg_replace('/[.,]/', '', $s);
            $dec = '';
        }
        $neg = str_starts_with($int, '-');
        $int = ltrim($int, '-');
        if ($int === '') {
            $int = '0';
        }
        $exp = self::exp($currency);
        $value = (int) $int * (10 ** $exp) + ($dec !== '' ? (int) substr($dec, 0, $exp) : 0);
        return $neg ? -$value : $value;
    }

    public static function format(int $minor, string $currency, bool $withSymbol = true): string
    {
        $exp = self::exp($currency);
        $value = $exp > 0 ? $minor / (10 ** $exp) : $minor;
        $txt = number_format($value, $exp, ',', "\u{202F}");
        if (!$withSymbol) {
            return $txt;
        }
        $sym = Domain::CURRENCIES[$currency]['symbol'] ?? $currency;
        return $sym === '$' || $sym === '€' ? $txt . "\u{00A0}" . $sym : $txt . "\u{00A0}" . $sym;
    }

    /** Valeur pour un champ de formulaire (sans séparateur de milliers). */
    public static function input(?int $minor, string $currency): string
    {
        if ($minor === null) {
            return '';
        }
        $exp = self::exp($currency);
        return $exp > 0 ? number_format($minor / (10 ** $exp), $exp, ',', '') : (string) $minor;
    }

    /** Saisie de poids en kg (« 12,5 ») -> grammes. */
    public static function parseKg(?string $input): ?int
    {
        $s = str_replace([' ', "\u{202F}", "\u{00A0}"], '', trim((string) $input));
        if ($s === '' || !preg_match('/^\d+([.,]\d{1,3})?$/', $s)) {
            return null;
        }
        $s = str_replace(',', '.', $s);
        [$i, $f] = array_pad(explode('.', $s), 2, '');
        return (int) $i * 1000 + (int) str_pad($f, 3, '0');
    }

    public static function kgInput(?int $grams): string
    {
        if ($grams === null) {
            return '';
        }
        return rtrim(rtrim(number_format($grams / 1000, 3, ',', ''), '0'), ',');
    }

    /**
     * Poids facturable : arrondi au palier supérieur (BILLING_WEIGHT_STEP_G, 100 g par défaut)
     * puis application du minimum facturable du tarif.
     */
    public static function billableWeight(int $grams, int $minG = 0, ?int $step = null): int
    {
        $step ??= Config::int('BILLING_WEIGHT_STEP_G', 100);
        $step = max(1, $step);
        $rounded = (int) (ceil($grams / $step) * $step);
        return max($rounded, $minG);
    }

    /**
     * Montant transport = poids facturable (kg) × tarif/kg, arrondi au plus proche
     * (demi vers le haut) dans l'unité mineure de la devise. Arithmétique entière.
     */
    public static function transportAmount(int $billableG, int $ratePerKgMinor): int
    {
        $num = $billableG * $ratePerKgMinor;
        return intdiv($num + 500, 1000);
    }

    /** Taxe en points de base (1800 = 18 %), arrondi demi vers le haut. */
    public static function tax(int $baseMinor, int $rateBp): int
    {
        return intdiv($baseMinor * $rateBp + 5000, 10000);
    }
}

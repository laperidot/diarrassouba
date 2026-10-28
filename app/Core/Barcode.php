<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Code 128 (jeu B) : lisible par les douchettes et par la caméra des téléphones
 * (API BarcodeDetector). Produit une suite de modules [barre?, largeur].
 */
final class Barcode
{
    private const P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];

    /** @return list<array{0:bool,1:int}> barres (true) et espaces (false) avec leur largeur en modules */
    public static function code128(string $text): array
    {
        $codes = [104]; // Start B
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($text[$i]);
            if ($o < 32 || $o > 126) {
                throw new \InvalidArgumentException('Caractère non encodable en Code 128 B');
            }
            $codes[] = $o - 32;
        }
        $sum = 104;
        foreach (array_slice($codes, 1) as $pos => $c) {
            $sum += $c * ($pos + 1);
        }
        $codes[] = $sum % 103;
        $codes[] = 106; // Stop
        $modules = [];
        foreach ($codes as $c) {
            foreach (str_split(self::P[$c]) as $k => $w) {
                $modules[] = [$k % 2 === 0, (int) $w];
            }
        }
        return $modules;
    }

    public static function totalModules(string $text): int
    {
        return array_sum(array_column(self::code128($text), 1)) + 20; // + zones blanches de 10 modules
    }

    /** SVG autonome (pour l'étiquette HTML imprimable). */
    public static function svg(string $text, int $height = 60, float $module = 2.0): string
    {
        $x = 10 * $module;
        $rects = '';
        foreach (self::code128($text) as [$bar, $w]) {
            if ($bar) {
                $rects .= sprintf('<rect x="%.2f" y="0" width="%.2f" height="%d"/>', $x, $w * $module, $height);
            }
            $x += $w * $module;
        }
        $width = $x + 10 * $module;
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %.2f %d" width="%.2f" height="%d" role="img" aria-label="Code-barres %s" shape-rendering="crispEdges"><rect width="100%%" height="100%%" fill="#fff"/><g fill="#000">%s</g></svg>',
            $width, $height, $width, $height, htmlspecialchars($text), $rects
        );
    }
}

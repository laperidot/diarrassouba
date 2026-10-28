<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Générateur PDF minimal sans dépendance (PDF 1.4) : texte Helvetica / Helvetica-Bold
 * en encodage WinAnsi (accents français, €), rectangles, traits, code-barres.
 * Coordonnées en points, origine en haut à gauche (convertie en interne).
 */
final class Pdf
{
    private const W_REG = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const W_BOLD = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    private array $pages = [];
    private int $current = -1;
    private string $font = 'R';
    private float $size = 10;
    private string $title = '';

    public function __construct(private float $width = 595.28, private float $height = 841.89)
    {
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageHeight(): float
    {
        return $this->height;
    }

    public function pageWidth(): float
    {
        return $this->width;
    }

    private function out(string $s): void
    {
        $this->pages[$this->current] .= $s . "\n";
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3f %.3f %.3f', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    public function font(string $style, float $size): void
    {
        $this->font = $style === 'B' ? 'B' : 'R';
        $this->size = $size;
    }

    /** UTF-8 -> Windows-1252 (caractères hors jeu translittérés). */
    private static function enc(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{2009}", "\u{2019}", "\u{2018}", "\u{2014}", "\u{2013}", "\u{2026}", "\u{2022}"], [' ', ' ', "'", "'", '-', '-', '...', '-'], $s);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }

    public function textWidth(string $s, ?string $style = null, ?float $size = null): float
    {
        $table = ($style ?? $this->font) === 'B' ? self::W_BOLD : self::W_REG;
        $enc = self::enc($s);
        $w = 0;
        $len = strlen($enc);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($enc[$i]);
            $w += match (true) {
                $o >= 32 && $o <= 126 => $table[$o - 32],
                $o === 0xA0 => 278,
                $o >= 0xEC && $o <= 0xEF, $o >= 0xCC && $o <= 0xCF => 278, // ì-ï, Ì-Ï
                $o === 0xE7 => $table[ord('c') - 32],
                $o === 0xC7 => 722,
                $o >= 0xE0 => $table[ord('e') - 32], // minuscules accentuées
                $o >= 0xC0 => 667,                  // majuscules accentuées
                default => 556,                      // €, signes divers
            };
        }
        return $w * ($size ?? $this->size) / 1000;
    }

    public function text(float $x, float $y, string $s, string $color = '#111827'): void
    {
        $enc = self::enc($s);
        $enc = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $enc);
        $this->out(sprintf(
            'BT /F%s %.2f Tf %s rg %.2f %.2f Td (%s) Tj ET',
            $this->font === 'B' ? '2' : '1', $this->size, self::rgb($color), $x, $this->height - $y - $this->size * 0.8, $enc
        ));
    }

    public function textRight(float $xRight, float $y, string $s, string $color = '#111827'): void
    {
        $this->text($xRight - $this->textWidth($s), $y, $s, $color);
    }

    public function textCenter(float $xCenter, float $y, string $s, string $color = '#111827'): void
    {
        $this->text($xCenter - $this->textWidth($s) / 2, $y, $s, $color);
    }

    /** Découpe un texte en lignes tenant dans $width. */
    public function wrap(string $s, float $width): array
    {
        $lines = [];
        foreach (preg_split("/\r?\n/", $s) as $para) {
            $line = '';
            foreach (preg_split('/\s+/', trim($para)) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->textWidth($try) <= $width || $line === '') {
                    $line = $try;
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Écrit un paragraphe ; retourne la nouvelle position verticale. */
    public function paragraph(float $x, float $y, float $width, string $s, float $leading = 1.35, string $color = '#111827'): float
    {
        foreach ($this->wrap($s, $width) as $line) {
            $this->text($x, $y, $line, $color);
            $y += $this->size * $leading;
        }
        return $y;
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $lineWidth = 0.6): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->out(sprintf(
            '%s%s%.2f w %.2f %.2f %.2f %.2f re %s',
            $fill ? self::rgb($fill) . ' rg ' : '', $stroke ? self::rgb($stroke) . ' RG ' : '', $lineWidth,
            $x, $this->height - $y - $h, $w, $h, $op
        ));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#E3E6EB', float $width = 0.6, ?array $dash = null): void
    {
        $this->out(sprintf(
            '%s RG %.2f w %s %.2f %.2f m %.2f %.2f l S [] 0 d',
            self::rgb($color), $width, $dash ? '[' . implode(' ', $dash) . '] 0 d' : '',
            $x1, $this->height - $y1, $x2, $this->height - $y2
        ));
    }

    public function barcode(float $x, float $y, float $width, float $height, string $text): void
    {
        $modules = Barcode::code128($text);
        $total = array_sum(array_column($modules, 1));
        $m = $width / $total;
        $cx = $x;
        $buf = '0 0 0 rg';
        foreach ($modules as [$bar, $w]) {
            if ($bar) {
                $buf .= sprintf(' %.3f %.3f %.3f %.3f re', $cx, $this->height - $y - $height, $w * $m, $height);
            }
            $cx += $w * $m;
        }
        $this->out($buf . ' f');
    }

    public function output(): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $stream = function_exists('gzcompress') ? gzcompress($content, 6) : $content;
            $filter = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
            $objs[$n] = sprintf('<< /Length %d%s >>' . "\nstream\n%s\nendstream", strlen($stream), $filter, $stream);
            $objs[$n + 1] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->width, $this->height, $n
            );
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        $title = self::enc($this->title);
        $objs[$n] = sprintf('<< /Title (%s) /Producer (Diarrassouba Logistique) /CreationDate (D:%s) >>', str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title), gmdate('YmdHis'));
        $infoId = $n;
        ksort($objs);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }
}

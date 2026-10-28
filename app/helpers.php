<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Money;

/**
 * Dossier des données écrites par l'application (journaux, sessions, pièces jointes).
 * STORAGE_PATH le déplace hors du projet (ex. /tmp/diarrassouba sur Vercel, où le code est en lecture seule).
 */
function storage_path(string $sub = ''): string
{
    $root = getenv('STORAGE_PATH') ?: Config::get('STORAGE_PATH') ?: APP_ROOT . '/storage';
    return rtrim(str_replace('\\', '/', $root), '/') . ($sub === '' ? '' : '/' . ltrim($sub, '/'));
}

/** Échappement HTML systématique dans les vues. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $configured = Config::get('APP_BASE_PATH');
        if ($configured !== null && $configured !== '') {
            $base = '/' . trim($configured, '/');
            $base = $base === '/' ? '' : $base;
        } else {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $dir = str_replace('\\', '/', dirname($script));
            $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
            // Projet servi depuis sa racine (XAMPP : .htaccess racine -> public/) : « /public » n'apparaît pas dans l'URL.
            $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if (str_ends_with($base, '/public') && $uri !== $base && !str_starts_with($uri, $base . '/')) {
                $base = substr($base, 0, -strlen('/public'));
            }
        }
    }
    return $base;
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');
        if ($query) {
            $u .= '?' . http_build_query($query);
        }
    }
    return $u;
}

function absolute_url(string $path = '/'): string
{
    $root = rtrim((string) Config::get('APP_URL', ''), '/');
    if ($root === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $root = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_path();
    }
    return $root . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $publicDir = PHP_SAPI !== 'cli' && !empty($_SERVER['SCRIPT_FILENAME']) ? dirname($_SERVER['SCRIPT_FILENAME']) : APP_ROOT . '/public';
    $file = $publicDir . '/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function money(int $minor, string $currency): string
{
    return Money::format($minor, $currency);
}

function kg(?int $grams): string
{
    if ($grams === null) {
        return '—';
    }
    return number_format($grams / 1000, $grams % 1000 === 0 ? 0 : ($grams % 100 === 0 ? 1 : 2), ',', "\u{202F}") . "\u{202F}kg";
}

function local_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone(Config::get('APP_TIMEZONE', 'Africa/Abidjan'));
}

/** Convertit une date UTC stockée en affichage local. */
function dt(?string $utc, string $format = 'd/m/Y H:i'): string
{
    if (!$utc) {
        return '—';
    }
    try {
        $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(local_tz())->format($format);
    } catch (Throwable) {
        return '—';
    }
}

function d(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '—';
}

/** Date relative courte : « il y a 3 h ». */
function ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $diff = time() - strtotime($utc . ' UTC');
    return match (true) {
        $diff < 60 => "à l'instant",
        $diff < 3600 => 'il y a ' . intdiv($diff, 60) . ' min',
        $diff < 86400 => 'il y a ' . intdiv($diff, 3600) . ' h',
        $diff < 86400 * 7 => 'il y a ' . intdiv($diff, 86400) . ' j',
        default => dt($utc, 'd/m/Y'),
    };
}

/** Saisie locale (datetime-local) -> UTC stocké. */
function local_to_utc(?string $local): ?string
{
    if (!$local) {
        return null;
    }
    try {
        $d = new DateTimeImmutable($local, local_tz());
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function utc_to_local_input(?string $utc): string
{
    return $utc ? dt($utc, 'Y-m-d\TH:i') : '';
}

function normalize_phone(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    // On ne conserve que les 10 derniers chiffres pour comparer +225 07.. et 07..
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

/** Masque un téléphone pour l'affichage public : 07 •• •• 45 12 */
function mask_phone(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    if (strlen($d) < 4) {
        return '';
    }
    return str_repeat('•', max(0, strlen($d) - 4)) . substr($d, -4);
}

/** « Konan Aya Marie » -> « Konan A. » */
function mask_name(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    if (!$parts || $parts[0] === '') {
        return '';
    }
    $first = array_shift($parts);
    return $first . ($parts ? ' ' . mb_strtoupper(mb_substr($parts[0], 0, 1)) . '.' : '');
}

function country_name(?string $code): string
{
    return App\Core\Domain::COUNTRIES[$code ?? ''] ?? (string) $code;
}

function old(string $key, mixed $default = ''): string
{
    $old = $_SESSION['_old'] ?? [];
    return e($old[$key] ?? $default);
}

function selected(mixed $a, mixed $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function checked(bool $cond): string
{
    return $cond ? ' checked' : '';
}

function pluralize(int $n, string $one, string $many): string
{
    return $n . "\u{00A0}" . ($n > 1 ? $many : $one);
}

/** Icônes au trait (24×24), dessinées pour l'application. */
function icon(string $name, string $class = ''): string
{
    $p = [
        'home' => '<path d="M3 11 12 4l9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'box' => '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>',
        'scan' => '<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 9v6M10 9v6M13 9v6M17 9v6"/>',
        'plane' => '<path d="M21 15.5 13.5 11V5.5a1.5 1.5 0 0 0-3 0V11L3 15.5V18l7.5-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5l-2-1.5v-3.5L21 18z"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'bell' => '<path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 13.5a6.5 6.5 0 0 1 3.5 6.5"/>',
        'truck' => '<path d="M2 6h11v10H2zM13 9h4l4 4v3h-8"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'invoice' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/>',
        'cash' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9.5v5M18 9.5v5"/>',
        'receipt' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2"/><circle cx="16" cy="14.5" r="1.2"/>',
        'tag' => '<path d="M3 3h8l10 10-8 8L3 11z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
        'logout' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M15 8l2 2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'print' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M7 14h10v7H7z"/>',
        'share' => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 20h16"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
        'check' => '<path d="m5 12 5 5 9-10"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'alert' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17.5v.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'route' => '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18H15a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6.5"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'back' => '<path d="m15 6-6 6 6 6"/>',
        'camera' => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h4"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
        'moon' => '<path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.2 6.5l2.6 1.5M17.2 16l2.6 1.5M4.2 17.5l2.6-1.5M17.2 8l2.6-1.5"/><circle cx="12" cy="12" r="6.5"/>',
        'monitor' =>'<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
    ][$name] ?? '';
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . ($class ? ' class="' . e($class) . '"' : '') . ">$p</svg>";
}

/** Bouton de bascule jour / nuit (géré par assets/js/theme.js). */
function theme_toggle(): string
{
    return '<button type="button" class="icon-btn theme-toggle" data-theme-toggle title="Mode nuit" aria-label="Mode nuit">'
        . icon('moon', 'i-moon') . icon('sun', 'i-sun') . '</button>';
}

/** Choix explicite du thème : automatique (système), jour ou nuit. */
function theme_picker(): string
{
    $opt = fn (string $v, string $icon, string $label) => '<button type="button" data-theme-set="' . $v . '" aria-pressed="false">' . icon($icon) . '<span>' . $label . '</span></button>';
    return '<div class="segmented theme-seg" role="group" aria-label="Apparence">'
        . $opt('auto', 'monitor', 'Auto') . $opt('light', 'sun', 'Jour') . $opt('dark', 'moon', 'Nuit') . '</div>';
}

function status_badge(string $status, string $kind = 'parcel'): string
{
    [$label, $tone] = match ($kind) {
        'shipment' => [App\Core\Domain::SHIPMENT_STATUSES[$status] ?? $status, App\Core\Domain::SHIPMENT_TONE[$status] ?? 'muted'],
        'invoice' => [App\Core\Domain::INVOICE_STATUSES[$status] ?? $status, App\Core\Domain::INVOICE_TONE[$status] ?? 'muted'],
        'delivery' => [App\Core\Domain::DELIVERY_STATUSES[$status] ?? $status, App\Core\Domain::DELIVERY_TONE[$status] ?? 'muted'],
        default => [App\Core\Domain::parcelLabel($status), App\Core\Domain::PARCEL_TONE[$status] ?? 'muted'],
    };
    return '<span class="status tone-' . $tone . '">' . e($label) . '</span>';
}

/** Champ : message d'erreur et attribut aria-invalid. */
function field_error(string $name): string
{
    $err = App\Core\Session::errors()[$name] ?? null;
    return $err ? '<span class="error" id="err-' . e($name) . '">' . e($err) . '</span>' : '';
}

function invalid(string $name): string
{
    return isset(App\Core\Session::errors()[$name]) ? ' aria-invalid="true" aria-describedby="err-' . e($name) . '"' : '';
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Core\Session::csrfToken()) . '">';
}

function can(string $permission): bool
{
    return App\Core\Auth::can($permission);
}

/** Rail de route d'un colis. */
function parcel_rail(string $status, bool $compact = true): string
{
    $flow = array_keys(App\Core\Domain::PARCEL_FLOW);
    $isException = isset(App\Core\Domain::PARCEL_EXCEPTIONS[$status]);
    $idx = array_search($status, $flow, true);
    $html = '<div class="rail' . ($compact ? ' compact' : '') . '" role="list" aria-label="Progression du colis">';
    foreach ($flow as $i => $s) {
        $cls = ['rail-stop'];
        if (in_array($s, ['in_transit', 'arrived'], true)) {
            $cls[] = 'leg-air';
        }
        if ($idx !== false && $i < $idx) {
            $cls[] = 'passed';
        }
        if ($idx !== false && $i === $idx) {
            $cls[] = 'current';
        }
        $html .= '<div class="' . implode(' ', $cls) . '" role="listitem"' . ($idx === $i ? ' aria-current="step"' : '') . '><span class="rail-dot"></span><span class="rail-label">' . e(App\Core\Domain::PARCEL_SHORT[$s]) . '</span></div>';
    }
    $html .= '</div>';
    if ($isException) {
        $html .= '<p class="flash flash-error" style="margin:14px 0 0">' . icon('alert') . '<span><strong>' . e(App\Core\Domain::parcelLabel($status)) . '</strong> : le colis est momentanément hors du parcours normal.</span></p>';
    }
    return $html;
}

function paginate(int $total, int $page, int $per, array $query): string
{
    $pages = max(1, (int) ceil($total / $per));
    if ($pages <= 1) {
        return '';
    }
    $path = App\Core\Request::path();
    $prev = $page > 1 ? '<a class="btn btn-sm" href="' . e(url($path, ['page' => $page - 1] + $query)) . '">Précédent</a>' : '<span></span>';
    $next = $page < $pages ? '<a class="btn btn-sm" href="' . e(url($path, ['page' => $page + 1] + $query)) . '">Suivant</a>' : '<span></span>';
    return '<nav class="pagination" aria-label="Pagination">' . $prev . '<span>Page ' . $page . ' sur ' . $pages . ' (' . $total . ')</span>' . $next . '</nav>';
}

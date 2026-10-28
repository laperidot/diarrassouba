<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Téléversement contrôlé : type réel vérifié (finfo), taille limitée, nom aléatoire,
 * stockage hors du dossier public. Les fichiers ne sont servis qu'après contrôle des droits.
 */
final class Upload
{
    public const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /** @return array{path:string,original_name:string,mime:string,size:int}|null  null si aucun fichier */
    public static function store(?array $file, string $folder): ?array
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new BusinessException('Le fichier n’a pas pu être reçu (taille maximale dépassée ou envoi interrompu).');
        }
        $max = Config::int('UPLOAD_MAX_MB', 5) * 1024 * 1024;
        if ($file['size'] > $max) {
            throw new BusinessException('Fichier trop volumineux : ' . Config::int('UPLOAD_MAX_MB', 5) . ' Mo maximum.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new BusinessException('Format refusé. Formats acceptés : JPG, PNG, WebP ou PDF.');
        }
        if (str_starts_with($mime, 'image/') && @getimagesize($file['tmp_name']) === false) {
            throw new BusinessException('Image illisible ou corrompue.');
        }
        $dir = storage_path('uploads/' . $folder) . '/' . gmdate('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier de stockage inaccessible.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::ALLOWED[$mime];
        $dest = $dir . '/' . $name;
        $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dest) : rename($file['tmp_name'], $dest);
        if (!$moved) {
            throw new \RuntimeException('Enregistrement du fichier impossible.');
        }
        $original = preg_replace('/[^\p{L}\p{N}._ -]/u', '_', (string) ($file['name'] ?? 'fichier'));
        return [
            'path' => $folder . '/' . gmdate('Y/m') . '/' . $name,
            'original_name' => mb_substr($original, 0, 150),
            'mime' => $mime,
            'size' => (int) $file['size'],
        ];
    }

    public static function absolute(string $relative): string
    {
        $full = realpath(storage_path('uploads/' . $relative));
        $root = realpath(storage_path('uploads'));
        if ($full === false || $root === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404);
        }
        return $full;
    }

    public static function send(string $relative, string $mime, string $downloadName): never
    {
        $path = self::absolute($relative);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . rawurlencode($downloadName) . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}

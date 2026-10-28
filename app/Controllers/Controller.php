<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BusinessException;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Core\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], ?string $layout = 'app'): string
    {
        return View::render($template, $data + ['user' => Auth::user()], $layout);
    }

    protected function redirect(string $path, array $query = []): never
    {
        header('Location: ' . url($path, $query), true, 303);
        exit;
    }

    protected function back(string $fallback = '/'): never
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($ref && parse_url($ref, PHP_URL_HOST) === $host) {
            header('Location: ' . $ref, true, 303);
            exit;
        }
        $this->redirect($fallback);
    }

    protected function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function sendPdf(string $bytes, string $filename, bool $download = false): never
    {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }

    /**
     * Exécute une action de formulaire : en cas d'erreur de saisie ou métier,
     * revient au formulaire avec les messages et les valeurs saisies.
     */
    protected function attempt(callable $action, string $backTo): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            Session::setOld(Request::all());
            Session::setErrors($e->errors);
            $msg = $e->getMessage();
            foreach (['duplicate', 'similar'] as $special) {
                if (isset($e->errors[$special])) {
                    $msg = $e->errors[$special];
                }
            }
            Session::flash(isset($e->errors['duplicate']) || isset($e->errors['similar']) ? 'warning' : 'error', $msg);
            $this->redirect($backTo);
        } catch (BusinessException $e) {
            Session::setOld(Request::all());
            Session::flash('error', $e->getMessage());
            $this->redirect($backTo);
        }
    }

    protected function page(): int
    {
        return max(1, (int) Request::query('page', '1'));
    }

    protected function notFoundUnless(mixed $value): void
    {
        if (!$value) {
            throw new HttpException(404);
        }
    }
}

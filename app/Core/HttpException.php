<?php
declare(strict_types=1);

namespace App\Core;

/** Exception HTTP transformée en page d'erreur propre par le front controller. */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message);
    }
}

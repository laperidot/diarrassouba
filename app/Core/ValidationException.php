<?php
declare(strict_types=1);

namespace App\Core;

/** Erreurs de saisie par champ, renvoyées au formulaire. */
final class ValidationException extends \RuntimeException
{
    public function __construct(public readonly array $errors, string $message = 'Certains champs sont à corriger.')
    {
        parent::__construct($message);
    }
}

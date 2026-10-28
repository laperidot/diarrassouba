<?php
declare(strict_types=1);

namespace App\Core;

/** Validation côté serveur : accumule les erreurs par champ. */
final class Validator
{
    private array $errors = [];

    public function required(string $field, mixed $value, string $label): self
    {
        if ($value === null || $value === '' || $value === []) {
            $this->errors[$field] ??= "$label est obligatoire.";
        }
        return $this;
    }

    public function max(string $field, ?string $value, int $max, string $label): self
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $this->errors[$field] ??= "$label : $max caractères maximum.";
        }
        return $this;
    }

    public function in(string $field, ?string $value, array $allowed, string $label, bool $optional = false): self
    {
        if (($value === null || $value === '') && $optional) {
            return $this;
        }
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] ??= "$label : valeur non reconnue.";
        }
        return $this;
    }

    public function email(string $field, ?string $value, string $label = 'E-mail'): self
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] ??= "$label : adresse invalide.";
        }
        return $this;
    }

    public function phone(string $field, ?string $value, string $label = 'Téléphone', bool $required = false): self
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (($value === null || $value === '') && !$required) {
            return $this;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15 || !preg_match('/^\+?[\d\s().-]+$/', (string) $value)) {
            $this->errors[$field] ??= "$label : numéro invalide (8 à 15 chiffres).";
        }
        return $this;
    }

    public function date(string $field, ?string $value, string $label, bool $required = false): self
    {
        if ($value === null || $value === '') {
            if ($required) {
                $this->errors[$field] ??= "$label est obligatoire.";
            }
            return $this;
        }
        $ok = (bool) preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?$/', $value) && strtotime($value) !== false;
        if (!$ok) {
            $this->errors[$field] ??= "$label : date invalide.";
        }
        return $this;
    }

    public function positive(string $field, ?int $value, string $label, bool $allowZero = false): self
    {
        if ($value === null) {
            $this->errors[$field] ??= "$label : nombre invalide.";
        } elseif ($value < 0 || (!$allowZero && $value === 0)) {
            $this->errors[$field] ??= "$label doit être supérieur à zéro.";
        }
        return $this;
    }

    public function add(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

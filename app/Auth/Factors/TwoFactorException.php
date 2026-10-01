<?php

namespace App\Auth\Factors;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Erreur 2FA à renvoyer telle quelle au client ({"message": ...} + statut).
 * Rendue automatiquement par Laravel : les contrôleurs n'ont pas à la capturer.
 */
class TwoFactorException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}

<?php

namespace App\Exceptions;

use App\Enums\RequestStatus;
use DomainException;
use Illuminate\Http\JsonResponse;

class InvalidTransitionException extends DomainException
{
    public static function between(RequestStatus $from, RequestStatus $to): self
    {
        $message = $from->isFinal()
            ? "This request is {$from->value} and can no longer be changed."
            : "A request cannot move from '{$from->value}' to '{$to->value}'.";

        return new self($message);
    }

    /**
     * Laravel calls this automatically when the exception reaches the handler.
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
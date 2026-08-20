<?php

namespace App\Services\FlightX\Exceptions;

/**
 * Risposta HTTP 429: troppe richieste verso l'API FlightX in poco tempo.
 */
final class FlightXRateLimitException extends FlightXException
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        string $message,
        private readonly ?int $retryAfter = null,
        ?array $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $previous);
    }

    /**
     * Secondi suggeriti dal server prima di ritentare, letti dall'header
     * "Retry-After" della risposta, se presente.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

<?php

namespace App\Services\Hotelbeds\Exceptions;

/**
 * Risposta HTTP 429: troppe richieste verso l'API Hotelbeds in poco tempo.
 * L'ambiente test dichiara un limite di 50 richieste/minuto, esposto anche
 * dagli header `X-Ratelimit-Limit`/`X-Ratelimit-Remaining` (vedi `context()`).
 */
final class HotelbedsRateLimitException extends HotelbedsException
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
     * "Retry-After" della risposta, se presente. Hotelbeds non lo invia sempre.
     */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}

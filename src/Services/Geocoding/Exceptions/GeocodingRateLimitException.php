<?php

namespace App\Services\Geocoding\Exceptions;

/**
 * Risposta HTTP 429: troppe richieste verso il servizio di geocoding in poco
 * tempo. Con Nominatim questo non dovrebbe accadere se il throttle di
 * {@see \App\Services\Geocoding\NominatimClient} è attivo (1 richiesta/secondo
 * di default): se si verifica comunque, ridurre `minIntervalMs` non è la
 * soluzione, il servizio sta segnalando un limite più stringente del previsto.
 */
final class GeocodingRateLimitException extends GeocodingException
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

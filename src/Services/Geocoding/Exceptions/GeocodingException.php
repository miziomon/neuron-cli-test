<?php

namespace App\Services\Geocoding\Exceptions;

use App\Services\Geocoding\NominatimClient;
use RuntimeException;

/**
 * Base per tutte le eccezioni sollevate dai client di geocoding (namespace
 * volutamente provider-agnostico: oggi implementato da {@see NominatimClient}).
 *
 * Un LLM che genera un tool MCP attorno a un client di geocoding può
 * intercettare questa classe per gestire in modo generico qualsiasi errore,
 * oppure una delle sottoclassi più specifiche per reagire in modo differenziato
 * (client bloccato, rate limit, payload non valido, ecc.).
 */
class GeocodingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $context  Dati aggiuntivi utili al debug (es. corpo della
     *                                              risposta API). Non deve mai contenere dati di
     *                                              contatto (es. email) in chiaro: chi lancia
     *                                              l'eccezione è responsabile di oscurarli prima
     *                                              di passarli qui.
     */
    public function __construct(
        string $message,
        private readonly ?array $context = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function context(): ?array
    {
        return $this->context;
    }
}

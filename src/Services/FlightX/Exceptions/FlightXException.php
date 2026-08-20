<?php

namespace App\Services\FlightX\Exceptions;

use App\Services\FlightX\FlightXClient;
use RuntimeException;

/**
 * Base per tutte le eccezioni sollevate dal wrapper FlightX (Ugotto).
 *
 * Un LLM che genera un tool MCP attorno a {@see FlightXClient}
 * può intercettare questa classe per gestire in modo generico qualsiasi errore
 * del wrapper, oppure una delle sottoclassi più specifiche per reagire in modo
 * differenziato (credenziali scadute, payload non valido, rate limit, ecc.).
 */
class FlightXException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $context  Dati aggiuntivi utili al debug (es. corpo della
     *                                              risposta API). Non deve mai contenere credenziali
     *                                              in chiaro: chi lancia l'eccezione è responsabile
     *                                              di oscurarle prima di passarle qui.
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

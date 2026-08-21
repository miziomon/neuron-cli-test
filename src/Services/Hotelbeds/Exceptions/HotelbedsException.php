<?php

namespace App\Services\Hotelbeds\Exceptions;

use App\Services\Hotelbeds\HotelbedsClient;
use RuntimeException;

/**
 * Base per tutte le eccezioni sollevate dal wrapper Hotelbeds (HBX Group).
 *
 * Un LLM che genera un tool MCP attorno a {@see HotelbedsClient}
 * può intercettare questa classe per gestire in modo generico qualsiasi errore
 * del wrapper, oppure una delle sottoclassi più specifiche per reagire in modo
 * differenziato (firma/credenziali non valide, payload non valido, rate limit, ecc.).
 */
class HotelbedsException extends RuntimeException
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

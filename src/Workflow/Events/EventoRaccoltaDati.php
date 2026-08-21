<?php

declare(strict_types=1);

namespace App\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Fase di raccolta dati (receptionist). Arriva dal ConsulenteNode (con gli
 * eventuali suggerimenti di viaggio), dal loop di conversazione (con il
 * messaggio dell'utente) o dal ValidazioneNode (con gli errori da correggere).
 */
class EventoRaccoltaDati implements Event
{
    /**
     * @param array<string, string>|null $suggerimenti
     * @param string[] $errori
     */
    public function __construct(
        public readonly ?string $messaggio = null,
        public readonly ?array $suggerimenti = null,
        public readonly array $errori = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Fase di consulenza: è l'evento di avvio del workflow (messaggio null) e
 * l'evento di loop che riporta al ConsulenteNode l'input dell'utente.
 */
class EventoConsulenza implements Event
{
    public function __construct(
        public readonly ?string $messaggio = null,
    ) {
    }
}

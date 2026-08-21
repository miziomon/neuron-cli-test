<?php

declare(strict_types=1);

namespace App\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Dati del viaggio validati: avvia la fase voli (messaggioUtente null) e
 * funge da evento di loop del VoliNode con l'input dell'utente.
 * Il saluto è il messaggio di conferma del receptionist, mostrato insieme
 * alla prima risposta dell'agente voli.
 */
class EventoDatiValidati implements Event
{
    public function __construct(
        public readonly ?string $messaggioUtente = null,
        public readonly ?string $saluto = null,
    ) {
    }
}

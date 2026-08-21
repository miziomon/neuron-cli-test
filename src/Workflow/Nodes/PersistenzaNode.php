<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Workflow\Events\EventoFine;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Chiusura del workflow: compone la pratica finale nello stato (il file JSON
 * è già stato creato dopo la validazione e aggiornato a ogni selezione) e
 * termina con StopEvent. In caso di rinuncia nessun file è stato creato.
 */
class PersistenzaNode extends Node
{
    public function __invoke(EventoFine $event, WorkflowState $state): StopEvent
    {
        $state->set('pratica', [
            'utente' => $state->get('utente'),
            'viaggio' => $state->get('viaggio'),
            'volo_selezionato' => $state->get('volo_selezionato'),
            'hotel_selezionato' => $state->get('hotel_selezionato'),
        ]);

        return new StopEvent();
    }
}

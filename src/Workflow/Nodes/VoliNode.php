<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Neuron\TurnoVolo;
use App\Neuron\VoliAgent;
use App\Support\Pratica;
use App\Workflow\Events\EventoDatiValidati;
use App\Workflow\Events\EventoHotel;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Fase voli: conversa con il VoliAgent (tool MCP FlightX) fino alla scelta di
 * un'opzione o alla rinuncia, poi passa alla fase hotel. La selezione è
 * registrata nella pratica, senza operazioni verso il fornitore.
 *
 * Il turno LLM (che include la chiamata al tool cerca_voli) non viene MAI
 * rieseguito al resume: dopo un'interruzione il nodo consuma solo l'input
 * dell'utente e rientra nel loop.
 */
class VoliNode extends NodoConversazionale
{
    protected function creaAgente(WorkflowState $state): Agent
    {
        return $this->agentFactory !== null ? ($this->agentFactory)($state) : VoliAgent::make($state->get('viaggio'));
    }

    public function __invoke(EventoDatiValidati $event, WorkflowState $state): EventoDatiValidati|EventoHotel
    {
        if (($input = $this->inputRipresa()) !== null) {
            return new EventoDatiValidati(messaggioUtente: $input);
        }

        /** @var TurnoVolo $turno */
        $turno = $this->turno($state, 'storia_voli', TurnoVolo::class, $event->messaggioUtente ?? 'Procediamo con la ricerca dei voli.');

        if (!$turno->confermato) {
            $messaggio = $event->saluto !== null ? "{$event->saluto}\n\n{$turno->risposta}" : $turno->risposta;
            $this->chiediInput($messaggio);
        }

        if ($turno->voloSelezionato !== null && trim($turno->voloSelezionato) !== '') {
            $volo = [
                'descrizione' => trim($turno->voloSelezionato),
                'selezionato_il' => date(DATE_ATOM),
            ];
            $state->set('volo_selezionato', $volo);
            $this->aggiornaPratica($state, ['volo_selezionato' => $volo]);
        }

        return new EventoHotel(saluto: $turno->risposta);
    }

    /**
     * @param array<string, mixed> $sezioni
     */
    protected function aggiornaPratica(WorkflowState $state, array $sezioni): void
    {
        $percorso = $state->get('pratica_percorso');
        if (is_string($percorso)) {
            Pratica::apri($percorso)->aggiorna($sezioni);
        }
    }
}

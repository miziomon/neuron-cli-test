<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Neuron\ConsulenteAgent;
use App\Neuron\TurnoConsulente;
use App\Workflow\Events\EventoConsulenza;
use App\Workflow\Events\EventoRaccoltaDati;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Fase di consulenza: conversazione aperta sui viaggi (nessun tool MCP).
 * Gira in loop finché l'utente non dichiara di voler prenotare; in quel caso
 * passa i suggerimenti emersi alla raccolta dati.
 */
class ConsulenteNode extends NodoConversazionale
{
    protected function creaAgente(WorkflowState $state): Agent
    {
        return $this->agentFactory !== null ? ($this->agentFactory)($state) : ConsulenteAgent::make();
    }

    public function __invoke(EventoConsulenza $event, WorkflowState $state): EventoConsulenza|EventoRaccoltaDati
    {
        // Ripresa da un'interruzione: l'input dell'utente rientra nel loop.
        if (($input = $this->inputRipresa()) !== null) {
            return new EventoConsulenza($input);
        }

        /** @var TurnoConsulente $turno */
        $turno = $this->turno($state, 'storia_consulente', TurnoConsulente::class, $event->messaggio ?? 'Ciao!');

        if ($turno->prontoAPrenotare) {
            return new EventoRaccoltaDati(suggerimenti: $this->suggerimenti($turno));
        }

        $this->chiediInput($turno->risposta);
    }

    /**
     * @return array<string, string>
     */
    protected function suggerimenti(TurnoConsulente $turno): array
    {
        return array_filter([
            'destinazione' => $turno->destinazioneSuggerita,
            'aeroporto_partenza' => $turno->aeroportoPartenzaSuggerito,
            'aeroporto_destinazione' => $turno->aeroportoDestinazioneSuggerito,
            'data_partenza' => $turno->dataPartenzaSuggerita,
            'data_ritorno' => $turno->dataRitornoSuggerita,
            'note' => $turno->note,
        ], static fn(?string $valore): bool => $valore !== null && trim($valore) !== '');
    }
}

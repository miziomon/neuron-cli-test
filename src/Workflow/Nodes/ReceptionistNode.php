<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Neuron\ReceptionistAgent;
use App\Neuron\TurnoReceptionist;
use App\Workflow\Events\EventoDaValidare;
use App\Workflow\Events\EventoRaccoltaDati;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Fase di raccolta dati: conversa con il ReceptionistAgent fino alla conferma
 * esplicita dell'utente, poi passa il turno al ValidazioneNode. Gli errori di
 * validazione rientrano qui come messaggio di correzione per l'agente.
 */
class ReceptionistNode extends NodoConversazionale
{
    protected function creaAgente(WorkflowState $state): Agent
    {
        return $this->agentFactory !== null ? ($this->agentFactory)($state) : ReceptionistAgent::make();
    }

    public function __invoke(EventoRaccoltaDati $event, WorkflowState $state): EventoRaccoltaDati|EventoDaValidare
    {
        if (($input = $this->inputRipresa()) !== null) {
            return new EventoRaccoltaDati(messaggio: $input);
        }

        /** @var TurnoReceptionist $turno */
        $turno = $this->turno($state, 'storia_receptionist', TurnoReceptionist::class, $this->input($event));
        $state->set('turno_receptionist', $turno);

        if (!$turno->confermato) {
            $this->chiediInput($turno->risposta);
        }

        return new EventoDaValidare();
    }

    /**
     * Messaggio di input per l'agente: errori di validazione da correggere,
     * suggerimenti della consulenza da proporre come default, o input dell'utente.
     */
    protected function input(EventoRaccoltaDati $event): string
    {
        if ($event->errori !== []) {
            return 'Attenzione, questi dati che ho raccolto non sono validi o mancanti: '
                . implode('; ', $event->errori)
                . '. Chiedimi gentilmente di correggerli o di fornirli, aggiorna i campi corrispondenti e poi chiedimi di nuovo conferma del ricapitolo completo.';
        }

        if ($event->suggerimenti !== null && $event->suggerimenti !== []) {
            $suggerimenti = implode(', ', array_map(
                static fn(string $chiave, string $valore): string => "{$chiave}: {$valore}",
                array_keys($event->suggerimenti),
                $event->suggerimenti,
            ));

            return "L'utente ha appena parlato con il consulente di viaggi ed è pronto a prenotare. "
                . "Suggerimenti da proporre come default (l'utente può modificarli): {$suggerimenti}. "
                . 'Proponi subito un ricapitolo sintetico con questi valori.';
        }

        return $event->messaggio ?? 'Ciao!';
    }
}

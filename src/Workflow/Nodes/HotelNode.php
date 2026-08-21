<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Neuron\HotelAgent;
use App\Neuron\TurnoHotel;
use App\Support\Pratica;
use App\Support\Validazione;
use App\Workflow\Events\EventoFine;
use App\Workflow\Events\EventoHotel;
use NeuronAI\Agent\Agent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Fase hotel: chiede se serve un hotel e, in caso affermativo, conversa con
 * l'HotelAgent (tool MCP Hotelbeds) fino alla scelta o alla rinuncia. Date ed
 * età dei bambini non valide tornano all'agente per la correzione.
 */
class HotelNode extends NodoConversazionale
{
    protected function creaAgente(WorkflowState $state): Agent
    {
        return $this->agentFactory !== null ? ($this->agentFactory)($state) : HotelAgent::make($state->get('viaggio'));
    }

    public function __invoke(EventoHotel $event, WorkflowState $state): EventoHotel|EventoFine
    {
        if (($input = $this->inputRipresa()) !== null) {
            return new EventoHotel(messaggioUtente: $input);
        }

        /** @var TurnoHotel $turno */
        $turno = $this->turno($state, 'storia_hotel', TurnoHotel::class, $this->input($event));

        if (!$turno->confermato) {
            $messaggio = $event->saluto !== null ? "{$event->saluto}\n\n{$turno->risposta}" : $turno->risposta;
            $this->chiediInput($messaggio);
        }

        if ($turno->hotelSelezionato !== null && trim($turno->hotelSelezionato) !== '') {
            $errori = Validazione::erroriHotel($turno, $state->get('viaggio')['bambini'] ?? 0);
            if ($errori !== []) {
                return new EventoHotel(errori: $errori);
            }

            $hotel = [
                'descrizione' => trim($turno->hotelSelezionato),
                'check_in' => $turno->dataCheckIn !== null ? trim($turno->dataCheckIn) : null,
                'check_out' => $turno->dataCheckOut !== null ? trim($turno->dataCheckOut) : null,
                'camere' => $turno->camere ?? 1,
                'selezionato_il' => date(DATE_ATOM),
            ];
            $state->set('hotel_selezionato', $hotel);
            $this->aggiornaPratica($state, ['hotel_selezionato' => $hotel]);
        }

        return new EventoFine();
    }

    protected function input(EventoHotel $event): string
    {
        if ($event->errori !== []) {
            return 'Attenzione, questi dati del soggiorno non sono validi: '
                . implode('; ', $event->errori)
                . '. Chiedimi gentilmente di correggerli, aggiorna i campi corrispondenti e riproponimi la scelta.';
        }

        return $event->messaggioUtente ?? 'Volo sistemato. Passiamo agli hotel.';
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

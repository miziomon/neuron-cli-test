<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Neuron\TurnoReceptionist;
use App\Support\Pratica;
use App\Support\Validazione;
use App\Workflow\Events\EventoDaValidare;
use App\Workflow\Events\EventoDatiValidati;
use App\Workflow\Events\EventoFine;
use App\Workflow\Events\EventoRaccoltaDati;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Valida il TurnoReceptionist confermato. Solo i dati del viaggio sono
 * obbligatori; l'anagrafica è opzionale e viene sanificata (scartata se
 * malformata) senza bloccare il flusso. A validazione superata crea subito la
 * pratica su disco, così i dati non vanno persi se l'utente esce più tardi.
 */
class ValidazioneNode extends Node
{
    public function __invoke(EventoDaValidare $event, WorkflowState $state): EventoRaccoltaDati|EventoDatiValidati|EventoFine
    {
        $turno = $state->get('turno_receptionist');
        if (!$turno instanceof TurnoReceptionist) {
            return new EventoRaccoltaDati(errori: ['i dati del viaggio non sono stati raccolti']);
        }

        // Campi valorizzati ma non validi: torna al receptionist per la correzione.
        $errori = Validazione::erroriDatiViaggio($turno);
        if ($errori !== []) {
            return new EventoRaccoltaDati(errori: $errori);
        }

        $mancanti = Validazione::campiMancanti($turno);
        if ($mancanti !== []) {
            // Nessun dato di viaggio: l'utente ha rinunciato, si chiude senza salvare.
            if (Validazione::nessunDatoViaggio($turno)) {
                return new EventoFine();
            }

            return new EventoRaccoltaDati(errori: array_map(
                static fn(string $campo): string => "manca {$campo}",
                $mancanti,
            ));
        }

        $utente = Validazione::anagrafica($turno);
        $viaggio = [
            'destinazione' => trim($turno->destinazione),
            'aeroporto_partenza' => strtoupper(trim($turno->aeroportoPartenza)),
            'aeroporto_destinazione' => strtoupper(trim($turno->aeroportoDestinazione)),
            'data_partenza' => trim($turno->dataPartenza),
            'data_ritorno' => Validazione::vuoto($turno->dataRitorno) ? null : trim($turno->dataRitorno),
            'adulti' => $turno->adulti,
            'bambini' => $turno->bambini,
        ];

        $state->set('utente', $utente);
        $state->set('viaggio', $viaggio);

        // La pratica nasce qui: le selezioni successive la aggiornano.
        $pratica = Pratica::crea($state->get('dir_dati', $this->dirDatiDefault()), [
            'utente' => $utente,
            'viaggio' => $viaggio,
            'volo_selezionato' => null,
            'hotel_selezionato' => null,
        ]);
        $state->set('pratica_percorso', $pratica->percorso());

        return new EventoDatiValidati(saluto: $turno->risposta);
    }

    protected function dirDatiDefault(): string
    {
        return dirname(__DIR__, 3) . '/data';
    }
}

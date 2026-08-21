<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Struttura di ogni turno di conversazione del consulente di viaggi:
 * risposta libera più l'eventuale disponibilità a passare alla prenotazione
 * con i suggerimenti emersi dalla consulenza.
 */
class TurnoConsulente
{
    #[SchemaProperty(
        description: "Il messaggio da mostrare all'utente, in italiano",
        required: true
    )]
    public string $risposta;

    #[SchemaProperty(
        description: "true solo quando l'utente dichiara esplicitamente di voler prenotare (es. \"cercami i voli\", \"voglio prenotare\", \"procediamo\")",
        required: true
    )]
    public bool $prontoAPrenotare = false;

    #[SchemaProperty(description: "La destinazione emersa dalla consulenza, oppure null se non emersa")]
    public ?string $destinazioneSuggerita = null;

    #[SchemaProperty(description: "Codice IATA dell'aeroporto di partenza suggerito (3 lettere), oppure null se non emerso")]
    public ?string $aeroportoPartenzaSuggerito = null;

    #[SchemaProperty(description: "Codice IATA dell'aeroporto di destinazione suggerito (3 lettere), oppure null se non emerso")]
    public ?string $aeroportoDestinazioneSuggerito = null;

    #[SchemaProperty(description: "Data di partenza suggerita in formato YYYY-MM-DD, oppure null se non emersa")]
    public ?string $dataPartenzaSuggerita = null;

    #[SchemaProperty(description: "Data di ritorno suggerita in formato YYYY-MM-DD, oppure null se non emersa o per la sola andata")]
    public ?string $dataRitornoSuggerita = null;

    #[SchemaProperty(description: "Eventuali note utili per la prenotazione (budget, preferenze, numero di viaggiatori emersi), oppure null")]
    public ?string $note = null;
}

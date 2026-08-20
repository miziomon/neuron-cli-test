<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Struttura di ogni turno di conversazione dell'agente di ricerca voli.
 */
class TurnoVolo
{
    #[SchemaProperty(
        description: "Il messaggio da mostrare all'utente, in italiano",
        required: true
    )]
    public string $risposta;

    #[SchemaProperty(
        description: "Codice IATA dell'aeroporto di partenza (3 lettere), oppure null se non ancora noto"
    )]
    public ?string $aeroportoPartenza = null;

    #[SchemaProperty(
        description: "Codice IATA dell'aeroporto di destinazione (3 lettere), oppure null se non ancora noto"
    )]
    public ?string $aeroportoDestinazione = null;

    #[SchemaProperty(
        description: "Data di partenza in formato YYYY-MM-DD, oppure null se non ancora nota"
    )]
    public ?string $dataPartenza = null;

    #[SchemaProperty(
        description: "Data di ritorno in formato YYYY-MM-DD, oppure null per la sola andata"
    )]
    public ?string $dataRitorno = null;

    #[SchemaProperty(
        description: "Numero di passeggeri adulti, oppure null se non ancora noto"
    )]
    public ?int $adulti = null;

    #[SchemaProperty(
        description: "true solo dopo aver presentato all'utente l'elenco dei voli trovati",
        required: true
    )]
    public bool $ricercaCompletata = false;

    #[SchemaProperty(
        description: "true quando la fase deve terminare: dopo aver presentato l'elenco dei voli, oppure se l'utente rifiuta la ricerca",
        required: true
    )]
    public bool $confermato = false;
}

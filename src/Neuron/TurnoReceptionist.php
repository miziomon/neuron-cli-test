<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Struttura di ogni turno di conversazione del receptionist:
 * raccoglie anagrafica, viaggio e parametri di ricerca voli in un'unica fase.
 */
class TurnoReceptionist
{
    #[SchemaProperty(
        description: "Il messaggio da mostrare all'utente, in italiano",
        required: true
    )]
    public string $risposta;

    #[SchemaProperty(description: "Il nome fornito dall'utente, oppure null se non ancora noto")]
    public ?string $nome = null;

    #[SchemaProperty(description: "Il cognome fornito dall'utente, oppure null se non ancora noto")]
    public ?string $cognome = null;

    #[SchemaProperty(description: "L'indirizzo email fornito dall'utente, oppure null se non ancora noto")]
    public ?string $email = null;

    #[SchemaProperty(description: "La destinazione del viaggio, oppure null se non ancora nota")]
    public ?string $destinazione = null;

    #[SchemaProperty(description: "Codice IATA dell'aeroporto di partenza (3 lettere), oppure null se non ancora noto")]
    public ?string $aeroportoPartenza = null;

    #[SchemaProperty(description: "Codice IATA dell'aeroporto di destinazione (3 lettere), oppure null se non ancora noto")]
    public ?string $aeroportoDestinazione = null;

    #[SchemaProperty(description: "Data di partenza in formato YYYY-MM-DD, oppure null se non ancora nota")]
    public ?string $dataPartenza = null;

    #[SchemaProperty(description: "Data di ritorno in formato YYYY-MM-DD, oppure null per la sola andata")]
    public ?string $dataRitorno = null;

    #[SchemaProperty(description: "Numero di passeggeri adulti, oppure null se non ancora noto")]
    public ?int $adulti = null;

    #[SchemaProperty(description: "Numero di passeggeri bambini (2-11 anni), oppure null se non ancora noto")]
    public ?int $bambini = null;

    #[SchemaProperty(
        description: "true solo quando l'utente ha confermato esplicitamente che TUTTI i dati raccolti sono corretti, oppure in caso di rifiuto",
        required: true
    )]
    public bool $confermato = false;
}

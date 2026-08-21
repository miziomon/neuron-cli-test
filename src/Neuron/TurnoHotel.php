<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Struttura di ogni turno di conversazione dell'agente di ricerca hotel.
 */
class TurnoHotel
{
    #[SchemaProperty(
        description: "Il messaggio da mostrare all'utente, in italiano",
        required: true
    )]
    public string $risposta;

    #[SchemaProperty(
        description: "true se l'utente vuole cercare un hotel, false se non gli serve, null se non ha ancora risposto"
    )]
    public ?bool $hotelRichiesto = null;

    #[SchemaProperty(
        description: "Data di check-in in formato YYYY-MM-DD, oppure null se non ancora nota"
    )]
    public ?string $dataCheckIn = null;

    #[SchemaProperty(
        description: "Data di check-out in formato YYYY-MM-DD, oppure null se non ancora nota"
    )]
    public ?string $dataCheckOut = null;

    #[SchemaProperty(
        description: "Numero di camere richieste, oppure null se non ancora noto (default 1)"
    )]
    public ?int $camere = null;

    #[SchemaProperty(
        description: "Età dei bambini separate da virgola (es. \"5, 8\"), oppure null se non ci sono bambini o non sono ancora note"
    )]
    public ?string $etaBambini = null;

    #[SchemaProperty(
        description: "Descrizione leggibile dell'hotel scelto dall'utente (numero dell'opzione, nome, categoria, prezzo), oppure null se l'utente non ha ancora scelto o non vuole scegliere"
    )]
    public ?string $hotelSelezionato = null;

    #[SchemaProperty(
        description: "Codice univoco dell'hotel scelto, come riportato dal tool (es. \"12345\"), oppure null se non disponibile o se l'utente non ha scelto"
    )]
    public ?string $codiceHotel = null;

    #[SchemaProperty(
        description: "true quando la fase deve terminare: l'utente ha scelto un hotel, ha rifiutato la ricerca/selezione, oppure non vuole alcun hotel",
        required: true
    )]
    public bool $confermato = false;
}

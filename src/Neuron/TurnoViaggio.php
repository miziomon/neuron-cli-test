<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Struttura di ogni turno di conversazione dell'agente di viaggio.
 */
class TurnoViaggio
{
    #[SchemaProperty(
        description: "Il messaggio da mostrare all'utente, in italiano",
        required: true
    )]
    public string $risposta;

    #[SchemaProperty(
        description: "La destinazione del viaggio, oppure null se non ancora nota"
    )]
    public ?string $destinazione = null;

    #[SchemaProperty(
        description: "Il numero di persone che partecipano al viaggio, oppure null se non ancora noto"
    )]
    public ?int $numeroPersone = null;

    #[SchemaProperty(
        description: "Il periodo del viaggio (es. \"luglio 2026\"), oppure null se non ancora noto"
    )]
    public ?string $periodo = null;

    #[SchemaProperty(
        description: "true solo quando l'utente ha confermato esplicitamente che destinazione, numero di persone e periodo sono corretti",
        required: true
    )]
    public bool $confermato = false;
}

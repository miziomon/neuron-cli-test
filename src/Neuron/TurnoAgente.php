<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Struttura di ogni turno di conversazione dell'agente.
 */
class TurnoAgente
{
    #[SchemaProperty(
        description: "Il messaggio da mostrare all'utente, in italiano",
        required: true
    )]
    public string $risposta;

    #[SchemaProperty(
        description: "Il nome fornito dall'utente, oppure null se non ancora noto"
    )]
    public ?string $nome = null;

    #[SchemaProperty(
        description: "Il cognome fornito dall'utente, oppure null se non ancora noto"
    )]
    public ?string $cognome = null;

    #[SchemaProperty(
        description: "true solo quando l'utente ha confermato esplicitamente che nome e cognome sono corretti",
        required: true
    )]
    public bool $confermato = false;
}

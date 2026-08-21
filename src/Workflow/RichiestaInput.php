<?php

declare(strict_types=1);

namespace App\Workflow;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Interruzione human-in-the-loop del workflow: porta all'esterno il messaggio
 * dell'agente da mostrare all'utente e, al resume, riporta dentro il workflow
 * l'input digitato dall'utente.
 */
class RichiestaInput extends InterruptRequest
{
    public function __construct(
        string $message,
        protected ?string $input = null,
    ) {
        parent::__construct($message);
    }

    public function input(): ?string
    {
        return $this->input;
    }

    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'input' => $this->input,
        ];
    }
}

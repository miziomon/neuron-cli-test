<?php

declare(strict_types=1);

namespace App\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Fase hotel: avvia l'HotelNode dopo la selezione del volo e funge da evento
 * di loop (con l'input dell'utente o con gli errori di validazione su date ed
 * età dei bambini da rimandare all'agente).
 */
class EventoHotel implements Event
{
    /**
     * @param string[] $errori
     */
    public function __construct(
        public readonly ?string $messaggioUtente = null,
        public readonly array $errori = [],
        public readonly ?string $saluto = null,
    ) {
    }
}

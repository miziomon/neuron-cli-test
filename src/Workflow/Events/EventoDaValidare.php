<?php

declare(strict_types=1);

namespace App\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Il receptionist ha raccolto una conferma esplicita: il ValidazioneNode
 * deve verificare il TurnoReceptionist conservato nello stato del workflow.
 */
class EventoDaValidare implements Event
{
}

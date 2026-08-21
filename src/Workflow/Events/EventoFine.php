<?php

declare(strict_types=1);

namespace App\Workflow\Events;

use NeuronAI\Workflow\Events\Event;

/**
 * Tutte le fasi conversazionali sono concluse (o l'utente ha rinunciato):
 * il PersistenzaNode compone la pratica finale e chiude il workflow.
 */
class EventoFine implements Event
{
}

<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Workflow\Events\EventoConsulenza;
use App\Workflow\Nodes\ConsulenteNode;
use App\Workflow\Nodes\HotelNode;
use App\Workflow\Nodes\PersistenzaNode;
use App\Workflow\Nodes\ReceptionistNode;
use App\Workflow\Nodes\ValidazioneNode;
use App\Workflow\Nodes\VoliNode;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

/**
 * Workflow event-driven dell'applicazione: consulenza → raccolta dati →
 * validazione → voli → hotel → persistenza. La conversazione con l'utente
 * avviene tramite WorkflowInterrupt/RichiestaInput (human-in-the-loop).
 *
 * L'elenco dei nodi è iniettabile per i test (agenti con provider finti).
 */
class TravelWorkflow extends Workflow
{
    /** @var NodeInterface[]|null */
    protected ?array $nodiPersonalizzati;

    /**
     * @param NodeInterface[]|null $nodi Nodi alternativi (solo per i test).
     */
    public function __construct(
        ?PersistenceInterface $persistence = null,
        ?string $resumeToken = null,
        ?WorkflowState $state = null,
        ?array $nodi = null,
    ) {
        $this->nodiPersonalizzati = $nodi;
        parent::__construct($persistence, $resumeToken, $state);
    }

    protected function nodes(): array
    {
        return $this->nodiPersonalizzati ?? [
            new ConsulenteNode(),
            new ReceptionistNode(),
            new ValidazioneNode(),
            new VoliNode(),
            new HotelNode(),
            new PersistenzaNode(),
        ];
    }

    protected function startEvent(): Event
    {
        return new EventoConsulenza();
    }
}

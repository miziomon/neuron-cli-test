<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\RichiestaInput;
use Closure;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

/**
 * Base per i test dei nodi del workflow: invoca un nodo catturando l'eventuale
 * interruzione e costruisce factory di agenti finti con risposte in sequenza.
 */
abstract class NodoTestCase extends TestCase
{
    /**
     * Invoca il nodo come farebbe l'executor: restituisce l'evento prodotto
     * oppure il WorkflowInterrupt se il nodo ha chiesto un input all'utente.
     */
    protected function invoca(Node $node, Event $event, WorkflowState $state, ?RichiestaInput $ripresa = null): Event|WorkflowInterrupt
    {
        $node->setWorkflowContext($state, $event, $ripresa);

        try {
            $risultato = $node->run($event, $state);
            $this->assertInstanceOf(Event::class, $risultato);

            return $risultato;
        } catch (WorkflowInterrupt $interrupt) {
            return $interrupt;
        }
    }

    /**
     * Factory di agenti finti: a ogni invocazione restituisce un AgenteFinto
     * con la prossima risposta JSON della sequenza (l'ultima si ripete).
     *
     * @return Closure(WorkflowState): AgenteFinto
     */
    protected function agenteConRisposte(string ...$jsons): Closure
    {
        $i = 0;

        return static function () use ($jsons, &$i): AgenteFinto {
            $json = $jsons[min($i, count($jsons) - 1)];
            $i++;
            $messaggio = (new AssistantMessage($json))->setUsage(new Usage(inputTokens: 100, outputTokens: 20));

            return new AgenteFinto($messaggio);
        };
    }
}

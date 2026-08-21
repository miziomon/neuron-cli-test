<?php

declare(strict_types=1);

namespace App\Workflow\Nodes;

use App\Support\LlmRetry;
use App\Support\StoriaChat;
use App\Workflow\RichiestaInput;
use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Base dei nodi conversazionali del workflow: incapsula il ciclo
 * ripristino history → turno LLM strutturato → salvataggio history e usage.
 *
 * L'agente NON è mai una proprietà del nodo: i nodi vengono serializzati a ogni
 * interruzione (FilePersistence) e il provider HTTP non è serializzabile.
 * Per lo stesso motivo la factory iniettata nei test (una Closure) è esclusa
 * dalla serializzazione: al resume il nodo consuma solo l'input dell'utente e
 * non ha bisogno della factory.
 */
abstract class NodoConversazionale extends Node
{
    /** @var null|Closure(WorkflowState): Agent */
    protected ?Closure $agentFactory = null;

    /**
     * @param null|Closure(WorkflowState): Agent $agentFactory Factory dell'agente (test); null = agente reale di default.
     */
    public function __construct(?Closure $agentFactory = null)
    {
        $this->agentFactory = $agentFactory;
    }

    abstract protected function creaAgente(WorkflowState $state): Agent;

    /**
     * Esegue un turno LLM strutturato ripristinando la chat history dallo stato
     * e salvandola aggiornata, insieme all'usage dell'ultima risposta.
     */
    protected function turno(WorkflowState $state, string $chiaveStoria, string $classeTurno, string $input): object
    {
        $agent = $this->creaAgente($state);

        $storia = $state->get($chiaveStoria);
        if (is_string($storia) && $storia !== '') {
            $agent->setChatHistory(StoriaChat::daJson($storia));
        }

        $turno = LlmRetry::esegui(static fn(): object => $agent->structured(new UserMessage($input), $classeTurno));

        $state->set($chiaveStoria, StoriaChat::daChatHistory($agent->getChatHistory()));

        $usage = $agent->getChatHistory()->getLastMessage()->getUsage();
        if ($usage instanceof Usage) {
            $state->set('ultimo_usage', [
                'in' => $usage->inputTokens,
                'out' => $usage->outputTokens,
                'tot' => $usage->getTotal(),
            ]);
        }

        return $turno;
    }

    /**
     * Chiede un input all'utente interrompendo il workflow. Al resume il nodo
     * viene rieseguito dall'inizio: inputRipresa() consuma la richiesta e
     * restituisce l'input senza rieseguire il turno LLM.
     */
    protected function chiediInput(string $messaggio): never
    {
        $this->interrupt(new RichiestaInput($messaggio));

        // Mai raggiunto: senza una richiesta di resume interrupt() lancia sempre WorkflowInterrupt.
        throw new \LogicException('interrupt() deve lanciare WorkflowInterrupt quando non c\'è una ripresa in corso');
    }

    /**
     * Se il nodo sta riprendendo da un'interruzione, restituisce l'input
     * dell'utente; null se è un'esecuzione nuova.
     */
    protected function inputRipresa(): ?string
    {
        $richiesta = $this->consumeResumeRequest();

        return $richiesta instanceof RichiestaInput ? $richiesta->input() : null;
    }

    /**
     * La factory è una Closure (non serializzabile): la escludiamo. Al resume
     * serve solo consumare la richiesta di ripresa, non creare agenti.
     */
    public function __serialize(): array
    {
        return ['checkpoints' => $this->checkpoints];
    }

    public function __unserialize(array $data): void
    {
        $this->checkpoints = $data['checkpoints'] ?? [];
        $this->agentFactory = null;
    }
}

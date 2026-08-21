<?php

declare(strict_types=1);

namespace App\Support;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;

/**
 * Chat history serializzabile in JSON per attraversare le interruzioni del workflow.
 *
 * Lo WorkflowState viene serializzato su disco (FilePersistence) a ogni
 * WorkflowInterrupt: gli agenti non sono serializzabili (provider HTTP con
 * closure), quindi i nodi salvano solo la history come stringa JSON e
 * ricostruiscono l'agente a ogni invocazione ripristinando i messaggi.
 * Il formato è quello nativo di Neuron (lo stesso di FileChatHistory).
 */
class StoriaChat extends InMemoryChatHistory
{
    /**
     * Ricostruisce una history dalla sua rappresentazione JSON.
     * Una stringa vuota o non valida produce una history vuota.
     */
    public static function daJson(string $json): self
    {
        $storia = new self();
        $messaggi = json_decode($json, true);

        if (is_array($messaggi)) {
            foreach ($storia->deserializeMessages($messaggi) as $messaggio) {
                $storia->addMessage($messaggio);
            }
        }

        return $storia;
    }

    /**
     * Serializza una history esistente in JSON.
     */
    public static function daChatHistory(ChatHistoryInterface $history): string
    {
        return json_encode($history->getMessages(), JSON_THROW_ON_ERROR);
    }
}

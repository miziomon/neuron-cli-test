<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoAgente;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class NeuronAgentTest extends TestCase
{
    private function agenteConRisposta(string $json): AgenteFinto
    {
        $messaggio = (new AssistantMessage($json))
            ->setUsage(new Usage(inputTokens: 120, outputTokens: 30));

        return new AgenteFinto($messaggio);
    }

    public function testStructuredOutputCompleto(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"Perfetto, grazie!","nome":"Mario","cognome":"Rossi","email":"mario.rossi@example.com","confermato":true}'
        );

        /** @var TurnoAgente $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoAgente::class);

        $this->assertSame('Mario', $turno->nome);
        $this->assertSame('Rossi', $turno->cognome);
        $this->assertSame('mario.rossi@example.com', $turno->email);
        $this->assertTrue($turno->confermato);
        $this->assertSame('Perfetto, grazie!', $turno->risposta);
    }

    public function testStructuredOutputParziale(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"E il cognome?","nome":"Mario","cognome":null,"email":null,"confermato":false}'
        );

        /** @var TurnoAgente $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoAgente::class);

        $this->assertSame('Mario', $turno->nome);
        $this->assertNull($turno->cognome);
        $this->assertNull($turno->email);
        $this->assertFalse($turno->confermato);
    }

    public function testConteggioTokenDisponibile(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"Ciao!","nome":null,"cognome":null,"email":null,"confermato":false}'
        );

        $agent->structured(new UserMessage('test'), TurnoAgente::class);

        $usage = $agent->resolveState()->getChatHistory()->getLastMessage()->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(120, $usage->inputTokens);
        $this->assertSame(30, $usage->outputTokens);
        $this->assertSame(150, $usage->getTotal());
    }
}

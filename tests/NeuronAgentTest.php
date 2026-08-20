<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoAgente;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use PHPUnit\Framework\TestCase;

class NeuronAgentTest extends TestCase
{
    private function agenteConRisposta(string $json): Agent
    {
        $messaggio = (new AssistantMessage($json))
            ->setUsage(new Usage(inputTokens: 120, outputTokens: 30));

        return new class ($messaggio) extends Agent {
            public function __construct(
                private readonly AssistantMessage $risposta
            ) {
                parent::__construct();
            }

            protected function provider(): AIProviderInterface
            {
                return new FakeProvider($this->risposta);
            }

            protected function instructions(): string
            {
                return 'Agente di test';
            }
        };
    }

    public function testStructuredOutputCompleto(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"Perfetto, grazie!","nome":"Mario","cognome":"Rossi","confermato":true}'
        );

        /** @var TurnoAgente $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoAgente::class);

        $this->assertSame('Mario', $turno->nome);
        $this->assertSame('Rossi', $turno->cognome);
        $this->assertTrue($turno->confermato);
        $this->assertSame('Perfetto, grazie!', $turno->risposta);
    }

    public function testStructuredOutputParziale(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"E il cognome?","nome":"Mario","cognome":null,"confermato":false}'
        );

        /** @var TurnoAgente $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoAgente::class);

        $this->assertSame('Mario', $turno->nome);
        $this->assertNull($turno->cognome);
        $this->assertFalse($turno->confermato);
    }

    public function testConteggioTokenDisponibile(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"Ciao!","nome":null,"cognome":null,"confermato":false}'
        );

        $agent->structured(new UserMessage('test'), TurnoAgente::class);

        $usage = $agent->resolveState()->getChatHistory()->getLastMessage()->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(120, $usage->inputTokens);
        $this->assertSame(30, $usage->outputTokens);
        $this->assertSame(150, $usage->getTotal());
    }
}

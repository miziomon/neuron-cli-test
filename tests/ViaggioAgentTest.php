<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoViaggio;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class ViaggioAgentTest extends TestCase
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
            '{"risposta":"Ottimo, buon viaggio!","destinazione":"Roma","numeroPersone":2,"periodo":"luglio 2026","confermato":true}'
        );

        /** @var TurnoViaggio $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoViaggio::class);

        $this->assertSame('Roma', $turno->destinazione);
        $this->assertSame(2, $turno->numeroPersone);
        $this->assertSame('luglio 2026', $turno->periodo);
        $this->assertTrue($turno->confermato);
    }

    public function testStructuredOutputParziale(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"Quante persone sarete?","destinazione":"Roma","numeroPersone":null,"periodo":null,"confermato":false}'
        );

        /** @var TurnoViaggio $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoViaggio::class);

        $this->assertSame('Roma', $turno->destinazione);
        $this->assertNull($turno->numeroPersone);
        $this->assertNull($turno->periodo);
        $this->assertFalse($turno->confermato);
    }
}

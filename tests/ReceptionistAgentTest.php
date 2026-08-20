<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoReceptionist;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class ReceptionistAgentTest extends TestCase
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
            '{"risposta":"Grazie, dati confermati!","nome":"Mario","cognome":"Rossi","email":"mario.rossi@example.com",'
            . '"destinazione":"Barcellona","aeroportoPartenza":"LIN","aeroportoDestinazione":"BCN",'
            . '"dataPartenza":"2026-09-15","dataRitorno":null,"adulti":2,"bambini":1,"confermato":true}'
        );

        /** @var TurnoReceptionist $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoReceptionist::class);

        $this->assertSame('Mario', $turno->nome);
        $this->assertSame('Rossi', $turno->cognome);
        $this->assertSame('mario.rossi@example.com', $turno->email);
        $this->assertSame('Barcellona', $turno->destinazione);
        $this->assertSame('LIN', $turno->aeroportoPartenza);
        $this->assertSame('BCN', $turno->aeroportoDestinazione);
        $this->assertSame('2026-09-15', $turno->dataPartenza);
        $this->assertNull($turno->dataRitorno);
        $this->assertSame(2, $turno->adulti);
        $this->assertSame(1, $turno->bambini);
        $this->assertTrue($turno->confermato);
    }

    public function testStructuredOutputParziale(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"E il cognome?","nome":"Mario","cognome":null,"email":null,"destinazione":null,'
            . '"aeroportoPartenza":null,"aeroportoDestinazione":null,"dataPartenza":null,"dataRitorno":null,'
            . '"adulti":null,"bambini":null,"confermato":false}'
        );

        /** @var TurnoReceptionist $turno */
        $turno = $agent->structured(new UserMessage('test'), TurnoReceptionist::class);

        $this->assertSame('Mario', $turno->nome);
        $this->assertNull($turno->cognome);
        $this->assertNull($turno->email);
        $this->assertNull($turno->destinazione);
        $this->assertNull($turno->adulti);
        $this->assertFalse($turno->confermato);
    }

    public function testConteggioTokenDisponibile(): void
    {
        $agent = $this->agenteConRisposta(
            '{"risposta":"Ciao!","nome":null,"cognome":null,"email":null,"destinazione":null,'
            . '"aeroportoPartenza":null,"aeroportoDestinazione":null,"dataPartenza":null,"dataRitorno":null,'
            . '"adulti":null,"bambini":null,"confermato":false}'
        );

        $agent->structured(new UserMessage('test'), TurnoReceptionist::class);

        $usage = $agent->resolveState()->getChatHistory()->getLastMessage()->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(120, $usage->inputTokens);
        $this->assertSame(30, $usage->outputTokens);
        $this->assertSame(150, $usage->getTotal());
    }
}

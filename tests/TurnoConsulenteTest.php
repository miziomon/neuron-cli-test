<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoConsulente;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

class TurnoConsulenteTest extends TestCase
{
    private function turnoDaJson(string $json): TurnoConsulente
    {
        $agent = new AgenteFinto(new AssistantMessage($json));

        return $agent->structured(new UserMessage('test'), TurnoConsulente::class);
    }

    public function testStructuredOutputCompleto(): void
    {
        $turno = $this->turnoDaJson(
            '{"risposta":"Passiamo alla prenotazione!","prontoAPrenotare":true,'
            . '"destinazioneSuggerita":"Tokyo","aeroportoPartenzaSuggerito":"MXP","aeroportoDestinazioneSuggerito":"NRT",'
            . '"dataPartenzaSuggerita":"2026-10-10","dataRitornoSuggerita":"2026-10-24","note":"budget 2000 euro"}'
        );

        $this->assertTrue($turno->prontoAPrenotare);
        $this->assertSame('Tokyo', $turno->destinazioneSuggerita);
        $this->assertSame('MXP', $turno->aeroportoPartenzaSuggerito);
        $this->assertSame('NRT', $turno->aeroportoDestinazioneSuggerito);
        $this->assertSame('2026-10-10', $turno->dataPartenzaSuggerita);
        $this->assertSame('2026-10-24', $turno->dataRitornoSuggerita);
        $this->assertSame('budget 2000 euro', $turno->note);
    }

    public function testStructuredOutputMinimo(): void
    {
        $turno = $this->turnoDaJson(
            '{"risposta":"Ti consiglio la Sardegna.","prontoAPrenotare":false}'
        );

        $this->assertFalse($turno->prontoAPrenotare);
        $this->assertNull($turno->destinazioneSuggerita);
        $this->assertNull($turno->dataPartenzaSuggerita);
        $this->assertNull($turno->note);
    }
}

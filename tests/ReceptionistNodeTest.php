<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\Events\EventoDaValidare;
use App\Workflow\Events\EventoRaccoltaDati;
use App\Workflow\Nodes\ReceptionistNode;
use App\Workflow\RichiestaInput;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;

class ReceptionistNodeTest extends NodoTestCase
{
    public function testTurnoNonConfermatoInterrompePerInput(): void
    {
        $node = new ReceptionistNode($this->agenteConRisposte(
            '{"risposta":"Quando vuoi partire?","confermato":false}'
        ));
        $state = new WorkflowState();

        $risultato = $this->invoca($node, new EventoRaccoltaDati(), $state);

        $this->assertInstanceOf(WorkflowInterrupt::class, $risultato);
        $this->assertSame('Quando vuoi partire?', $risultato->getRequest()->getMessage());
        $this->assertIsString($state->get('storia_receptionist'));
    }

    public function testRipresaRiportaInputNelLoop(): void
    {
        $node = new ReceptionistNode(static function (): never {
            throw new \LogicException('Il modello non deve essere chiamato al resume');
        });
        $state = new WorkflowState();

        $risultato = $this->invoca($node, new EventoRaccoltaDati(), $state, new RichiestaInput('', 'Il 15 settembre'));

        $this->assertInstanceOf(EventoRaccoltaDati::class, $risultato);
        $this->assertSame('Il 15 settembre', $risultato->messaggio);
    }

    public function testConfermaProduceEventoDaValidare(): void
    {
        $node = new ReceptionistNode($this->agenteConRisposte(
            '{"risposta":"Grazie, dati confermati!","destinazione":"Barcellona","aeroportoPartenza":"LIN",'
            . '"aeroportoDestinazione":"BCN","dataPartenza":"2026-09-15","adulti":2,"bambini":0,"confermato":true}'
        ));
        $state = new WorkflowState();

        $risultato = $this->invoca($node, new EventoRaccoltaDati('confermo'), $state);

        $this->assertInstanceOf(EventoDaValidare::class, $risultato);
        $this->assertSame('Barcellona', $state->get('turno_receptionist')->destinazione);
    }

    public function testSuggerimentiComandanoIlMessaggioDiAvvio(): void
    {
        $catturato = null;
        $factory = function () use (&$catturato): AgenteFinto {
            $catturato = new AgenteFinto(new \NeuronAI\Chat\Messages\AssistantMessage(
                '{"risposta":"Va bene?","confermato":false}'
            ));

            return $catturato;
        };

        $node = new ReceptionistNode($factory);
        $state = new WorkflowState();

        $this->invoca($node, new EventoRaccoltaDati(suggerimenti: ['destinazione' => 'Tokyo', 'aeroporto_partenza' => 'MXP']), $state);

        $ultimoUtente = $catturato->getChatHistory()->getMessages()[0];
        $this->assertStringContainsString('Tokyo', $ultimoUtente->getContent());
        $this->assertStringContainsString('MXP', $ultimoUtente->getContent());
    }

    public function testErroriValidazioneDiventanoMessaggioDiCorrezione(): void
    {
        $catturato = null;
        $factory = function () use (&$catturato): AgenteFinto {
            $messaggio = new \NeuronAI\Chat\Messages\AssistantMessage(
                '{"risposta":"Correggiamo.","confermato":false}'
            );
            $catturato = new AgenteFinto($messaggio);

            return $catturato;
        };

        $node = new ReceptionistNode($factory);
        $state = new WorkflowState();

        $this->invoca($node, new EventoRaccoltaDati(errori: ['manca la destinazione']), $state);

        $this->assertStringContainsString('manca la destinazione', $catturato->getChatHistory()->getMessages()[0]->getContent());
    }
}

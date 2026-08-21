<?php

declare(strict_types=1);

namespace App\Tests;

use App\Neuron\TurnoReceptionist;
use App\Support\ArchivioSqlite;
use App\Workflow\Events\EventoDaValidare;
use App\Workflow\Events\EventoDatiValidati;
use App\Workflow\Events\EventoFine;
use App\Workflow\Events\EventoRaccoltaDati;
use App\Workflow\Nodes\ValidazioneNode;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;

class ValidazioneNodeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/validazione_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*.{json,sqlite}', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function statoConTurno(TurnoReceptionist $turno): WorkflowState
    {
        return new WorkflowState([
            'turno_receptionist' => $turno,
            'dir_dati' => $this->directory,
            'db_dati' => $this->directory . '/neuron.sqlite',
            '__workflowId' => 'chat_test_validazione',
        ]);
    }

    private function turnoValido(): TurnoReceptionist
    {
        $turno = new TurnoReceptionist();
        $turno->risposta = 'Grazie, dati confermati!';
        $turno->destinazione = 'Barcellona';
        $turno->aeroportoPartenza = 'lin';
        $turno->aeroportoDestinazione = 'BCN';
        $turno->dataPartenza = '2026-09-15';
        $turno->adulti = 2;
        $turno->bambini = 1;
        $turno->confermato = true;

        return $turno;
    }

    public function testDatiValidiSenzaAnagraficaProduconoDatiValidati(): void
    {
        $node = new ValidazioneNode();
        $state = $this->statoConTurno($this->turnoValido());

        $risultato = $node(new EventoDaValidare(), $state);

        $this->assertInstanceOf(EventoDatiValidati::class, $risultato);
        $this->assertSame('Grazie, dati confermati!', $risultato->saluto);

        // Anagrafica assente ma il viaggio è completo: si procede
        $this->assertNull($state->get('utente'));
        $viaggio = $state->get('viaggio');
        $this->assertSame('Barcellona', $viaggio['destinazione']);
        $this->assertSame('LIN', $viaggio['aeroporto_partenza']);
        $this->assertSame('BCN', $viaggio['aeroporto_destinazione']);
        $this->assertSame('2026-09-15', $viaggio['data_partenza']);
        $this->assertNull($viaggio['data_ritorno']);
        $this->assertSame(2, $viaggio['adulti']);
        $this->assertSame(1, $viaggio['bambini']);

        // La pratica è stata creata subito su disco con utente null
        $percorso = $state->get('pratica_percorso');
        $this->assertIsString($percorso);
        $dati = json_decode((string) file_get_contents($percorso), true);
        $this->assertNull($dati['utente']);
        $this->assertSame('Barcellona', $dati['viaggio']['destinazione']);

        // E anche sul DB SQLite, per chat
        $riga = (new ArchivioSqlite($this->directory . '/neuron.sqlite'))->pratica('chat_test_validazione');
        $this->assertNotNull($riga);
        $this->assertNull($riga['nome']);
        $this->assertSame('Barcellona', $riga['destinazione']);
        $this->assertSame('LIN', $riga['aeroporto_partenza']);
    }

    public function testDatiValidiConAnagrafica(): void
    {
        $turno = $this->turnoValido();
        $turno->nome = 'Mario';
        $turno->cognome = 'Rossi';
        $turno->email = 'mario.rossi@example.com';

        $node = new ValidazioneNode();
        $state = $this->statoConTurno($turno);

        $risultato = $node(new EventoDaValidare(), $state);

        $this->assertInstanceOf(EventoDatiValidati::class, $risultato);
        $this->assertSame(
            ['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.com'],
            $state->get('utente'),
        );
    }

    public function testCampiNonValidiTornanoAlReceptionistConErrori(): void
    {
        $turno = $this->turnoValido();
        $turno->aeroportoPartenza = 'MILANO';

        $node = new ValidazioneNode();
        $state = $this->statoConTurno($turno);

        $risultato = $node(new EventoDaValidare(), $state);

        $this->assertInstanceOf(EventoRaccoltaDati::class, $risultato);
        $this->assertNotEmpty($risultato->errori);
        $this->assertStringContainsString('IATA', $risultato->errori[0]);
        $this->assertNull($state->get('viaggio'));
    }

    public function testDatiParzialiTornanoAlReceptionistConMancanti(): void
    {
        $turno = new TurnoReceptionist();
        $turno->risposta = 'ok';
        $turno->destinazione = 'Roma';
        $turno->confermato = true;

        $node = new ValidazioneNode();
        $state = $this->statoConTurno($turno);

        $risultato = $node(new EventoDaValidare(), $state);

        $this->assertInstanceOf(EventoRaccoltaDati::class, $risultato);
        $this->assertContains('manca la data di partenza', $risultato->errori);
    }

    public function testRifiutoSenzaDatiProduceEventoFineSenzaPratica(): void
    {
        $turno = new TurnoReceptionist();
        $turno->risposta = 'Va bene, alla prossima!';
        $turno->confermato = true;

        $node = new ValidazioneNode();
        $state = $this->statoConTurno($turno);

        $risultato = $node(new EventoDaValidare(), $state);

        $this->assertInstanceOf(EventoFine::class, $risultato);
        $this->assertNull($state->get('pratica_percorso'));
        $this->assertSame([], glob($this->directory . '/*.json') ?: []);
    }

    public function testTurnoAssenteTornaAlReceptionist(): void
    {
        $node = new ValidazioneNode();
        $state = new WorkflowState(['dir_dati' => $this->directory]);

        $risultato = $node(new EventoDaValidare(), $state);

        $this->assertInstanceOf(EventoRaccoltaDati::class, $risultato);
        $this->assertNotEmpty($risultato->errori);
    }
}

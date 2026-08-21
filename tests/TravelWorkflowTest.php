<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\ArchivioSqlite;
use App\Workflow\Nodes\ConsulenteNode;
use App\Workflow\Nodes\HotelNode;
use App\Workflow\Nodes\PersistenzaNode;
use App\Workflow\Nodes\ReceptionistNode;
use App\Workflow\Nodes\ValidazioneNode;
use App\Workflow\Nodes\VoliNode;
use App\Workflow\RichiestaInput;
use App\Workflow\TravelWorkflow;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\WorkflowState;

/**
 * Test di integrazione: l'intero workflow viene guidato attraverso interruzioni
 * e riprese, come fa il ciclo CLI di chat.php, con agenti finti (nessuna
 * chiamata HTTP, nessun server MCP, pratiche in directory temporanea).
 */
class TravelWorkflowTest extends NodoTestCase
{
    private string $directory;
    private InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/workflow_test_' . uniqid();
        $this->persistence = new InMemoryPersistence();
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

    /**
     * @return array{0: WorkflowState|null, 1: WorkflowInterrupt|null, 2: string|null}
     */
    private function esegui(?string $workflowId, ?RichiestaInput $ripresa, array $nodi, ?WorkflowState $state = null): array
    {
        $workflow = TravelWorkflow::make($this->persistence, $workflowId, $state, $nodi);

        try {
            return [$workflow->init($ripresa)->run(), null, $workflow->getWorkflowId()];
        } catch (WorkflowInterrupt $interrupt) {
            return [null, $interrupt, $interrupt->getWorkflowId()];
        }
    }

    public function testFlussoCompletoConsulenzaPrenotazioneVoloRinunciaHotel(): void
    {
        $nodi = [
            new ConsulenteNode($this->agenteConRisposte(
                '{"risposta":"Ciao! Sono il tuo consulente di viaggi.","prontoAPrenotare":false}',
                '{"risposta":"Perfetto, passiamo alla prenotazione!","prontoAPrenotare":true,'
                . '"destinazioneSuggerita":"Tokyo","aeroportoPartenzaSuggerito":"MXP","aeroportoDestinazioneSuggerito":"NRT",'
                . '"dataPartenzaSuggerita":"2026-10-10","dataRitornoSuggerita":"2026-10-24"}',
            )),
            new ReceptionistNode($this->agenteConRisposte(
                '{"risposta":"Ti propongo MXP → NRT, 10/10 → 24/10, 2 adulti. Confermi?",'
                . '"destinazione":"Tokyo","aeroportoPartenza":"MXP","aeroportoDestinazione":"NRT",'
                . '"dataPartenza":"2026-10-10","dataRitorno":"2026-10-24","adulti":2,"bambini":0,"confermato":false}',
                '{"risposta":"Grazie, dati confermati!","destinazione":"Tokyo","aeroportoPartenza":"MXP",'
                . '"aeroportoDestinazione":"NRT","dataPartenza":"2026-10-10","dataRitorno":"2026-10-24",'
                . '"adulti":2,"bambini":0,"confermato":true}',
            )),
            new ValidazioneNode(),
            new VoliNode($this->agenteConRisposte(
                '{"risposta":"Ecco i voli: 1) ... 2) ...","ricercaCompletata":true,"confermato":false}',
                '{"risposta":"Hai scelto il volo 2!","ricercaCompletata":true,'
                . '"voloSelezionato":"Opzione 2: MXP-NRT, ANA, 950 EUR","codiceVolo":"NH206","confermato":true}',
            )),
            new HotelNode($this->agenteConRisposte(
                '{"risposta":"Ti serve anche un hotel?","confermato":false}',
                '{"risposta":"Va bene, nessun hotel.","hotelRichiesto":false,"confermato":true}',
            )),
            new PersistenzaNode(),
        ];

        $state = new WorkflowState([
            'dir_dati' => $this->directory,
            'db_dati' => $this->directory . '/neuron.sqlite',
        ]);

        // 1. Avvio: il consulente saluta e il workflow si interrompe
        [$finale, $interrupt, $id] = $this->esegui(null, null, $nodi, $state);
        $this->assertNull($finale);
        $this->assertSame('Ciao! Sono il tuo consulente di viaggi.', $interrupt->getRequest()->getMessage());
        $this->assertInstanceOf(ConsulenteNode::class, $interrupt->getNode());

        // 2. L'utente vuole prenotare: consulente → receptionist (con suggerimenti) → interruzione
        [, $interrupt, $id] = $this->esegui($id, new RichiestaInput('', 'Ok, voglio prenotare'), $nodi);
        $this->assertStringContainsString('Confermi?', $interrupt->getRequest()->getMessage());
        $this->assertInstanceOf(ReceptionistNode::class, $interrupt->getNode());

        // 3. Conferma i dati: validazione → voli → interruzione con l'elenco
        [, $interrupt, $id] = $this->esegui($id, new RichiestaInput('', 'Confermo'), $nodi);
        $this->assertInstanceOf(VoliNode::class, $interrupt->getNode());
        $messaggio = $interrupt->getRequest()->getMessage();
        $this->assertStringContainsString('Grazie, dati confermati!', $messaggio);
        $this->assertStringContainsString('Ecco i voli', $messaggio);

        // La pratica esiste già su disco dopo la validazione
        $pratiche = glob($this->directory . '/*.json') ?: [];
        $this->assertCount(1, $pratiche);

        // 4. Sceglie il volo: hotel → interruzione
        [, $interrupt, $id] = $this->esegui($id, new RichiestaInput('', 'Scelgo la 2'), $nodi);
        $this->assertInstanceOf(HotelNode::class, $interrupt->getNode());
        $this->assertStringContainsString('Ti serve anche un hotel?', $interrupt->getRequest()->getMessage());

        // 5. Rinuncia all'hotel: il workflow termina
        [$finale] = $this->esegui($id, new RichiestaInput('', 'No grazie'), $nodi);
        $this->assertInstanceOf(WorkflowState::class, $finale);

        // Stato finale: anagrafica assente (opzionale), viaggio e volo registrati
        $pratica = $finale->get('pratica');
        $this->assertNull($pratica['utente']);
        $this->assertSame('Tokyo', $pratica['viaggio']['destinazione']);
        $this->assertSame('MXP', $pratica['viaggio']['aeroporto_partenza']);
        $this->assertSame('2026-10-24', $pratica['viaggio']['data_ritorno']);
        $this->assertSame('Opzione 2: MXP-NRT, ANA, 950 EUR', $pratica['volo_selezionato']['descrizione']);
        $this->assertNull($pratica['hotel_selezionato']);

        // Il file della pratica riflette lo stato finale
        $dati = json_decode((string) file_get_contents($finale->get('pratica_percorso')), true);
        $this->assertNull($dati['utente']);
        $this->assertSame('Opzione 2: MXP-NRT, ANA, 950 EUR', $dati['volo_selezionato']['descrizione']);
        $this->assertSame('NH206', $dati['volo_selezionato']['codice']);

        // E il dettaglio completo è sulla tabella pratiche del DB SQLite
        // (i messaggi della chat sono registrati dal ciclo CLI, non dal workflow)
        $riga = (new ArchivioSqlite($this->directory . '/neuron.sqlite'))->pratica($id);
        $this->assertNotNull($riga);
        $this->assertSame('Tokyo', $riga['destinazione']);
        $this->assertSame('NH206', $riga['codice_volo']);
        $this->assertSame('Opzione 2: MXP-NRT, ANA, 950 EUR', $riga['volo_descrizione']);
        $this->assertNull($riga['codice_hotel']);
    }

    public function testRifiutoAllaRaccoltaDatiChiudeSenzaPratica(): void
    {
        $nodi = [
            new ConsulenteNode($this->agenteConRisposte(
                '{"risposta":"Passiamo alla prenotazione!","prontoAPrenotare":true}',
            )),
            new ReceptionistNode($this->agenteConRisposte(
                '{"risposta":"Va bene, alla prossima!","confermato":true}',
            )),
            new ValidazioneNode(),
            new VoliNode($this->agenteConRisposte('{}')),
            new HotelNode($this->agenteConRisposte('{}')),
            new PersistenzaNode(),
        ];

        $state = new WorkflowState(['dir_dati' => $this->directory]);

        // Il consulente passa subito alla prenotazione, l'utente rifiuta i dati:
        // il workflow termina senza interruzioni e senza creare pratiche
        [$finale, $interrupt] = $this->esegui(null, null, $nodi, $state);

        $this->assertNull($interrupt);
        $this->assertInstanceOf(WorkflowState::class, $finale);
        $this->assertNull($finale->get('pratica')['viaggio']);
        $this->assertNull($finale->get('pratica_percorso'));
        $this->assertSame([], glob($this->directory . '/*.json') ?: []);
    }
}

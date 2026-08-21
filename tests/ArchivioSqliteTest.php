<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\ArchivioSqlite;
use PHPUnit\Framework\TestCase;

class ArchivioSqliteTest extends TestCase
{
    private string $percorso;
    private ArchivioSqlite $archivio;

    protected function setUp(): void
    {
        $this->percorso = sys_get_temp_dir() . '/archivio_test_' . uniqid() . '/neuron.sqlite';
        $this->archivio = new ArchivioSqlite($this->percorso);
    }

    protected function tearDown(): void
    {
        // Chiude la connessione PDO prima di eliminare il file (lock su Windows)
        unset($this->archivio);

        if (is_file($this->percorso)) {
            unlink($this->percorso);
        }
        if (is_dir(dirname($this->percorso))) {
            rmdir(dirname($this->percorso));
        }
    }

    public function testRegistraMessaggiConRuoloEToken(): void
    {
        $this->archivio->registraMessaggio('chat_1', 'agente', 'Ciao, come posso aiutarti?', ['in' => 100, 'out' => 20, 'tot' => 120]);
        $this->archivio->registraMessaggio('chat_1', 'utente', 'Voglio andare al mare');

        $messaggi = $this->archivio->messaggi('chat_1');

        $this->assertCount(2, $messaggi);

        $this->assertSame('agente', $messaggi[0]['ruolo']);
        $this->assertSame('Ciao, come posso aiutarti?', $messaggi[0]['messaggio']);
        $this->assertSame(100, (int) $messaggi[0]['token_input']);
        $this->assertSame(20, (int) $messaggi[0]['token_output']);
        $this->assertSame(120, (int) $messaggi[0]['token_totali']);
        $this->assertNotEmpty($messaggi[0]['creato_il']);

        // I messaggi dell'utente non hanno token
        $this->assertSame('utente', $messaggi[1]['ruolo']);
        $this->assertNull($messaggi[1]['token_input']);
        $this->assertNull($messaggi[1]['token_output']);
        $this->assertNull($messaggi[1]['token_totali']);
    }

    public function testMessaggiFiltratiPerChatId(): void
    {
        $this->archivio->registraMessaggio('chat_1', 'agente', 'Messaggio chat 1');
        $this->archivio->registraMessaggio('chat_2', 'agente', 'Messaggio chat 2');
        $this->archivio->registraMessaggio('chat_1', 'utente', 'Risposta chat 1');

        $this->assertCount(2, $this->archivio->messaggi('chat_1'));
        $this->assertCount(1, $this->archivio->messaggi('chat_2'));
        $this->assertSame([], $this->archivio->messaggi('chat_inesistente'));
    }

    public function testCicloCompletoPraticaConCodiciVoloEHotel(): void
    {
        $viaggio = [
            'destinazione' => 'Tokyo',
            'aeroporto_partenza' => 'MXP',
            'aeroporto_destinazione' => 'NRT',
            'data_partenza' => '2026-10-10',
            'data_ritorno' => '2026-10-24',
            'adulti' => 2,
            'bambini' => 0,
        ];

        $this->archivio->creaPratica('chat_1', null, $viaggio);
        $this->archivio->aggiornaVolo('chat_1', 'Opzione 2: MXP-NRT, ANA, 950 EUR', 'NH206');
        $this->archivio->aggiornaHotel('chat_1', [
            'descrizione' => 'Hotel Sunroute, 3 stelle, 800 EUR',
            'codice' => '12345',
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-24',
            'camere' => 1,
        ]);

        $pratica = $this->archivio->pratica('chat_1');

        $this->assertNotNull($pratica);
        // Anagrafica non fornita
        $this->assertNull($pratica['nome']);
        $this->assertNull($pratica['cognome']);
        $this->assertNull($pratica['email']);
        // Viaggio
        $this->assertSame('Tokyo', $pratica['destinazione']);
        $this->assertSame('MXP', $pratica['aeroporto_partenza']);
        $this->assertSame('NRT', $pratica['aeroporto_destinazione']);
        $this->assertSame('2026-10-10', $pratica['data_partenza']);
        $this->assertSame(2, (int) $pratica['adulti']);
        // Selezioni con i codici univoci
        $this->assertSame('Opzione 2: MXP-NRT, ANA, 950 EUR', $pratica['volo_descrizione']);
        $this->assertSame('NH206', $pratica['codice_volo']);
        $this->assertSame('Hotel Sunroute, 3 stelle, 800 EUR', $pratica['hotel_descrizione']);
        $this->assertSame('12345', $pratica['codice_hotel']);
        $this->assertSame(1, (int) $pratica['camere']);
        $this->assertNotEmpty($pratica['volo_selezionato_il']);
        $this->assertNotEmpty($pratica['hotel_selezionato_il']);
    }

    public function testPraticaInesistenteRestituisceNull(): void
    {
        $this->assertNull($this->archivio->pratica('chat_inesistente'));
    }
}

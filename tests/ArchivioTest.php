<?php

declare(strict_types=1);

namespace App\Tests;

use App\Support\Archivio;
use PHPUnit\Framework\TestCase;

class ArchivioTest extends TestCase
{
    private string $percorso;

    protected function setUp(): void
    {
        $this->percorso = sys_get_temp_dir() . '/archivio_test_' . uniqid() . '/utenti.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->percorso)) {
            unlink($this->percorso);
            rmdir(dirname($this->percorso));
        }
    }

    public function testSalvaELegge(): void
    {
        $archivio = new Archivio($this->percorso);

        $this->assertSame([], $archivio->tutti());

        $archivio->salva(['nome' => 'Mario', 'cognome' => 'Rossi', 'email' => 'mario.rossi@example.com']);
        $archivio->salva(['nome' => 'Luigi', 'cognome' => 'Verdi', 'email' => 'luigi.verdi@example.com']);

        $dati = $archivio->tutti();

        $this->assertCount(2, $dati);
        $this->assertSame('Mario', $dati[0]['nome']);
        $this->assertSame('Rossi', $dati[0]['cognome']);
        $this->assertSame('mario.rossi@example.com', $dati[0]['email']);
        $this->assertArrayHasKey('raccolto_il', $dati[0]);
        $this->assertSame('Luigi', $dati[1]['nome']);
    }

    public function testSalvaRecordViaggio(): void
    {
        $archivio = new Archivio($this->percorso);

        $archivio->salva([
            'email' => 'mario.rossi@example.com',
            'destinazione' => 'Roma',
            'numero_persone' => 2,
            'periodo' => 'luglio 2026',
        ]);

        $dati = $archivio->tutti();

        $this->assertCount(1, $dati);
        $this->assertSame('mario.rossi@example.com', $dati[0]['email']);
        $this->assertSame('Roma', $dati[0]['destinazione']);
        $this->assertSame(2, $dati[0]['numero_persone']);
        $this->assertSame('luglio 2026', $dati[0]['periodo']);
        $this->assertArrayHasKey('raccolto_il', $dati[0]);
    }
}

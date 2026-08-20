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

        $archivio->salva('Mario', 'Rossi');
        $archivio->salva('Luigi', 'Verdi');

        $dati = $archivio->tutti();

        $this->assertCount(2, $dati);
        $this->assertSame('Mario', $dati[0]['nome']);
        $this->assertSame('Rossi', $dati[0]['cognome']);
        $this->assertArrayHasKey('raccolto_il', $dati[0]);
        $this->assertSame('Luigi', $dati[1]['nome']);
    }
}

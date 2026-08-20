<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Persistenza minimale dei dati raccolti su file JSON.
 */
class Archivio
{
    public function __construct(
        private readonly string $percorso
    ) {
    }

    /**
     * @return array<int, array<string, string|int>>
     */
    public function tutti(): array
    {
        if (!is_file($this->percorso)) {
            return [];
        }

        $dati = json_decode((string) file_get_contents($this->percorso), true);

        return is_array($dati) ? $dati : [];
    }

    /**
     * @param array<string, string|int> $record
     */
    public function salva(array $record): void
    {
        $directory = dirname($this->percorso);
        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new RuntimeException("Impossibile creare la directory {$directory}");
        }

        $dati = $this->tutti();
        $dati[] = $record + ['raccolto_il' => date(DATE_ATOM)];

        if (file_put_contents(
            $this->percorso,
            json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        ) === false) {
            throw new RuntimeException("Impossibile scrivere il file {$this->percorso}");
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Persistenza della pratica di viaggio: UN file JSON per utente, con nome
 * basato su timestamp (pratica_YYYYmmdd_His.json), che raccoglie anagrafica,
 * viaggio e selezioni (volo, hotel) in un unico documento.
 *
 * Il file viene creato subito dopo la raccolta dei dati anagrafici e aggiornato
 * a ogni selezione successiva: se l'utente esce a metà conversazione i dati
 * già raccolti non vanno persi. I file esistenti non vengono mai eliminati.
 */
class Pratica
{
    /** @var array<string, mixed> */
    private array $dati;

    /**
     * @param array<string, mixed> $dati
     */
    private function __construct(
        private readonly string $percorso,
        array $dati,
    ) {
        $this->dati = $dati;
    }

    /**
     * Crea una nuova pratica nella directory indicata e la scrive subito su disco.
     *
     * @param array<string, mixed> $dati Contenuto iniziale (es. utente e viaggio).
     */
    public static function crea(string $directory, array $dati): self
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new RuntimeException("Impossibile creare la directory {$directory}");
        }

        $pratica = new self(
            $directory . '/pratica_' . date('Ymd_His') . '.json',
            $dati + ['raccolto_il' => date(DATE_ATOM)],
        );
        $pratica->scrivi();

        return $pratica;
    }

    /**
     * Riapre una pratica esistente dal suo file JSON (es. dopo un resume del workflow).
     */
    public static function apri(string $percorso): self
    {
        if (!is_file($percorso)) {
            throw new RuntimeException("Pratica non trovata: {$percorso}");
        }

        $dati = json_decode((string) file_get_contents($percorso), true);
        if (!is_array($dati)) {
            throw new RuntimeException("Pratica non leggibile: {$percorso}");
        }

        return new self($percorso, $dati);
    }

    /**
     * Aggiunge o aggiorna sezioni della pratica (es. volo_selezionato,
     * hotel_selezionato) e riscrive il file.
     *
     * @param array<string, mixed> $sezioni
     */
    public function aggiorna(array $sezioni): void
    {
        $this->dati = $sezioni + $this->dati;
        $this->scrivi();
    }

    public function percorso(): string
    {
        return $this->percorso;
    }

    /**
     * @return array<string, mixed>
     */
    public function dati(): array
    {
        return $this->dati;
    }

    private function scrivi(): void
    {
        if (file_put_contents(
            $this->percorso,
            json_encode($this->dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        ) === false) {
            throw new RuntimeException("Impossibile scrivere il file {$this->percorso}");
        }
    }
}

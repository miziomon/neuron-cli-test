<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use RuntimeException;

/**
 * Archivio SQLite dell'applicazione (`data/neuron.sqlite`): due tabelle.
 *
 * - `chat`: ogni messaggio visibile all'utente (in entrata e in uscita) con
 *   l'identificativo della conversazione (il workflowId), il ruolo
 *   ('utente' | 'agente') e il dettaglio dei token del turno (solo agente).
 * - `pratiche`: il dettaglio dei dati raccolti per conversazione, incluse le
 *   selezioni con il codice univoco del volo e il codice dell'hotel.
 */
class ArchivioSqlite
{
    private PDO $pdo;

    public function __construct(string $percorso)
    {
        $directory = dirname($percorso);
        if (!is_dir($directory) && !mkdir($directory, 0777, true)) {
            throw new RuntimeException("Impossibile creare la directory {$directory}");
        }

        $this->pdo = new PDO('sqlite:' . $percorso);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->creaSchema();
    }

    /**
     * Registra un messaggio della conversazione.
     *
     * @param array{in: int, out: int, tot: int}|null $usage Token del turno (solo messaggi dell'agente).
     */
    public function registraMessaggio(string $chatId, string $ruolo, string $messaggio, ?array $usage = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO chat (chat_id, ruolo, messaggio, token_input, token_output, token_totali, creato_il)
             VALUES (:chat_id, :ruolo, :messaggio, :token_input, :token_output, :token_totali, :creato_il)'
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'ruolo' => $ruolo,
            'messaggio' => $messaggio,
            'token_input' => $usage['in'] ?? null,
            'token_output' => $usage['out'] ?? null,
            'token_totali' => $usage['tot'] ?? null,
            'creato_il' => date(DATE_ATOM),
        ]);
    }

    /**
     * Crea la riga della pratica con anagrafica (opzionale) e dati del viaggio.
     *
     * @param array{nome: ?string, cognome: ?string, email: ?string}|null $utente
     * @param array<string, mixed> $viaggio
     */
    public function creaPratica(string $chatId, ?array $utente, array $viaggio): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pratiche (chat_id, nome, cognome, email, destinazione, aeroporto_partenza,
                aeroporto_destinazione, data_partenza, data_ritorno, adulti, bambini, creato_il, aggiornato_il)
             VALUES (:chat_id, :nome, :cognome, :email, :destinazione, :aeroporto_partenza,
                :aeroporto_destinazione, :data_partenza, :data_ritorno, :adulti, :bambini, :creato_il, :aggiornato_il)'
        );
        $stmt->execute([
            'chat_id' => $chatId,
            'nome' => $utente['nome'] ?? null,
            'cognome' => $utente['cognome'] ?? null,
            'email' => $utente['email'] ?? null,
            'destinazione' => $viaggio['destinazione'],
            'aeroporto_partenza' => $viaggio['aeroporto_partenza'],
            'aeroporto_destinazione' => $viaggio['aeroporto_destinazione'],
            'data_partenza' => $viaggio['data_partenza'],
            'data_ritorno' => $viaggio['data_ritorno'],
            'adulti' => $viaggio['adulti'],
            'bambini' => $viaggio['bambini'],
            'creato_il' => date(DATE_ATOM),
            'aggiornato_il' => date(DATE_ATOM),
        ]);
    }

    /**
     * Registra la selezione del volo (descrizione + codice univoco).
     */
    public function aggiornaVolo(string $chatId, string $descrizione, ?string $codice): void
    {
        $this->aggiornaPratica($chatId, [
            'volo_descrizione' => $descrizione,
            'codice_volo' => $codice,
            'volo_selezionato_il' => date(DATE_ATOM),
        ]);
    }

    /**
     * Registra la selezione dell'hotel (descrizione + codice + date e camere).
     *
     * @param array{descrizione: string, codice: ?string, check_in: ?string, check_out: ?string, camere: int} $hotel
     */
    public function aggiornaHotel(string $chatId, array $hotel): void
    {
        $this->aggiornaPratica($chatId, [
            'hotel_descrizione' => $hotel['descrizione'],
            'codice_hotel' => $hotel['codice'],
            'check_in' => $hotel['check_in'],
            'check_out' => $hotel['check_out'],
            'camere' => $hotel['camere'],
            'hotel_selezionato_il' => date(DATE_ATOM),
        ]);
    }

    /**
     * I messaggi di una conversazione, in ordine cronologico.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messaggi(string $chatId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM chat WHERE chat_id = :chat_id ORDER BY id');
        $stmt->execute(['chat_id' => $chatId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * La pratica di una conversazione, oppure null se non esiste.
     *
     * @return array<string, mixed>|null
     */
    public function pratica(string $chatId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pratiche WHERE chat_id = :chat_id');
        $stmt->execute(['chat_id' => $chatId]);
        $riga = $stmt->fetch(PDO::FETCH_ASSOC);

        return $riga === false ? null : $riga;
    }

    /**
     * @param array<string, mixed> $campi
     */
    private function aggiornaPratica(string $chatId, array $campi): void
    {
        $campi['aggiornato_il'] = date(DATE_ATOM);
        $assegnazioni = implode(', ', array_map(
            static fn(string $colonna): string => "{$colonna} = :{$colonna}",
            array_keys($campi),
        ));

        $stmt = $this->pdo->prepare("UPDATE pratiche SET {$assegnazioni} WHERE chat_id = :chat_id");
        $stmt->execute($campi + ['chat_id' => $chatId]);
    }

    private function creaSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id TEXT NOT NULL,
                ruolo TEXT NOT NULL CHECK (ruolo IN (\'utente\', \'agente\')),
                messaggio TEXT NOT NULL,
                token_input INTEGER,
                token_output INTEGER,
                token_totali INTEGER,
                creato_il TEXT NOT NULL
            )'
        );
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_chat_id ON chat (chat_id)');

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS pratiche (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id TEXT NOT NULL UNIQUE,
                nome TEXT,
                cognome TEXT,
                email TEXT,
                destinazione TEXT NOT NULL,
                aeroporto_partenza TEXT NOT NULL,
                aeroporto_destinazione TEXT NOT NULL,
                data_partenza TEXT NOT NULL,
                data_ritorno TEXT,
                adulti INTEGER NOT NULL,
                bambini INTEGER NOT NULL,
                volo_descrizione TEXT,
                codice_volo TEXT,
                volo_selezionato_il TEXT,
                hotel_descrizione TEXT,
                codice_hotel TEXT,
                check_in TEXT,
                check_out TEXT,
                camere INTEGER,
                hotel_selezionato_il TEXT,
                creato_il TEXT NOT NULL,
                aggiornato_il TEXT NOT NULL
            )'
        );
    }
}

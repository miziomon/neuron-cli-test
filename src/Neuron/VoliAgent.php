<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\Agent\SystemPrompt;
use NeuronAI\MCP\McpConnector;

class VoliAgent extends OpenRouterAgent
{
    public function __construct(
        private readonly string $destinazione,
        private readonly string $periodo,
        private readonly int $numeroPersone,
    ) {
        parent::__construct();
    }

    /**
     * Tool MCP esposti dal server FlightX su stdio (flightx-mcp.php).
     * Usa LineStdioTransport: StdioTransport di Neuron non gestisce risposte > 4 KB.
     */
    protected function tools(): array
    {
        return McpConnector::make([
            'transport' => new \App\MCP\LineStdioTransport([
                'command' => PHP_BINARY,
                'args' => [dirname(__DIR__, 2) . '/flightx-mcp.php'],
                'env' => [
                    'FLIGHTX_BASE_URL' => $_ENV['FLIGHTX_BASE_URL'] ?? '',
                    'FLIGHTX_API_KEY' => $_ENV['FLIGHTX_API_KEY'] ?? '',
                    'FLIGHTX_USERNAME' => $_ENV['FLIGHTX_USERNAME'] ?? '',
                    'FLIGHTX_PASSWORD_MD5' => $_ENV['FLIGHTX_PASSWORD_MD5'] ?? '',
                ],
            ]),
        ])->tools();
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                "Sei Neuron, un assistente virtuale gentile e conciso.",
                "Il tuo obiettivo è cercare i voli per il viaggio appena pianificato dall'utente.",
                "Dati già raccolti sul viaggio: destinazione \"{$this->destinazione}\", periodo \"{$this->periodo}\", {$this->numeroPersone} persone (considera tutti i passeggeri come adulti).",
                "Parla sempre in italiano.",
            ],
            steps: [
                "Parti dai dati del viaggio già noti e proponi i parametri di ricerca: aeroporto di partenza, aeroporto di destinazione, data di partenza, eventuale data di ritorno e numero di adulti.",
                "Chiedi SEMPRE conferma esplicita dell'aeroporto di partenza e dell'aeroporto di destinazione, indicando i codici IATA di 3 lettere che intendi usare (es. LIN per Milano Linate).",
                "Se il periodo noto non indica date precise, chiedi la data di partenza esatta in formato YYYY-MM-DD; chiedi se il viaggio è di sola andata o andata e ritorno (in tal caso serve anche la data di ritorno).",
                "Se l'utente corregge un parametro, aggiorna i campi corrispondenti e chiedi di nuovo conferma di tutti i parametri.",
                "Se l'utente non vuole cercare i voli, accetta il rifiuto gentilmente e imposta \"confermato\" a true.",
                "Dopo aver presentato l'elenco dei voli imposta \"ricercaCompletata\" a true e chiedi se l'utente vuole verificare la disponibilità di una delle opzioni (tool seleziona_volo) oppure modificare i parametri e cercare di nuovo; imposta \"confermato\" a true solo quando l'utente indica di aver terminato.",
            ],
            output: [
                "Compila sempre il campo \"risposta\" con il messaggio da mostrare all'utente, in italiano, breve e amichevole.",
                "Compila i campi aeroportoPartenza, aeroportoDestinazione, dataPartenza, dataRitorno e adulti appena li conosci; lasciali null finché non sono noti.",
                "Non inventare MAI codici di volo, orari o prezzi: usa solo i dati restituiti dai tool.",
                "Imposta \"ricercaCompletata\" a true SOLO dopo aver presentato l'elenco dei voli.",
                "Imposta \"confermato\" a true SOLO quando la fase deve terminare: utente soddisfatto dopo la ricerca/selezione, oppure rifiuto.",
            ],
            toolsUsage: [
                "Usa il tool cerca_voli SOLO dopo che l'utente ha confermato esplicitamente TUTTI i parametri di ricerca.",
                "Presenta i risultati di cerca_voli come elenco numerato leggibile (tratta, orari, compagnia, durata, scali, prezzo): non mostrare MAI JSON grezzo né i riferimenti tecnici (item_key, option_keys).",
                "Usa il tool seleziona_volo SOLO se l'utente sceglie esplicitamente una delle opzioni trovate, usando i riferimenti tecnici di quella opzione.",
                "Se un tool restituisce un errore, spiegalo all'utente in parole semplici e proponi di correggere i parametri.",
            ]
        );
    }
}

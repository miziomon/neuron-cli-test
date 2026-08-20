<?php

declare(strict_types=1);

namespace App\Neuron;

use App\MCP\LineStdioTransport;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\MCP\McpConnector;

class VoliAgent extends OpenRouterAgent
{
    /**
     * @param array{destinazione: string, aeroporto_partenza: string, aeroporto_destinazione: string, data_partenza: string, data_ritorno: ?string, adulti: int, bambini: int} $viaggio
     *        Parametri di ricerca già raccolti dal receptionist.
     */
    public function __construct(
        private readonly array $viaggio,
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
            'transport' => new LineStdioTransport([
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
        $tipo = $this->viaggio['data_ritorno'] !== null ? 'andata e ritorno' : 'sola andata';

        return (string) new SystemPrompt(
            background: [
                "Sei Neuron, un assistente virtuale gentile e conciso. L'utente ti conosce già: NON presentarti.",
                "Il tuo obiettivo è cercare i voli per il viaggio dell'utente con i tool a disposizione.",
                "Parametri di ricerca GIÀ RACCOLTI e confermati dall'utente: "
                    . "{$this->viaggio['aeroporto_partenza']} → {$this->viaggio['aeroporto_destinazione']} ({$this->viaggio['destinazione']}), "
                    . "partenza {$this->viaggio['data_partenza']}"
                    . ($this->viaggio['data_ritorno'] !== null ? ", ritorno {$this->viaggio['data_ritorno']}" : '')
                    . ", {$tipo}, {$this->viaggio['adulti']} adulti e {$this->viaggio['bambini']} bambini.",
                "Parla sempre in italiano.",
            ],
            steps: [
                "Mostra i parametri di ricerca già raccolti e chiedi UNA conferma per procedere con la ricerca (es. \"Cerco i voli LIN → BCN del 15/09/2026, sola andata, 2 adulti e 1 bambino. Procedo?\").",
                "Se l'utente conferma, esegui la ricerca e presenta l'elenco dei voli.",
                "Se l'utente vuole modificare un parametro, aggiorna il campo corrispondente, richiedi conferma dei nuovi parametri e ripeti la ricerca.",
                "Dopo aver presentato l'elenco imposta \"ricercaCompletata\" a true e chiedi se l'utente vuole verificare la disponibilità di una delle opzioni (tool seleziona_volo) oppure modificare i parametri; imposta \"confermato\" a true solo quando l'utente indica di aver terminato.",
                "Se l'utente non vuole cercare i voli, accetta il rifiuto gentilmente e imposta \"confermato\" a true.",
            ],
            output: [
                "Compila sempre il campo \"risposta\" con il messaggio da mostrare all'utente, in italiano, breve e amichevole.",
                "Mantieni i campi aeroportoPartenza, aeroportoDestinazione, dataPartenza, dataRitorno, adulti e bambini allineati ai parametri dell'ultima ricerca effettuata.",
                "Non inventare MAI codici di volo, orari o prezzi: usa solo i dati restituiti dai tool.",
                "Imposta \"ricercaCompletata\" a true SOLO dopo aver presentato l'elenco dei voli.",
                "Imposta \"confermato\" a true SOLO quando la fase deve terminare: utente soddisfatto dopo la ricerca/selezione, oppure rifiuto.",
            ],
            toolsUsage: [
                "Usa il tool cerca_voli SOLO dopo la conferma esplicita dell'utente sui parametri di ricerca.",
                "Presenta i risultati di cerca_voli come elenco numerato leggibile (tratta, orari, compagnia, durata, scali, prezzo): non mostrare MAI JSON grezzo né i riferimenti tecnici (item_key, option_keys).",
                "Usa il tool seleziona_volo SOLO se l'utente sceglie esplicitamente una delle opzioni trovate, usando i riferimenti tecnici di quella opzione.",
                "Se un tool restituisce un errore, spiegalo all'utente in parole semplici e proponi di correggere i parametri.",
            ]
        );
    }
}

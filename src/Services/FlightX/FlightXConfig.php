<?php

namespace App\Services\FlightX;

use App\Services\FlightX\Exceptions\FlightXConfigurationException;

/**
 * Configurazione immutabile richiesta per creare un {@see FlightXClient}.
 *
 * Le API FlightX (nome in codice presso il fornitore: "Ugotto") richiedono
 * quattro credenziali: l'URL base dell'ambiente, una ApiKey statica (senza
 * scadenza, inviata su ogni richiesta) e uno username/password usati per
 * ottenere un token JWT valido 8 ore (vedi {@see FlightXClient::login()}).
 */
final readonly class FlightXConfig
{
    public string $baseUrl;

    /**
     * @param  string  $baseUrl  URL base dell'ambiente FlightX (es. "https://api.stage.flightx.app").
     *                           Lo slash finale, se presente, viene rimosso.
     * @param  string  $apiKey  Chiave API statica, da inviare nell'header "ApiKey" su ogni richiesta.
     * @param  string  $username  Username usato per il login (POST api/token/logIn).
     * @param  string  $password  Password in chiaro: viene hashata con md5(strtolower($password))
     *                            solo al momento del login, mai loggata né serializzata altrove.
     *                            Può essere vuota se è valorizzata $passwordMd5.
     * @param  string|null  $passwordMd5  Password già hashata con md5(strtolower(...)): se presente
     *                                    ha la precedenza e viene inviata così com'è al login.
     * @param  int  $timeout  Timeout HTTP in secondi per ogni richiesta.
     * @param  int  $retries  Numero di tentativi aggiuntivi sugli errori transitori (connessione, 429, 5xx).
     * @param  int  $retryDelayMs  Attesa in millisecondi fra un tentativo e il successivo.
     * @param  bool  $logRequests  Se true, registra le richieste su STDERR (con credenziali oscurate).
     *
     * @throws FlightXConfigurationException se un campo obbligatorio è vuoto o baseUrl non è un URL valido.
     */
    public function __construct(
        string $baseUrl,
        public string $apiKey,
        public string $username,
        public string $password = '',
        public ?string $passwordMd5 = null,
        public int $timeout = 30,
        public int $retries = 2,
        public int $retryDelayMs = 250,
        public bool $logRequests = false,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new FlightXConfigurationException("FlightX: 'baseUrl' mancante o non valido.");
        }

        foreach (['apiKey' => $apiKey, 'username' => $username] as $field => $value) {
            if (trim($value) === '') {
                throw new FlightXConfigurationException("FlightX: '{$field}' è obbligatorio e non può essere vuoto.");
            }
        }

        if (trim($password) === '' && ($passwordMd5 === null || trim($passwordMd5) === '')) {
            throw new FlightXConfigurationException("FlightX: è obbligatoria 'password' in chiaro oppure 'passwordMd5'.");
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * Costruisce la configurazione da un array associativo, utile quando i valori
     * arrivano da una fonte esterna (form, richiesta HTTP, file di configurazione letto a mano).
     *
     * @param  array<string, mixed>  $config
     *
     * @throws FlightXConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $passwordMd5 = $config['password_md5'] ?? $config['passwordMd5'] ?? null;

        return new self(
            baseUrl: (string) ($config['base_url'] ?? $config['baseUrl'] ?? ''),
            apiKey: (string) ($config['api_key'] ?? $config['apiKey'] ?? ''),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            passwordMd5: $passwordMd5 !== null ? (string) $passwordMd5 : null,
            timeout: (int) ($config['timeout'] ?? 30),
            retries: (int) ($config['retries'] ?? 2),
            retryDelayMs: (int) ($config['retry_delay_ms'] ?? $config['retryDelayMs'] ?? 250),
            logRequests: (bool) ($config['log_requests'] ?? $config['logRequests'] ?? false),
        );
    }
}

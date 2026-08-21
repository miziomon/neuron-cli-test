<?php

namespace App\Services\Geocoding;

use App\Services\Geocoding\Exceptions\GeocodingConfigurationException;

/**
 * Configurazione immutabile richiesta per creare un {@see NominatimClient}.
 *
 * Nominatim non richiede alcuna credenziale, ma la sua policy d'uso impone uno
 * `userAgent` identificativo su ogni richiesta: è trattato qui come un campo
 * obbligatorio esattamente come lo sarebbe una API key.
 */
final readonly class NominatimConfig
{
    public string $baseUrl;

    public int $minIntervalMs;

    /**
     * @param  string  $baseUrl  URL base del servizio (es. "https://nominatim.openstreetmap.org").
     *                           Lo slash finale, se presente, viene rimosso.
     * @param  string  $userAgent  Identificativo obbligatorio richiesto dalla policy d'uso di
     *                             Nominatim (es. "nome-progetto/1.0"). Un User-Agent generico o
     *                             assente porta al blocco del client (HTTP 403).
     * @param  string|null  $email  Indirizzo di contatto opzionale ma consigliato dalla policy
     *                              d'uso: incluso nelle richieste come parametro `email`.
     * @param  string|null  $acceptLanguage  Lingua preferita per i risultati (header Accept-Language,
     *                                       es. "it"). Null per lasciare decidere al servizio.
     * @param  int  $timeout  Timeout HTTP in secondi per ogni richiesta.
     * @param  int  $retries  Numero di tentativi aggiuntivi sugli errori transitori (connessione, 429, 5xx).
     * @param  int  $retryDelayMs  Attesa in millisecondi fra un tentativo e il successivo.
     * @param  int  $minIntervalMs  Intervallo minimo in millisecondi fra due richieste di rete
     *                             consecutive (throttle). Default 1000 (1 richiesta/secondo),
     *                             come richiesto dalla policy d'uso. Non azzerare fuori dai test.
     * @param  bool  $logRequests  Se true, registra le richieste su STDERR (con dati di contatto oscurati).
     *
     * @throws GeocodingConfigurationException se baseUrl non è un URL valido o userAgent è vuoto.
     */
    public function __construct(
        string $baseUrl,
        public string $userAgent,
        public ?string $email = null,
        public ?string $acceptLanguage = null,
        public int $timeout = 15,
        public int $retries = 2,
        public int $retryDelayMs = 500,
        int $minIntervalMs = 1000,
        public bool $logRequests = false,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new GeocodingConfigurationException("Geocoding: 'baseUrl' mancante o non valido.");
        }

        if (trim($userAgent) === '') {
            throw new GeocodingConfigurationException("Geocoding: 'userAgent' è obbligatorio (policy d'uso di Nominatim) e non può essere vuoto.");
        }

        $this->baseUrl = $baseUrl;
        $this->minIntervalMs = max(0, $minIntervalMs);
    }

    /**
     * Costruisce la configurazione da un array associativo, utile quando i valori
     * arrivano da una fonte esterna (form, richiesta HTTP, file di configurazione letto a mano).
     *
     * @param  array<string, mixed>  $config
     *
     * @throws GeocodingConfigurationException
     */
    public static function fromArray(array $config): self
    {
        return new self(
            baseUrl: (string) ($config['base_url'] ?? $config['baseUrl'] ?? ''),
            userAgent: (string) ($config['user_agent'] ?? $config['userAgent'] ?? ''),
            email: isset($config['email']) && $config['email'] !== '' ? (string) $config['email'] : null,
            acceptLanguage: isset($config['accept_language']) || isset($config['acceptLanguage'])
                ? (string) ($config['accept_language'] ?? $config['acceptLanguage'])
                : null,
            timeout: (int) ($config['timeout'] ?? 15),
            retries: (int) ($config['retries'] ?? 2),
            retryDelayMs: (int) ($config['retry_delay_ms'] ?? $config['retryDelayMs'] ?? 500),
            minIntervalMs: (int) ($config['min_interval_ms'] ?? $config['minIntervalMs'] ?? 1000),
            logRequests: (bool) ($config['log_requests'] ?? $config['logRequests'] ?? false),
        );
    }
}

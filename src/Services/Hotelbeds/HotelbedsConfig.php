<?php

namespace App\Services\Hotelbeds;

use App\Services\Hotelbeds\Exceptions\HotelbedsConfigurationException;

/**
 * Configurazione immutabile richiesta per creare un {@see HotelbedsClient}.
 *
 * Le API Hotelbeds (HBX Group) richiedono due credenziali statiche, senza
 * scadenza: una `apiKey` e un `secret`, usate per firmare ogni singola
 * richiesta (vedi {@see HotelbedsClient::signatureHeaders()}). A differenza di
 * FlightX non esiste un login né un token: non ci sono `username`/`password`.
 */
final readonly class HotelbedsConfig
{
    public string $baseUrl;

    /**
     * @param  string  $baseUrl  URL base dell'ambiente Hotelbeds, comprensivo di versione
     *                           (es. "https://api.test.hotelbeds.com/hotel-api/1.0"). Lo
     *                           slash finale, se presente, viene rimosso.
     * @param  string  $apiKey  Chiave API statica, da inviare nell'header "Api-key" su ogni richiesta.
     * @param  string  $secret  Segreto statico usato per calcolare la firma "X-Signature"
     *                          (sha256(apiKey + secret + timestamp)), mai inviato direttamente.
     * @param  int  $timeout  Timeout HTTP in secondi per ogni richiesta.
     * @param  int  $retries  Numero di tentativi aggiuntivi sugli errori transitori (connessione, 429, 5xx).
     *                        Un 401 (firma non accettata) non viene mai ritentato: vedi {@see HotelbedsClient}.
     * @param  int  $retryDelayMs  Attesa in millisecondi fra un tentativo e il successivo.
     * @param  bool  $logRequests  Se true, registra le richieste su STDERR (con credenziali oscurate).
     *
     * @throws HotelbedsConfigurationException se un campo obbligatorio è vuoto o baseUrl non è un URL valido.
     */
    public function __construct(
        string $baseUrl,
        public string $apiKey,
        public string $secret,
        public int $timeout = 30,
        public int $retries = 2,
        public int $retryDelayMs = 250,
        public bool $logRequests = false,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');

        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new HotelbedsConfigurationException("Hotelbeds: 'baseUrl' mancante o non valido.");
        }

        foreach (['apiKey' => $apiKey, 'secret' => $secret] as $field => $value) {
            if (trim($value) === '') {
                throw new HotelbedsConfigurationException("Hotelbeds: '{$field}' è obbligatorio e non può essere vuoto.");
            }
        }

        $this->baseUrl = $baseUrl;
    }

    /**
     * Costruisce la configurazione da un array associativo, utile quando i valori
     * arrivano da una fonte esterna (form, richiesta HTTP, file di configurazione letto a mano).
     *
     * @param  array<string, mixed>  $config
     *
     * @throws HotelbedsConfigurationException
     */
    public static function fromArray(array $config): self
    {
        return new self(
            baseUrl: (string) ($config['base_url'] ?? $config['baseUrl'] ?? ''),
            apiKey: (string) ($config['api_key'] ?? $config['apiKey'] ?? ''),
            secret: (string) ($config['secret'] ?? ''),
            timeout: (int) ($config['timeout'] ?? 30),
            retries: (int) ($config['retries'] ?? 2),
            retryDelayMs: (int) ($config['retry_delay_ms'] ?? $config['retryDelayMs'] ?? 250),
            logRequests: (bool) ($config['log_requests'] ?? $config['logRequests'] ?? false),
        );
    }
}

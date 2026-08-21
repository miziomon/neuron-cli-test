<?php

namespace App\Services\Geocoding;

use App\Services\Geocoding\Exceptions\GeocodingApiException;
use App\Services\Geocoding\Exceptions\GeocodingBlockedException;
use App\Services\Geocoding\Exceptions\GeocodingConnectionException;
use App\Services\Geocoding\Exceptions\GeocodingException;
use App\Services\Geocoding\Exceptions\GeocodingRateLimitException;
use App\Services\Geocoding\Exceptions\GeocodingServerException;
use App\Services\Geocoding\Exceptions\GeocodingValidationException;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;

/**
 * Wrapper server-side per l'API di geocoding di Nominatim (OpenStreetMap).
 *
 * Serve a convertire un nome di città o un indirizzo in coordinate
 * (latitudine/longitudine), tipicamente per alimentare una ricerca per
 * geolocalizzazione come {@see \App\Services\Hotelbeds\HotelbedsClient::searchByGeolocation()}.
 * I due client non si conoscono: comporre `geocode()` con `searchByGeolocation()`
 * è responsabilità di chi chiama entrambi (un controller, un tool MCP), non di
 * questa classe.
 *
 * Flusso tipico d'uso:
 *   1. {@see self::geocode()} (ricerca libera) o {@see self::geocodeAddress()}
 *      (parametri strutturati, più precisi) — restituiscono il primo risultato
 *      normalizzato con coordinate come float, o null se nessun risultato.
 *   2. {@see self::search()} / {@see self::searchStructured()} se serve l'intera
 *      lista dei risultati invece del solo primo.
 *
 * IMPORTANTE — policy d'uso di Nominatim, applicata automaticamente da questa
 * classe: massimo 1 richiesta al secondo (vedi {@see self::throttle()}). Non
 * azzerare `minIntervalMs` fuori dai test: la policy prevede il blocco (HTTP 403)
 * dei client che non la rispettano. `userAgent` è obbligatorio per lo stesso motivo.
 *
 * Stato interno: questa classe non conserva credenziali (Nominatim non ne
 * richiede), ma il throttle è condiviso a livello di PROCESSO PHP tramite una
 * proprietà statica ({@see self::$lastRequestAt}), non di singola istanza:
 * anche istanze diverse create nello stesso processo si mettono in coda a
 * vicenda, come richiede la policy "1 richiesta al secondo per client".
 *
 * Non documentato dal servizio in modo esplicito, dedotto dal contratto reale
 * dell'API: `GET /search` risponde sempre con una LISTA JSON (mai un oggetto),
 * vuota quando non ci sono risultati — non è un errore, va trattata come tale.
 */
final class NominatimClient
{
    /**
     * Timestamp (secondi, con frazione) dell'ultima richiesta di rete
     * effettivamente inviata da un'istanza qualsiasi di questo processo.
     * Condiviso fra istanze per rispettare il limite "1 richiesta/secondo"
     * della policy Nominatim indipendentemente da quante istanze del client
     * esistono. Vedi {@see self::resetThrottle()} per l'uso nei test.
     */
    private static ?float $lastRequestAt = null;

    private Client $http;

    /**
     * @param  Closure|null  $sleeper  Funzione `fn(int $microsecondi): void` invocata dal
     *                                 throttle per attendere. Default `usleep`. Iniettabile
     *                                 nei test per verificare il throttle senza dormire davvero.
     */
    public function __construct(
        private readonly NominatimConfig $config,
        private readonly ?Closure $sleeper = null,
    ) {
        $this->http = new Client([
            'base_uri' => $this->config->baseUrl . '/',
            'timeout' => $this->config->timeout,
            // Gli status HTTP vengono tradotti nelle eccezioni del wrapper da decodeOrThrow().
            'http_errors' => false,
        ]);
    }

    /**
     * Azzera il timestamp del throttle condiviso di processo. Da chiamare in
     * `setUp()` nei test, altrimenti l'ordine di esecuzione dei test
     * influenzerebbe se il throttle attende o meno.
     */
    public static function resetThrottle(): void
    {
        self::$lastRequestAt = null;
    }

    // -------------------------------------------------------------------------
    // Ricerca
    // -------------------------------------------------------------------------

    /**
     * Ricerca libera per nome (città, indirizzo, punto di interesse).
     *
     * @param  string  $query  Testo da cercare, non vuoto (es. "Grugliasco, Italia").
     * @param  int  $limit  Numero massimo di risultati, fra 1 e 50.
     * @param  string|null  $countryCodes  Filtro opzionale su uno o più codici paese ISO 3166-1
     *                                    alpha-2 separati da virgola (es. "it" o "it,fr").
     * @return array<int, array<string, mixed>>  Lista grezza dei risultati Nominatim, array vuoto
     *                                            se non è stato trovato nulla (non è un errore).
     *
     * @throws GeocodingValidationException se $query è vuota o $limit fuori range.
     * @throws GeocodingException per errori di rete, blocco, rate limit, server o applicativi.
     */
    public function search(string $query, int $limit = 1, ?string $countryCodes = null): array
    {
        $query = $this->assertQuery($query, 'query');
        $this->assertLimit($limit);
        $countryCodes = $this->assertCountryCodes($countryCodes);

        $params = array_filter([
            'q' => $query,
            'format' => 'json',
            'limit' => $limit,
            'countrycodes' => $countryCodes,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->jsonList('search', $params);
    }

    /**
     * Ricerca con parametri strutturati: più precisa della ricerca libera
     * quando si conoscono già i singoli componenti dell'indirizzo. Almeno un
     * parametro deve essere valorizzato.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GeocodingValidationException se nessun parametro è valorizzato o $limit fuori range.
     * @throws GeocodingException per errori di rete, blocco, rate limit, server o applicativi.
     */
    public function searchStructured(
        ?string $street = null,
        ?string $city = null,
        ?string $county = null,
        ?string $state = null,
        ?string $postalcode = null,
        ?string $country = null,
        int $limit = 1,
    ): array {
        $params = array_filter(compact('street', 'city', 'county', 'state', 'postalcode', 'country'), static fn (?string $v): bool => $v !== null && trim($v) !== '');

        if ($params === []) {
            throw new GeocodingValidationException('Geocoding: searchStructured() richiede almeno un campo valorizzato fra street, city, county, state, postalcode, country.');
        }

        $this->assertLimit($limit);

        return $this->jsonList('search', array_merge($params, ['format' => 'json', 'limit' => $limit]));
    }

    /**
     * Escape hatch: query string arbitraria su `/search`, forzando comunque
     * `format=json` se non specificato (altrimenti Nominatim risponderebbe XML
     * e {@see self::jsonList()} rifiuterebbe la risposta).
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function searchRaw(array $query): array
    {
        return $this->jsonList('search', array_merge(['format' => 'json'], $query));
    }

    /**
     * Primo risultato di {@see self::search()}, normalizzato per essere passato
     * direttamente a un client di ricerca hotel (coordinate come float, non
     * come stringhe: Nominatim le restituisce sempre come stringhe).
     *
     * @return array{latitude: float, longitude: float, displayName: string, type: string, raw: array<string, mixed>}|null
     *         null se la ricerca non ha prodotto risultati.
     *
     * @throws GeocodingApiException se il primo risultato non ha coordinate valide.
     * @throws GeocodingValidationException|GeocodingException come {@see self::search()}.
     */
    public function geocode(string $query, ?string $countryCodes = null): ?array
    {
        return $this->firstOrNull($this->search($query, 1, $countryCodes));
    }

    /**
     * Primo risultato di {@see self::searchStructured()}, normalizzato come {@see self::geocode()}.
     *
     * @return array{latitude: float, longitude: float, displayName: string, type: string, raw: array<string, mixed>}|null
     *
     * @throws GeocodingApiException se il primo risultato non ha coordinate valide.
     * @throws GeocodingValidationException|GeocodingException come {@see self::searchStructured()}.
     */
    public function geocodeAddress(
        ?string $street = null,
        ?string $city = null,
        ?string $county = null,
        ?string $state = null,
        ?string $postalcode = null,
        ?string $country = null,
    ): ?array {
        return $this->firstOrNull($this->searchStructured($street, $city, $county, $state, $postalcode, $country, 1));
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{latitude: float, longitude: float, displayName: string, type: string, raw: array<string, mixed>}|null
     */
    private function firstOrNull(array $results): ?array
    {
        return $results === [] ? null : $this->normalizeResult($results[0]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{latitude: float, longitude: float, displayName: string, type: string, raw: array<string, mixed>}
     *
     * @throws GeocodingApiException se mancano coordinate numeriche valide.
     */
    private function normalizeResult(array $result): array
    {
        if (! isset($result['lat'], $result['lon']) || ! is_numeric($result['lat']) || ! is_numeric($result['lon'])) {
            throw new GeocodingApiException(
                'Geocoding: il risultato non contiene coordinate valide (lat/lon).',
                context: ['result' => $result],
            );
        }

        return [
            'latitude' => (float) $result['lat'],
            'longitude' => (float) $result['lon'],
            'displayName' => (string) ($result['display_name'] ?? ''),
            'type' => (string) ($result['type'] ?? ''),
            'raw' => $result,
        ];
    }

    // -------------------------------------------------------------------------
    // Validazioni locali pre-rete
    // -------------------------------------------------------------------------

    /**
     * @throws GeocodingValidationException
     */
    private function assertQuery(string $query, string $field): string
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            throw new GeocodingValidationException("Geocoding: '{$field}' non può essere vuota.");
        }

        return $trimmed;
    }

    /**
     * @throws GeocodingValidationException
     */
    private function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 50) {
            throw new GeocodingValidationException("Geocoding: 'limit' deve essere fra 1 e 50, ricevuto: {$limit}.");
        }
    }

    /**
     * @throws GeocodingValidationException
     */
    private function assertCountryCodes(?string $codes): ?string
    {
        if ($codes === null) {
            return null;
        }

        $normalized = strtolower(trim($codes));
        if (! preg_match('/^[a-z]{2}(,[a-z]{2})*$/', $normalized)) {
            throw new GeocodingValidationException("Geocoding: 'countryCodes' deve essere uno o più codici ISO 3166-1 alpha-2 separati da virgola, ricevuto: '{$codes}'.");
        }

        return $normalized;
    }

    // -------------------------------------------------------------------------
    // HTTP core
    // -------------------------------------------------------------------------

    /**
     * Come {@see \App\Services\Hotelbeds\HotelbedsClient::jsonRequest()} ma per
     * un endpoint la cui risposta è una LISTA JSON, non un oggetto: `/search`
     * risponde sempre con un array, anche vuoto quando non ci sono risultati.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    private function jsonList(string $endpoint, array $query): array
    {
        $data = $this->rawRequest($endpoint, $query);

        if (! is_array($data) || ! array_is_list($data)) {
            throw new GeocodingApiException(
                "Geocoding: risposta inattesa per {$endpoint}: atteso un elenco JSON.",
                context: ['raw' => $data],
            );
        }

        /** @var array<int, array<string, mixed>> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws GeocodingException
     */
    private function rawRequest(string $endpoint, array $query): mixed
    {
        $this->throttle();

        return $this->decodeOrThrow($this->send($endpoint, $query), $endpoint);
    }

    /**
     * Attende, se necessario, che sia trascorso `minIntervalMs` dall'ultima
     * richiesta di rete effettivamente inviata da un'istanza qualsiasi di
     * questo processo (vedi {@see self::$lastRequestAt}).
     */
    private function throttle(): void
    {
        if ($this->config->minIntervalMs <= 0) {
            return;
        }

        $now = microtime(true);
        if (self::$lastRequestAt !== null) {
            $elapsedMs = ($now - self::$lastRequestAt) * 1000;
            $remainingMs = $this->config->minIntervalMs - $elapsedMs;
            if ($remainingMs > 0) {
                $sleeper = $this->sleeper ?? usleep(...);
                $sleeper((int) ($remainingMs * 1000));
            }
        }

        self::$lastRequestAt = microtime(true);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws GeocodingConnectionException
     */
    private function send(string $endpoint, array $query): ResponseInterface
    {
        $params = array_filter(array_merge($query, [
            'email' => $this->config->email,
        ]), static fn (mixed $value): bool => $value !== null);

        $headers = array_filter([
            'Accept' => 'application/json',
            'User-Agent' => $this->config->userAgent,
            'Accept-Language' => $this->config->acceptLanguage,
        ], static fn (?string $value): bool => $value !== null);

        $maxAttempts = max(1, $this->config->retries + 1);
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->logRequest($endpoint, $headers, $params, $attempt);

            try {
                $response = $this->http->get(ltrim($endpoint, '/'), [
                    'headers' => $headers,
                    'query' => $params,
                ]);
            } catch (ConnectException $e) {
                $lastConnectionError = $e;
                if ($attempt < $maxAttempts) {
                    usleep($this->config->retryDelayMs * 1000);

                    continue;
                }

                throw new GeocodingConnectionException(
                    "Geocoding: errore di connessione verso {$endpoint}: {$e->getMessage()}",
                    previous: $e,
                );
            }

            $isTransient = $response->getStatusCode() === 429 || $response->getStatusCode() >= 500;
            if ($isTransient && $attempt < $maxAttempts) {
                usleep($this->config->retryDelayMs * 1000);

                continue;
            }

            return $response;
        }

        // Difensivo: il ciclo sopra restituisce o lancia sempre prima di arrivare qui.
        throw new GeocodingConnectionException(
            "Geocoding: impossibile completare la richiesta verso {$endpoint}.",
            previous: $lastConnectionError,
        );
    }

    /**
     * @throws GeocodingException
     */
    private function decodeOrThrow(ResponseInterface $response, string $endpoint): mixed
    {
        $rawBody = (string) $response->getBody();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
            $decoded = ['rawText' => $rawBody];
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $detail = is_array($decoded) ? (string) ($decoded['error'] ?? json_encode($decoded)) : ($rawBody !== '' ? $rawBody : '(corpo risposta vuoto)');
            $message = "Geocoding [{$status}] {$endpoint}: {$detail}";
            $context = is_array($decoded) ? $decoded : null;

            throw match (true) {
                $status === 401, $status === 403 => new GeocodingBlockedException(
                    "{$message}. Verificare che 'User-Agent' sia identificativo e che la policy d'uso (1 richiesta/secondo) sia rispettata.",
                    $context,
                ),
                $status === 429 => new GeocodingRateLimitException($message, $this->retryAfterFromHeader($response), $context),
                $status >= 500 => new GeocodingServerException($message, $context),
                default => new GeocodingValidationException($message, $context),
            };
        }

        return $decoded;
    }

    private function retryAfterFromHeader(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    /**
     * Registra la richiesta su STDERR se `$config->logRequests` è attivo, oscurando
     * l'indirizzo di contatto (`email`) se configurato.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    private function logRequest(string $endpoint, array $headers, array $query, int $attempt): void
    {
        if (! $this->config->logRequests) {
            return;
        }

        fwrite(STDERR, '[Geocoding] ' . json_encode([
            'endpoint' => $endpoint,
            'attempt' => $attempt,
            'headers' => $headers,
            'query' => $this->redact($query),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $data['email'] = '***';
        }

        return $data;
    }
}

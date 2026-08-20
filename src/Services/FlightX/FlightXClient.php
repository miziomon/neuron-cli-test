<?php

namespace App\Services\FlightX;

use App\Services\FlightX\Exceptions\FlightXApiException;
use App\Services\FlightX\Exceptions\FlightXAuthenticationException;
use App\Services\FlightX\Exceptions\FlightXConnectionException;
use App\Services\FlightX\Exceptions\FlightXException;
use App\Services\FlightX\Exceptions\FlightXRateLimitException;
use App\Services\FlightX\Exceptions\FlightXServerException;
use App\Services\FlightX\Exceptions\FlightXValidationException;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;

/**
 * Wrapper server-side per le API FlightX (nome in codice presso il fornitore: "Ugotto").
 *
 * Porta a PHP la stessa logica del POC JavaScript client-side (`UgottoClient` in
 * `experiments/journeo/journeo_ugotto/assets/ugotto-client.js`), eseguendola lato
 * server: le credenziali non sono mai esposte al browser e le chiamate non sono
 * soggette a CORS.
 *
 * Flusso tipico d'uso (perimetro di questa classe: sola lettura e selezione,
 * nessuna prenotazione):
 *   1. {@see self::searchFlights()} — elenco delle soluzioni di volo disponibili.
 *      Login (token JWT) avviene automaticamente alla prima chiamata.
 *   2. {@see self::selectFlightOption()} — verifica disponibilità/prezzo di una
 *      singola opzione scelta dall'elenco e crea un dossier temporaneo (24h).
 *   3. {@see self::dossier()} / {@see self::printDossier()} — dettaglio e voucher
 *      di un dossier esistente.
 *
 * Non sono esposti `bookItem` (prenotazione reale) né `issueTickets` (emissione
 * biglietti, che il fornitore richiede di concordare preventivamente): questo
 * wrapper è pensato per un uso consultivo, ad esempio dietro un tool MCP di sola
 * lettura.
 *
 * IMPORTANTE — stato interno: l'istanza conserva il token JWT ottenuto dal login
 * e il body dell'ultima {@see self::searchFlights()}, perché `selectItem` richiede
 * di riecheggiare integralmente la richiesta di ricerca originale. Per questo la
 * classe è STATEFUL e va istanziata una volta per richiesta/conversazione, non
 * condivisa come singleton fra utenti diversi.
 *
 * Non documentato dal fornitore, dedotto dal contratto reale delle API: ogni
 * risposta usa un envelope comune `{ Success, Errors[], IsValid, ErrorComplete }`.
 * Un errore di dominio (es. tariffa scaduta) arriva con HTTP 200 e `IsValid: false`:
 * questa classe lo traduce sempre in {@see FlightXApiException}, mai in un valore
 * di ritorno silenzioso.
 */
final class FlightXClient
{
    /**
     * Validità del token JWT: 8 ore dichiarate dal fornitore, meno 5 minuti di
     * margine di sicurezza, esattamente come nel POC (`ugotto-client.js` TOKEN_TTL_MS).
     */
    private const TOKEN_TTL_SECONDS = (8 * 60 - 5) * 60;

    private ?string $token = null;

    private ?int $tokenIssuedAt = null;

    /**
     * Body dell'ultima ricerca eseguita, riusato come `CurrentSearchRequest` in
     * {@see self::selectFlightOption()}. Vedi l'avviso di stato nel docblock di classe.
     *
     * @var array<string, mixed>|null
     */
    private ?array $lastSearchRequest = null;

    private Client $http;

    public function __construct(
        private readonly FlightXConfig $config,
    ) {
        $this->http = new Client([
            'base_uri' => $this->config->baseUrl . '/',
            'timeout' => $this->config->timeout,
            // Gli status HTTP vengono tradotti nelle eccezioni del wrapper da decodeOrThrow().
            'http_errors' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Autenticazione
    // -------------------------------------------------------------------------

    /**
     * Esegue il login e memorizza il token JWT ottenuto. Le credenziali usate,
     * se non passate esplicitamente, sono quelle di {@see FlightXConfig}.
     *
     * La password non viene mai inviata in chiaro: l'API richiede
     * md5(strtolower($password)), esattamente come nel POC. Se la configurazione
     * fornisce `passwordMd5` già hashata, quella ha la precedenza e viene
     * inviata senza ulteriori hashing.
     *
     * @throws FlightXAuthenticationException se il login ha successo lato HTTP ma
     *                                        nessun token è presente nella risposta.
     * @throws FlightXException per credenziali errate,
     *                          errori di rete o del server.
     */
    public function login(?string $username = null, ?string $password = null): string
    {
        $passwordMd5 = $password === null
            ? ($this->config->passwordMd5 ?? md5(strtolower($this->config->password)))
            : md5(strtolower($password));

        $body = [
            'UserName' => $username ?? $this->config->username,
            'Password' => $passwordMd5,
        ];

        $data = $this->rawRequest('POST', 'api/token/logIn', $body, useToken: false);

        // La risposta reale è { AdvancedAuth, Token }, ma il client resta tollerante
        // agli alias osservati nel POC (token/Token/access_token/jwt) e al caso in
        // cui il fornitore restituisca il token come stringa nuda.
        $token = match (true) {
            is_string($data) => $data,
            is_array($data) => (string) ($data['token'] ?? $data['Token'] ?? $data['access_token'] ?? $data['jwt'] ?? ''),
            default => '',
        };

        if ($token === '') {
            throw new FlightXAuthenticationException(
                'FlightX: login riuscito ma nessun token JWT trovato nella risposta.',
                context: is_array($data) ? $data : null,
            );
        }

        $this->setToken($token);

        return $token;
    }

    /**
     * Restituisce un token JWT valido, eseguendo il login automaticamente se
     * assente o scaduto localmente (TTL di 8 ore meno 5 minuti di margine).
     */
    public function ensureToken(): string
    {
        if ($this->tokenIsValid()) {
            /** @var string $token */
            $token = $this->token;

            return $token;
        }

        return $this->login();
    }

    /**
     * Imposta manualmente un token già ottenuto altrove, azzerando il timer di scadenza locale.
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
        $this->tokenIssuedAt = time();
    }

    /**
     * Token JWT correntemente valido, o null se assente/scaduto. A differenza di
     * {@see self::ensureToken()} non effettua alcun login.
     */
    public function token(): ?string
    {
        return $this->tokenIsValid() ? $this->token : null;
    }

    /**
     * Invalida il token corrente, forzando un nuovo login alla prossima chiamata autenticata.
     */
    public function forgetToken(): void
    {
        $this->token = null;
        $this->tokenIssuedAt = null;
    }

    private function tokenIsValid(): bool
    {
        return $this->token !== null
            && $this->tokenIssuedAt !== null
            && (time() - $this->tokenIssuedAt) < self::TOKEN_TTL_SECONDS;
    }

    // -------------------------------------------------------------------------
    // Booking: ricerca e selezione (sola lettura)
    // -------------------------------------------------------------------------

    /**
     * Cerca soluzioni di volo. Restituisce la risposta integrale dell'API: il
     * dettaglio di ogni volo (tratte, orari, bagagli, fare family, prezzi) si trova
     * in `Result.Items[].ListOptions[].Options[].Flights[]` — non esiste un endpoint
     * separato di "dettaglio volo", è tutto già in questa risposta.
     *
     * @param  string  $departureAirport  Codice IATA aeroporto di partenza (3 lettere).
     * @param  string  $arrivalAirport  Codice IATA aeroporto di destinazione (3 lettere).
     * @param  string  $departureDate  Data di partenza, formato YYYY-MM-DD.
     * @param  string|null  $returnDate  Data di ritorno YYYY-MM-DD, obbligatoria se $searchType è 'RT'.
     * @param  int  $adults  Numero adulti (ADT, età ≥12). Minimo: 1 adulto o 1 bambino.
     * @param  int  $children  Numero bambini (CHD, età 2-11).
     * @param  int  $infants  Numero neonati (INF, età ≤2). Non può superare adulti + bambini.
     * @param  string  $searchType  'OW' (sola andata) o 'RT' (andata e ritorno).
     * @param  string  $tariffType  Tipo tariffa. Nota: la documentazione del fornitore
     *                              indica 'RT'/'ETH'/'ITX', ma sia il POC sia le request
     *                              reali osservate usano 'REG' come default: è il valore
     *                              qui adottato, in attesa di conferma dal fornitore.
     * @return array<string, mixed>
     *
     * @throws FlightXValidationException se i parametri non rispettano le regole IATA/data/pax.
     * @throws FlightXException per errori di rete, autenticazione,
     *                          rate limit, server o applicativi (IsValid=false).
     */
    public function searchFlights(
        string $departureAirport,
        string $arrivalAirport,
        string $departureDate,
        ?string $returnDate = null,
        int $adults = 1,
        int $children = 0,
        int $infants = 0,
        string $searchType = 'OW',
        string $tariffType = 'REG',
    ): array {
        $departureAirport = $this->assertIata($departureAirport, 'departureAirport');
        $arrivalAirport = $this->assertIata($arrivalAirport, 'arrivalAirport');
        $this->assertDate($departureDate, 'departureDate');
        $this->assertPaxRules($adults, $children, $infants);

        $searchType = strtoupper($searchType);
        if (! in_array($searchType, ['OW', 'RT'], true)) {
            throw new FlightXValidationException("FlightX: 'searchType' deve essere 'OW' o 'RT', ricevuto: '{$searchType}'.");
        }

        if ($searchType === 'RT') {
            if ($returnDate === null) {
                throw new FlightXValidationException("FlightX: 'returnDate' è obbligatorio quando searchType è 'RT'.");
            }
            $this->assertDate($returnDate, 'returnDate');
        }

        // SearchRows: una tratta per sola andata, due (andata + ritorno) per RT.
        $searchRows = [[
            'DestinationFrom' => [$departureAirport],
            'DestinationTo' => [$arrivalAirport],
            'Depart' => "{$departureDate}T00:00:00",
        ]];
        if ($searchType === 'RT') {
            $searchRows[] = [
                'DestinationFrom' => [$arrivalAirport],
                'DestinationTo' => [$departureAirport],
                'Depart' => "{$returnDate}T00:00:00",
            ];
        }

        $body = [
            'Adults' => $adults,
            'Children' => $children,
            'Infants' => $infants,
            'Airlines' => [],
            'InPlaceMarkups' => [
                'AdultAgencyMarkup' => 0,
                'AdultAgencyFee' => 0,
                'InfantAgencyMarkup' => 0,
                'InfantAgencyFee' => 0,
            ],
            'IsRefundable' => false,
            'OnlyWithLuggage' => false,
            'SearchRows' => $searchRows,
            'SearchType' => $searchType,
            'StopsPreference' => '',
            'StrictSearch' => false,
            'TariffType' => $tariffType,
        ];

        return $this->searchRaw($body);
    }

    /**
     * Variante di {@see self::searchFlights()} che accetta un body già completo,
     * per chi vuole costruire manualmente filtri avanzati (es. multitratta, non
     * coperti dai parametri nominati del metodo tipizzato).
     *
     * @param  array<string, mixed>  $body  Body conforme allo schema `api/booking/search` dell'API.
     * @return array<string, mixed>
     */
    public function searchRaw(array $body): array
    {
        $result = $this->jsonRequest('POST', 'api/booking/search', $body);

        // Memorizzato sempre: selectItem ne ha bisogno per riecheggiare la ricerca.
        $this->lastSearchRequest = $body;

        return $result;
    }

    /**
     * Body dell'ultima {@see self::searchFlights()}/{@see self::searchRaw()} eseguita
     * su questa istanza, oppure null se nessuna ricerca è ancora stata fatta.
     *
     * @return array<string, mixed>|null
     */
    public function lastSearchRequest(): ?array
    {
        return $this->lastSearchRequest;
    }

    /**
     * Seleziona una singola opzione tra i risultati di una ricerca precedente,
     * verificandone disponibilità e prezzo, e crea un dossier temporaneo (valido 24h,
     * poi riciclato dal fornitore se non prenotato con bookItem).
     *
     * Per ricavare $itemId, $itemKey e $optionKeys da una risposta di searchFlights():
     *   - $item      = Result.Items[N]
     *   - $listOption = $item.ListOptions[0]           (o l'opzione scelta dall'utente)
     *   - $option    = $listOption.Options[0]
     *   - $itemId    = "{$item.ItemId}_{$listOption.OptionListId}"
     *   - $itemKey   = $item.ItemKey
     *   - $optionKeys = [$option.OptionKey]
     *
     * @param  string  $itemId  Nella forma "<ItemId>_<OptionListId>" (es. "1_1").
     * @param  string  $itemKey  Chiave dell'item, da Result.Items[].ItemKey.
     * @param  array<int, string>  $optionKeys  Chiavi dell'opzione scelta, da Options[].OptionKey.
     * @param  int  $adults  Deve coincidere con il numero di adulti della ricerca originale.
     * @param  string|null  $searchType  Default: quello della ricerca memorizzata (o esplicita in $currentSearchRequest).
     * @param  array<string, mixed>|null  $currentSearchRequest  Body della ricerca da riecheggiare.
     *                                                           Default: {@see self::lastSearchRequest()}.
     * @return array<string, mixed>
     *
     * @throws FlightXValidationException se non è disponibile alcuna ricerca precedente
     *                                    né viene passato esplicitamente $currentSearchRequest.
     */
    public function selectFlightOption(
        string $itemId,
        string $itemKey,
        array $optionKeys,
        int $adults,
        int $children = 0,
        int $infants = 0,
        ?string $searchType = null,
        ?array $currentSearchRequest = null,
    ): array {
        $currentSearchRequest ??= $this->lastSearchRequest;
        if ($currentSearchRequest === null) {
            throw new FlightXValidationException(
                'FlightX: nessuna ricerca precedente disponibile su questa istanza. '
                .'Eseguire prima searchFlights()/searchRaw(), oppure passare esplicitamente $currentSearchRequest.',
            );
        }

        $searchType ??= (string) ($currentSearchRequest['SearchType'] ?? 'OW');

        $body = [
            'CurrentSearchRequest' => $currentSearchRequest,
            'ItemId' => $itemId,
            'ItemKey' => $itemKey,
            'ListId' => 'FilteredItems',
            'NumberOfAdults' => $adults,
            'NumberOfChildren' => $children,
            'NumberOfInfants' => $infants,
            'OptionKeys' => array_values($optionKeys),
            'SearchType' => $searchType,
        ];

        return $this->jsonRequest('POST', 'api/booking/selectItem', $body);
    }

    // -------------------------------------------------------------------------
    // Dossier (sola lettura)
    // -------------------------------------------------------------------------

    /**
     * Recupera il dettaglio di un dossier: voli, passeggeri, ancillari e stato
     * (utilizzabile sia prima sia dopo un'eventuale prenotazione).
     *
     * @return array<string, mixed>
     */
    public function dossier(string $dossierId): array
    {
        return $this->jsonRequest('GET', 'api/dossier/get', query: ['id' => $dossierId]);
    }

    /**
     * Recupera il voucher/documento di viaggio di un dossier (utile principalmente
     * dopo l'emissione dei biglietti, non gestita da questo wrapper).
     *
     * @param  string  $lang  Lingua del documento: 'IT', 'EN' o 'ZH-CN'.
     * @return array<string, mixed>
     */
    public function printDossier(string $dossierId, bool $showTariffs = false, string $lang = 'IT'): array
    {
        $query = [
            'id' => $dossierId,
            'type' => 'TK',
            'showTariffs' => $showTariffs ? 'true' : 'false',
            'lang' => $lang,
        ];

        return $this->jsonRequest('GET', 'api/print/dossier', query: $query);
    }

    // -------------------------------------------------------------------------
    // Validazione locale (prima di chiamare la rete)
    // -------------------------------------------------------------------------

    /**
     * Verifica che $code sia un codice IATA di 3 lettere e lo normalizza in maiuscolo.
     *
     * @throws FlightXValidationException
     */
    private function assertIata(string $code, string $field): string
    {
        if (! preg_match('/^[A-Za-z]{3}$/', $code)) {
            throw new FlightXValidationException("FlightX: '{$field}' deve essere un codice IATA di 3 lettere, ricevuto: '{$code}'.");
        }

        return strtoupper($code);
    }

    /**
     * Verifica che $date sia una data valida in formato YYYY-MM-DD.
     *
     * @throws FlightXValidationException
     */
    private function assertDate(string $date, string $field): void
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new FlightXValidationException("FlightX: '{$field}' deve essere una data in formato YYYY-MM-DD, ricevuto: '{$date}'.");
        }
    }

    /**
     * Applica le regole passeggeri documentate dal fornitore: almeno 1 adulto o 1
     * bambino, massimo 9 passeggeri totali, neonati non superiori ad adulti + bambini.
     *
     * @throws FlightXValidationException
     */
    private function assertPaxRules(int $adults, int $children, int $infants): void
    {
        if ($adults < 0 || $children < 0 || $infants < 0) {
            throw new FlightXValidationException('FlightX: il numero di passeggeri non può essere negativo.');
        }

        if ($adults < 1 && $children < 1) {
            throw new FlightXValidationException('FlightX: è richiesto almeno 1 adulto o 1 bambino.');
        }

        $total = $adults + $children + $infants;
        if ($total > 9) {
            throw new FlightXValidationException("FlightX: massimo 9 passeggeri totali, ricevuti {$total}.");
        }

        if ($infants > $adults + $children) {
            throw new FlightXValidationException('FlightX: il numero di neonati non può superare quello di adulti + bambini.');
        }
    }

    // -------------------------------------------------------------------------
    // HTTP core
    // -------------------------------------------------------------------------

    /**
     * Esegue una richiesta e garantisce che il risultato decodificato sia un array
     * (oggetto JSON), lanciando un errore altrimenti. Usato da tutti i metodi
     * pubblici tranne {@see self::login()}, la cui risposta può eccezionalmente
     * essere una stringa nuda.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $endpoint, ?array $body = null, array $query = [], bool $useToken = true): array
    {
        $data = $this->rawRequest($method, $endpoint, $body, $query, $useToken);

        if (! is_array($data)) {
            throw new FlightXApiException(
                "FlightX: risposta inattesa per {$endpoint}: atteso un oggetto JSON.",
                errors: [],
                context: ['raw' => $data],
            );
        }

        return $data;
    }

    /**
     * Unico punto di uscita HTTP verso l'API FlightX (equivalente al metodo privato
     * `#request` del POC JavaScript). Gestisce: header obbligatori, retry sugli
     * errori transitori, un singolo rinnovo automatico del token su 401, decodifica
     * JSON e traduzione degli errori (HTTP e applicativi) nella gerarchia di eccezioni
     * del namespace {@see Exceptions}.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws FlightXException
     */
    private function rawRequest(string $method, string $endpoint, ?array $body = null, array $query = [], bool $useToken = true): mixed
    {
        $response = $this->send($method, $endpoint, $body, $query, $useToken);

        if ($useToken && $response->getStatusCode() === 401 && $this->token !== null) {
            // Il TTL locale (8h - 5min) non garantisce che il server non abbia già
            // invalidato il token: un solo rinnovo automatico, poi ci si arrende.
            $this->forgetToken();
            $response = $this->send($method, $endpoint, $body, $query, $useToken);
        }

        return $this->decodeOrThrow($response, $endpoint);
    }

    /**
     * Costruisce ed esegue la richiesta HTTP, ritentando gli errori transitori
     * (connessione, 429, 5xx) fino a `$config->retries` volte in più. Un 4xx di
     * validazione non viene mai ritentato.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws FlightXConnectionException
     */
    private function send(string $method, string $endpoint, ?array $body, array $query, bool $useToken): ResponseInterface
    {
        $headers = [
            'Content-Type' => 'application/json',
            'ApiKey' => $this->config->apiKey,
        ];
        if ($useToken) {
            $headers['Token'] = $this->ensureToken();
        }

        $this->logRequest($method, $endpoint, $headers, $body, $query);

        $maxAttempts = max(1, $this->config->retries + 1);
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $options = ['headers' => $headers];
                if (strtoupper($method) === 'GET') {
                    $options['query'] = $query;
                } else {
                    $options['json'] = $body ?? [];
                }

                $response = $this->http->request(strtoupper($method), ltrim($endpoint, '/'), $options);
            } catch (ConnectException $e) {
                $lastConnectionError = $e;
                if ($attempt < $maxAttempts) {
                    usleep($this->config->retryDelayMs * 1000);

                    continue;
                }

                throw new FlightXConnectionException(
                    "FlightX: errore di connessione verso {$endpoint}: {$e->getMessage()}",
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
        throw new FlightXConnectionException(
            "FlightX: impossibile completare la richiesta verso {$endpoint}.",
            previous: $lastConnectionError,
        );
    }

    /**
     * Decodifica il corpo JSON della risposta e traduce sia gli errori HTTP sia gli
     * errori applicativi (`IsValid: false`) nell'eccezione appropriata.
     *
     * @throws FlightXException
     */
    private function decodeOrThrow(ResponseInterface $response, string $endpoint): mixed
    {
        $rawBody = (string) $response->getBody();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
            // Corpo non-JSON: preservato per il debug, come il fallback rawText del POC.
            $decoded = ['rawText' => $rawBody];
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $detail = $this->extractErrorDetail($decoded, $rawBody);
            $message = "FlightX [{$status}] {$endpoint}: {$detail}";
            $context = is_array($decoded) ? $decoded : null;

            throw match (true) {
                $status === 401, $status === 403 => new FlightXAuthenticationException($message, $context),
                $status === 429 => new FlightXRateLimitException(
                    $message,
                    $this->retryAfterFromHeader($response),
                    $context,
                ),
                $status >= 500 => new FlightXServerException($message, $context),
                default => new FlightXValidationException($message, $context),
            };
        }

        // Envelope applicativo comune a tutte le risposte FlightX: HTTP 200 non
        // garantisce successo, va sempre controllato anche IsValid.
        if (is_array($decoded) && array_key_exists('IsValid', $decoded) && $decoded['IsValid'] === false) {
            $errors = array_values(array_map(
                static fn (array $e): array => [
                    'code' => $e['Code'] ?? null,
                    'text' => $e['ShortText'] ?? $e['Message'] ?? null,
                ],
                array_filter((array) ($decoded['Errors'] ?? []), 'is_array'),
            ));

            $summary = implode(' / ', array_filter(array_map(
                static fn (array $e): string => (string) ($e['text'] ?? $e['code'] ?? ''),
                $errors,
            )));

            throw new FlightXApiException(
                "FlightX: {$endpoint} ha risposto con un errore applicativo".($summary !== '' ? " ({$summary})" : '.'),
                errors: $errors,
                context: $decoded,
            );
        }

        return $decoded;
    }

    private function extractErrorDetail(mixed $decoded, string $rawBody): string
    {
        if (is_array($decoded)) {
            return (string) ($decoded['message'] ?? $decoded['Message'] ?? $decoded['error'] ?? $decoded['Error'] ?? json_encode($decoded));
        }

        return $rawBody !== '' ? $rawBody : '(corpo risposta vuota)';
    }

    private function retryAfterFromHeader(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    /**
     * Registra la richiesta su STDERR se `$config->logRequests` è attivo, oscurando
     * sempre le credenziali (ApiKey, Token, Password) sia negli header sia nel body.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     */
    private function logRequest(string $method, string $endpoint, array $headers, ?array $body, array $query = []): void
    {
        if (! $this->config->logRequests) {
            return;
        }

        fwrite(STDERR, '[FlightX] ' . json_encode([
            'method' => $method,
            'endpoint' => $endpoint,
            'headers' => $this->redact($headers),
            'query' => $query,
            'body' => $this->redact($body),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $redacted = $data;
        foreach (['Password', 'password', 'ApiKey', 'apiKey', 'Token', 'token'] as $key) {
            if (array_key_exists($key, $redacted)) {
                $redacted[$key] = '***';
            }
        }

        return $redacted;
    }
}

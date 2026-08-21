<?php

namespace App\Services\Hotelbeds;

use App\Services\Hotelbeds\Exceptions\HotelbedsApiException;
use App\Services\Hotelbeds\Exceptions\HotelbedsAuthenticationException;
use App\Services\Hotelbeds\Exceptions\HotelbedsConnectionException;
use App\Services\Hotelbeds\Exceptions\HotelbedsException;
use App\Services\Hotelbeds\Exceptions\HotelbedsRateLimitException;
use App\Services\Hotelbeds\Exceptions\HotelbedsServerException;
use App\Services\Hotelbeds\Exceptions\HotelbedsValidationException;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;

/**
 * Wrapper server-side per le API Hotelbeds (HBX Group) Hotel Booking API v1.0.
 *
 * Perimetro di questa classe: SOLA LETTURA. Espone la ricerca disponibilità
 * (in 4 varianti) e la riverifica di un `rateKey`:
 *   1. {@see self::searchByGeolocation()} / {@see self::searchByArea()} /
 *      {@see self::searchByDestination()} / {@see self::searchByHotelCodes()}
 *      — elenco degli hotel disponibili, ciascuno con le proprie tariffe.
 *   2. {@see self::checkRates()} — riverifica prezzo/condizioni aggiornate di
 *      uno o più `rateKey` prima di una eventuale prenotazione.
 *
 * Le operazioni di scrittura (`POST /bookings`, `PUT /bookings/{ref}`,
 * `DELETE /bookings/{ref}`) NON sono esposte e non vanno aggiunte senza una
 * decisione di prodotto esplicita: questo wrapper è pensato per un uso
 * consultivo, ad esempio dietro un tool MCP di sola lettura (come
 * {@see \App\Services\FlightX\FlightXClient}, di cui questa classe replica il
 * pattern architetturale).
 *
 * IMPORTANTE — stato interno: a differenza di FlightXClient, questa classe è
 * STATELESS. L'autenticazione non usa un token con TTL ma una firma calcolata
 * a partire da `apiKey` + `secret` + timestamp Unix corrente, ricalcolata a
 * ogni singola richiesta (vedi {@see self::signatureHeaders()}). Non c'è quindi
 * alcun avviso di condivisione: un'istanza può essere riusata liberamente fra
 * richieste/utenti diversi, anche come singleton.
 *
 * Gotcha verificati sull'ambiente test (`https://api.test.hotelbeds.com`):
 *   - La firma `X-Signature` ha validità di pochi minuti: accettata dopo 5
 *     minuti, rifiutata con HTTP 401 "Request signature verification failed"
 *     dopo 15. Per questo va ricalcolata a ogni tentativo HTTP, inclusi i retry.
 *   - La ricerca a rettangolo (`searchByArea()`) richiede le coordinate come
 *     STRINGHE e rifiuta con `400 INVALID_DATA` se sono presenti `radius`/`unit`.
 *   - La ricerca per destinazione (`searchByDestination()`, blocco `destination`)
 *     funziona ma non è documentata nello schema `availabilityRQ` dell'OpenAPI
 *     ufficiale: compare solo negli esempi.
 *   - `GET /status` funziona ma non è nemmeno lui nello spec OpenAPI.
 *   - Tutti gli importi nella risposta (`net`, `sellingRate`, tasse, ecc.) sono
 *     STRINGHE, non numeri: vanno castati esplicitamente prima di fare aritmetica.
 *   - Rate limit dichiarato in ambiente test: 50 richieste/minuto, esposto dagli
 *     header di risposta `X-Ratelimit-Limit`/`X-Ratelimit-Remaining`.
 *
 * Non documentato dal fornitore nello schema, dedotto dal contratto reale
 * delle API: un errore di dominio (es. geolocalizzazione mal formata) può
 * arrivare con HTTP 200 e una chiave `error` nel corpo. Questa classe lo
 * traduce sempre in {@see HotelbedsApiException}, mai in un valore di ritorno
 * silenzioso.
 */
final class HotelbedsClient
{
    private const MAX_RADIUS_KM_OR_MI = 200;

    private const MAX_HOTEL_CODES = 2000;

    private const UNITS = ['km', 'mi'];

    private Client $http;

    public function __construct(
        private readonly HotelbedsConfig $config,
    ) {
        $this->http = new Client([
            'base_uri' => $this->config->baseUrl . '/',
            'timeout' => $this->config->timeout,
            // Gli status HTTP vengono tradotti nelle eccezioni del wrapper da decodeOrThrow().
            'http_errors' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Health check
    // -------------------------------------------------------------------------

    /**
     * Verifica la raggiungibilità e l'autenticazione verso l'API. Risposta
     * attesa: `['status' => 'OK']`.
     *
     * @return array<string, mixed>
     *
     * @throws HotelbedsException
     */
    public function status(): array
    {
        return $this->jsonRequest('GET', 'status');
    }

    // -------------------------------------------------------------------------
    // Disponibilità: le 4 varianti di ricerca (sola lettura)
    // -------------------------------------------------------------------------

    /**
     * Cerca hotel disponibili entro un raggio da un punto (ricerca "a cerchio").
     *
     * @param  float  $latitude  Latitudine del centro, fra -90 e 90.
     * @param  float  $longitude  Longitudine del centro, fra -180 e 180.
     * @param  string  $checkIn  Data di check-in, formato YYYY-MM-DD, non nel passato.
     * @param  string  $checkOut  Data di check-out, formato YYYY-MM-DD, successiva a $checkIn.
     * @param  float  $radius  Raggio di ricerca, maggiore di 0 e non superiore a 200. Unità definita da $unit.
     * @param  string  $unit  Unità del raggio: 'km' o 'mi'.
     * @param  int  $rooms  Numero di camere richieste (ignorato se $occupancies è fornito).
     * @param  int  $adults  Adulti per camera (ignorato se $occupancies è fornito).
     * @param  int  $children  Bambini per camera (ignorato se $occupancies è fornito).
     * @param  array<int, int>  $childrenAges  Età (0-17) di ciascun bambino, richiesto se $children > 0
     *                                        e $occupancies non è fornito. Deve avere esattamente $children elementi.
     * @param  array<int, array<string, mixed>>|null  $occupancies  Blocco `occupancies` completo, sostituisce
     *                                                              integralmente $rooms/$adults/$children/$childrenAges
     *                                                              quando fornito (utile per camere eterogenee).
     * @param  array<string, mixed>|null  $filter  Blocco `filter` opzionale (maxHotels, minCategory, maxRate, ecc.).
     * @return array<string, mixed>  Risposta integrale dell'API: l'elenco hotel è in `hotels.hotels[]`,
     *                                ciascuno con `rooms[].rates[]`.
     *
     * @throws HotelbedsValidationException se un parametro non rispetta le regole locali.
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function searchByGeolocation(
        float $latitude,
        float $longitude,
        string $checkIn,
        string $checkOut,
        float $radius = 20,
        string $unit = 'km',
        int $rooms = 1,
        int $adults = 2,
        int $children = 0,
        array $childrenAges = [],
        ?array $occupancies = null,
        ?array $filter = null,
    ): array {
        $this->assertLatitude($latitude, 'latitude');
        $this->assertLongitude($longitude, 'longitude');
        $this->assertRadius($radius);
        $unit = $this->assertUnit($unit);

        $criteria = ['geolocation' => [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius' => $radius,
            'unit' => $unit,
        ]];

        return $this->availability($criteria, $checkIn, $checkOut, $rooms, $adults, $children, $childrenAges, $occupancies, $filter);
    }

    /**
     * Cerca hotel disponibili entro un'area rettangolare (bounding box).
     *
     * ⚠️ Nel body inviato all'API le quattro coordinate sono sempre STRINGHE e
     * non compaiono mai `radius`/`unit`: l'API risponde `400 INVALID_DATA`
     * ("Geolocation by area requires all coordinates") se questa regola non è
     * rispettata. Questo metodo non ha parametri `radius`/`unit` nella firma
     * proprio per rendere impossibile costruire il body ibrido rifiutato.
     *
     * @param  float  $latitude  Latitudine dell'angolo alto-sinistra, fra -90 e 90.
     * @param  float  $longitude  Longitudine dell'angolo alto-sinistra, fra -180 e 180.
     * @param  float  $secondaryLatitude  Latitudine dell'angolo basso-destra, fra -90 e 90, diversa da $latitude.
     * @param  float  $secondaryLongitude  Longitudine dell'angolo basso-destra, fra -180 e 180, diversa da $longitude.
     * @param  string  $checkIn  Data di check-in, formato YYYY-MM-DD, non nel passato.
     * @param  string  $checkOut  Data di check-out, formato YYYY-MM-DD, successiva a $checkIn.
     * @param  int  $rooms  Numero di camere richieste (ignorato se $occupancies è fornito).
     * @param  int  $adults  Adulti per camera (ignorato se $occupancies è fornito).
     * @param  int  $children  Bambini per camera (ignorato se $occupancies è fornito).
     * @param  array<int, int>  $childrenAges  Età (0-17) di ciascun bambino, richiesto se $children > 0
     *                                        e $occupancies non è fornito.
     * @param  array<int, array<string, mixed>>|null  $occupancies  Blocco `occupancies` completo, sostituisce
     *                                                              integralmente $rooms/$adults/$children/$childrenAges.
     * @param  array<string, mixed>|null  $filter  Blocco `filter` opzionale.
     * @return array<string, mixed>
     *
     * @throws HotelbedsValidationException se un parametro non rispetta le regole locali.
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function searchByArea(
        float $latitude,
        float $longitude,
        float $secondaryLatitude,
        float $secondaryLongitude,
        string $checkIn,
        string $checkOut,
        int $rooms = 1,
        int $adults = 2,
        int $children = 0,
        array $childrenAges = [],
        ?array $occupancies = null,
        ?array $filter = null,
    ): array {
        $this->assertLatitude($latitude, 'latitude');
        $this->assertLongitude($longitude, 'longitude');
        $this->assertLatitude($secondaryLatitude, 'secondaryLatitude');
        $this->assertLongitude($secondaryLongitude, 'secondaryLongitude');

        if ($latitude === $secondaryLatitude || $longitude === $secondaryLongitude) {
            throw new HotelbedsValidationException(
                'Hotelbeds: il rettangolo di ricerca è degenere: le coordinate primarie e secondarie devono differire.',
            );
        }

        $criteria = ['geolocation' => [
            'latitude' => $this->coordinateString($latitude),
            'longitude' => $this->coordinateString($longitude),
            'secondaryLatitude' => $this->coordinateString($secondaryLatitude),
            'secondaryLongitude' => $this->coordinateString($secondaryLongitude),
        ]];

        return $this->availability($criteria, $checkIn, $checkOut, $rooms, $adults, $children, $childrenAges, $occupancies, $filter);
    }

    /**
     * Cerca hotel disponibili in una destinazione HBX (es. 'TRN' per Torino,
     * 'MIL' per Milano). I codici si ricavano dalla Content API
     * (`hotel-content-api/1.0/locations/destinations`, non coperta da questo
     * client) oppure dal campo `destinationCode` nelle risposte di disponibilità.
     *
     * Nota: il blocco `destination` funziona (verificato con TRN/MIL) ma non è
     * dichiarato nello schema `availabilityRQ` dell'OpenAPI ufficiale.
     *
     * @param  string  $destinationCode  Codice destinazione HBX, 2-5 lettere (case-insensitive).
     * @param  string  $checkIn  Data di check-in, formato YYYY-MM-DD, non nel passato.
     * @param  string  $checkOut  Data di check-out, formato YYYY-MM-DD, successiva a $checkIn.
     * @param  int  $rooms  Numero di camere richieste (ignorato se $occupancies è fornito).
     * @param  int  $adults  Adulti per camera (ignorato se $occupancies è fornito).
     * @param  int  $children  Bambini per camera (ignorato se $occupancies è fornito).
     * @param  array<int, int>  $childrenAges  Età (0-17) di ciascun bambino, richiesto se $children > 0
     *                                        e $occupancies non è fornito.
     * @param  array<int, array<string, mixed>>|null  $occupancies  Blocco `occupancies` completo, sostituisce
     *                                                              integralmente $rooms/$adults/$children/$childrenAges.
     * @param  array<string, mixed>|null  $filter  Blocco `filter` opzionale.
     * @return array<string, mixed>
     *
     * @throws HotelbedsValidationException se un parametro non rispetta le regole locali.
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function searchByDestination(
        string $destinationCode,
        string $checkIn,
        string $checkOut,
        int $rooms = 1,
        int $adults = 2,
        int $children = 0,
        array $childrenAges = [],
        ?array $occupancies = null,
        ?array $filter = null,
    ): array {
        $criteria = ['destination' => ['code' => $this->assertDestinationCode($destinationCode)]];

        return $this->availability($criteria, $checkIn, $checkOut, $rooms, $adults, $children, $childrenAges, $occupancies, $filter);
    }

    /**
     * Cerca disponibilità per un elenco esplicito di codici hotel HBX (max 2000).
     *
     * @param  array<int, int|string>  $hotelCodes  Codici hotel numerici (stringhe numeriche accettate).
     * @param  string  $checkIn  Data di check-in, formato YYYY-MM-DD, non nel passato.
     * @param  string  $checkOut  Data di check-out, formato YYYY-MM-DD, successiva a $checkIn.
     * @param  int  $rooms  Numero di camere richieste (ignorato se $occupancies è fornito).
     * @param  int  $adults  Adulti per camera (ignorato se $occupancies è fornito).
     * @param  int  $children  Bambini per camera (ignorato se $occupancies è fornito).
     * @param  array<int, int>  $childrenAges  Età (0-17) di ciascun bambino, richiesto se $children > 0
     *                                        e $occupancies non è fornito.
     * @param  array<int, array<string, mixed>>|null  $occupancies  Blocco `occupancies` completo, sostituisce
     *                                                              integralmente $rooms/$adults/$children/$childrenAges.
     * @param  array<string, mixed>|null  $filter  Blocco `filter` opzionale.
     * @return array<string, mixed>
     *
     * @throws HotelbedsValidationException se un parametro non rispetta le regole locali.
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function searchByHotelCodes(
        array $hotelCodes,
        string $checkIn,
        string $checkOut,
        int $rooms = 1,
        int $adults = 2,
        int $children = 0,
        array $childrenAges = [],
        ?array $occupancies = null,
        ?array $filter = null,
    ): array {
        $criteria = ['hotels' => ['hotel' => $this->assertHotelCodes($hotelCodes)]];

        return $this->availability($criteria, $checkIn, $checkOut, $rooms, $adults, $children, $childrenAges, $occupancies, $filter);
    }

    /**
     * Variante di ricerca che accetta un body `POST /hotels` già completo, per
     * chi vuole costruire manualmente un criterio non coperto dai metodi
     * tipizzati (es. combinazioni avanzate di `filter`, `boards`, `keywords`).
     * Nessuna validazione locale: il body è inviato così com'è.
     *
     * @param  array<string, mixed>  $body  Body conforme allo schema `availabilityRQ` dell'API.
     * @return array<string, mixed>
     *
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function availabilityRaw(array $body): array
    {
        return $this->jsonRequest('POST', 'hotels', $body);
    }

    // -------------------------------------------------------------------------
    // Riverifica tariffa (sola lettura)
    // -------------------------------------------------------------------------

    /**
     * Riverifica prezzo e condizioni aggiornate di uno o più `rateKey` prima di
     * un'eventuale prenotazione. Un `rateKey` proviene da una risposta di
     * disponibilità (`hotels.hotels[].rooms[].rates[].rateKey`) e scade dopo
     * pochi minuti: se questo metodo lancia un errore di validazione/API,
     * spesso la causa è un `rateKey` non più valido e va rifatta la ricerca.
     *
     * @param  array<int, string>  $rateKeys  Uno o più `rateKey` da riverificare, non vuoti.
     * @return array<string, mixed>
     *
     * @throws HotelbedsValidationException se $rateKeys è vuoto o contiene stringhe vuote.
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function checkRates(array $rateKeys): array
    {
        $rateKeys = $this->assertRateKeys($rateKeys);

        $body = ['rooms' => array_map(static fn (string $key): array => ['rateKey' => $key], $rateKeys)];

        return $this->checkRatesRaw($body);
    }

    /**
     * Variante di {@see self::checkRates()} che accetta un body `POST /checkrates`
     * già completo (es. per aggiungere `paxes` per camera). Nessuna validazione locale.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws HotelbedsException per errori di rete, autenticazione, rate limit, server o applicativi.
     */
    public function checkRatesRaw(array $body): array
    {
        return $this->jsonRequest('POST', 'checkrates', $body);
    }

    // -------------------------------------------------------------------------
    // Costruzione del body di disponibilità
    // -------------------------------------------------------------------------

    /**
     * Punto unico di costruzione e invio del body `POST /hotels`, condiviso
     * dalle 4 varianti di ricerca tipizzate. `$criteria` è il blocco (già
     * validato) che le distingue: `geolocation`, `destination` o `hotels`.
     *
     * @param  array<string, mixed>  $criteria
     * @param  array<int, int>  $childrenAges
     * @param  array<int, array<string, mixed>>|null  $occupancies
     * @param  array<string, mixed>|null  $filter
     * @return array<string, mixed>
     */
    private function availability(
        array $criteria,
        string $checkIn,
        string $checkOut,
        int $rooms,
        int $adults,
        int $children,
        array $childrenAges,
        ?array $occupancies,
        ?array $filter,
    ): array {
        $this->assertStay($checkIn, $checkOut);

        $body = array_merge([
            'stay' => $this->buildStay($checkIn, $checkOut),
            'occupancies' => $this->buildOccupancies($rooms, $adults, $children, $childrenAges, $occupancies),
        ], $criteria);

        if ($filter !== null) {
            $body['filter'] = $this->assertFilter($filter);
        }

        return $this->jsonRequest('POST', 'hotels', $body);
    }

    /**
     * @return array{checkIn: string, checkOut: string}
     */
    private function buildStay(string $checkIn, string $checkOut): array
    {
        return ['checkIn' => $checkIn, 'checkOut' => $checkOut];
    }

    /**
     * Costruisce il blocco `occupancies`. Se $occupancies è fornito, sostituisce
     * integralmente gli argomenti scalari (dopo averne validato la forma).
     *
     * @param  array<int, int>  $childrenAges
     * @param  array<int, array<string, mixed>>|null  $occupancies
     * @return array<int, array<string, mixed>>
     */
    private function buildOccupancies(int $rooms, int $adults, int $children, array $childrenAges, ?array $occupancies): array
    {
        if ($occupancies !== null) {
            return $this->assertOccupanciesOverride($occupancies);
        }

        $this->assertOccupancy($rooms, $adults, $children, $childrenAges);

        $occupancy = ['rooms' => $rooms, 'adults' => $adults, 'children' => $children];
        if ($children > 0) {
            $occupancy['paxes'] = array_map(
                static fn (int $age): array => ['type' => 'CH', 'age' => $age],
                $childrenAges,
            );
        }

        return [$occupancy];
    }

    /**
     * Formatta una coordinata come STRINGA decimale a precisione fissa.
     *
     * Obbligatorio per la ricerca a rettangolo ({@see self::searchByArea()}):
     * l'API risponde `400 INVALID_DATA` ("Geolocation by area requires all
     * coordinates") se le quattro coordinate non sono stringhe. Non si usa un
     * semplice cast `(string) $value` perché la serializzazione JSON di un
     * float PHP può produrre code decimali spurie (es. "7.5776000000000004")
     * o notazione esponenziale per valori molto piccoli.
     *
     * 6 decimali equivalgono a circa 11 cm: più che sufficiente per una
     * bounding box alberghiera.
     */
    private function coordinateString(float $value): string
    {
        $formatted = number_format($value, 6, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    // -------------------------------------------------------------------------
    // Validazioni locali pre-rete
    // -------------------------------------------------------------------------

    /**
     * @throws HotelbedsValidationException
     */
    private function assertDate(string $date, string $field): void
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new HotelbedsValidationException("Hotelbeds: '{$field}' deve essere una data in formato YYYY-MM-DD, ricevuto: '{$date}'.");
        }
    }

    /**
     * @throws HotelbedsValidationException
     */
    private function assertStay(string $checkIn, string $checkOut): void
    {
        $this->assertDate($checkIn, 'checkIn');
        $this->assertDate($checkOut, 'checkOut');

        $today = date('Y-m-d');
        if ($checkIn < $today) {
            throw new HotelbedsValidationException("Hotelbeds: 'checkIn' non può essere nel passato, ricevuto: '{$checkIn}' (oggi: '{$today}').");
        }

        if ($checkOut <= $checkIn) {
            throw new HotelbedsValidationException("Hotelbeds: 'checkOut' deve essere successivo a 'checkIn' ({$checkIn} → {$checkOut}).");
        }
    }

    /**
     * @throws HotelbedsValidationException
     */
    private function assertLatitude(float $value, string $field): void
    {
        if ($value < -90 || $value > 90) {
            throw new HotelbedsValidationException("Hotelbeds: '{$field}' deve essere compresa fra -90 e 90, ricevuto: {$value}.");
        }
    }

    /**
     * @throws HotelbedsValidationException
     */
    private function assertLongitude(float $value, string $field): void
    {
        if ($value < -180 || $value > 180) {
            throw new HotelbedsValidationException("Hotelbeds: '{$field}' deve essere compresa fra -180 e 180, ricevuto: {$value}.");
        }
    }

    /**
     * @throws HotelbedsValidationException
     */
    private function assertRadius(float $radius): void
    {
        if ($radius <= 0 || $radius > self::MAX_RADIUS_KM_OR_MI) {
            throw new HotelbedsValidationException('Hotelbeds: \'radius\' deve essere maggiore di 0 e non superiore a '.self::MAX_RADIUS_KM_OR_MI.", ricevuto: {$radius}.");
        }
    }

    /**
     * @throws HotelbedsValidationException
     */
    private function assertUnit(string $unit): string
    {
        $normalized = strtolower($unit);
        if (! in_array($normalized, self::UNITS, true)) {
            throw new HotelbedsValidationException("Hotelbeds: 'unit' deve essere 'km' o 'mi', ricevuto: '{$unit}'.");
        }

        return $normalized;
    }

    /**
     * @throws HotelbedsValidationException
     */
    private function assertDestinationCode(string $code): string
    {
        if (! preg_match('/^[A-Za-z]{2,5}$/', $code)) {
            throw new HotelbedsValidationException("Hotelbeds: 'destinationCode' deve essere un codice destinazione HBX di 2-5 lettere, ricevuto: '{$code}'.");
        }

        return strtoupper($code);
    }

    /**
     * @param  array<int, int|string>  $codes
     * @return array<int, int>
     *
     * @throws HotelbedsValidationException
     */
    private function assertHotelCodes(array $codes): array
    {
        if ($codes === []) {
            throw new HotelbedsValidationException("Hotelbeds: 'hotelCodes' non può essere vuoto.");
        }

        if (count($codes) > self::MAX_HOTEL_CODES) {
            $count = count($codes);
            throw new HotelbedsValidationException('Hotelbeds: massimo '.self::MAX_HOTEL_CODES." codici hotel, ricevuti {$count}.");
        }

        return array_map(function (int|string $code): int {
            if (! is_int($code) && ! (is_string($code) && ctype_digit($code))) {
                throw new HotelbedsValidationException("Hotelbeds: 'hotelCodes' deve contenere solo codici numerici positivi, ricevuto: '{$code}'.");
            }

            $intCode = (int) $code;
            if ($intCode <= 0) {
                throw new HotelbedsValidationException("Hotelbeds: 'hotelCodes' deve contenere solo codici numerici positivi, ricevuto: '{$code}'.");
            }

            return $intCode;
        }, array_values($codes));
    }

    /**
     * @param  array<int, int>  $childrenAges
     *
     * @throws HotelbedsValidationException
     */
    private function assertOccupancy(int $rooms, int $adults, int $children, array $childrenAges): void
    {
        if ($rooms < 1) {
            throw new HotelbedsValidationException("Hotelbeds: 'rooms' deve essere almeno 1, ricevuto: {$rooms}.");
        }

        if ($adults < 1) {
            throw new HotelbedsValidationException("Hotelbeds: 'adults' deve essere almeno 1, ricevuto: {$adults}.");
        }

        if ($children < 0) {
            throw new HotelbedsValidationException("Hotelbeds: 'children' non può essere negativo, ricevuto: {$children}.");
        }

        if ($children > 0 && count($childrenAges) !== $children) {
            $count = count($childrenAges);
            throw new HotelbedsValidationException("Hotelbeds: 'childrenAges' deve contenere esattamente {$children} età, ricevute {$count}.");
        }

        foreach ($childrenAges as $age) {
            if ($age < 0 || $age > 17) {
                throw new HotelbedsValidationException("Hotelbeds: l'età di un bambino deve essere fra 0 e 17, ricevuta: {$age}.");
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $occupancies
     * @return array<int, array<string, mixed>>
     *
     * @throws HotelbedsValidationException
     */
    private function assertOccupanciesOverride(array $occupancies): array
    {
        if ($occupancies === []) {
            throw new HotelbedsValidationException("Hotelbeds: 'occupancies' deve essere una lista non vuota di occupazioni.");
        }

        foreach ($occupancies as $index => $occupancy) {
            if (! is_array($occupancy) || ($occupancy['rooms'] ?? 0) < 1 || ($occupancy['adults'] ?? 0) < 1) {
                throw new HotelbedsValidationException("Hotelbeds: 'occupancies[{$index}]' deve avere 'rooms' e 'adults' pari ad almeno 1.");
            }

            foreach ((array) ($occupancy['paxes'] ?? []) as $paxIndex => $pax) {
                $type = is_array($pax) ? ($pax['type'] ?? null) : null;
                if (! in_array($type, ['AD', 'CH'], true)) {
                    throw new HotelbedsValidationException("Hotelbeds: 'occupancies[{$index}].paxes[{$paxIndex}].type' deve essere 'AD' o 'CH'.");
                }

                if ($type === 'CH' && ! isset($pax['age'])) {
                    throw new HotelbedsValidationException("Hotelbeds: 'occupancies[{$index}].paxes[{$paxIndex}]' di tipo 'CH' richiede 'age'.");
                }
            }
        }

        return $occupancies;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     *
     * @throws HotelbedsValidationException
     */
    private function assertFilter(array $filter): array
    {
        $known = ['maxHotels', 'maxRooms', 'minRate', 'maxRate', 'maxRatesPerRoom', 'minCategory', 'maxCategory', 'contract'];
        foreach (array_keys($filter) as $key) {
            if (! in_array($key, $known, true)) {
                throw new HotelbedsValidationException("Hotelbeds: 'filter' contiene una chiave non supportata: '{$key}'.");
            }
        }

        if (isset($filter['maxHotels']) && ($filter['maxHotels'] < 1 || $filter['maxHotels'] > 2000)) {
            throw new HotelbedsValidationException("Hotelbeds: 'filter.maxHotels' deve essere fra 1 e 2000, ricevuto: {$filter['maxHotels']}.");
        }

        if (isset($filter['maxRooms']) && ($filter['maxRooms'] < 1 || $filter['maxRooms'] > 50)) {
            throw new HotelbedsValidationException("Hotelbeds: 'filter.maxRooms' deve essere fra 1 e 50, ricevuto: {$filter['maxRooms']}.");
        }

        foreach (['minCategory', 'maxCategory'] as $field) {
            if (isset($filter[$field]) && ($filter[$field] < 1 || $filter[$field] > 5)) {
                throw new HotelbedsValidationException("Hotelbeds: 'filter.{$field}' deve essere fra 1 e 5, ricevuto: {$filter[$field]}.");
            }
        }

        if (isset($filter['minCategory'], $filter['maxCategory']) && $filter['minCategory'] > $filter['maxCategory']) {
            throw new HotelbedsValidationException("Hotelbeds: 'filter.minCategory' non può essere maggiore di 'filter.maxCategory'.");
        }

        if (isset($filter['minRate'], $filter['maxRate']) && $filter['minRate'] > $filter['maxRate']) {
            throw new HotelbedsValidationException("Hotelbeds: 'filter.minRate' non può essere maggiore di 'filter.maxRate'.");
        }

        return $filter;
    }

    /**
     * @param  array<int, string>  $rateKeys
     * @return array<int, string>
     *
     * @throws HotelbedsValidationException
     */
    private function assertRateKeys(array $rateKeys): array
    {
        if ($rateKeys === []) {
            throw new HotelbedsValidationException("Hotelbeds: 'rateKeys' non può essere vuoto.");
        }

        foreach ($rateKeys as $index => $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new HotelbedsValidationException("Hotelbeds: ogni rateKey deve essere una stringa non vuota, ricevuto alla posizione {$index}: '{$key}'.");
            }
        }

        return $rateKeys;
    }

    // -------------------------------------------------------------------------
    // HTTP core
    // -------------------------------------------------------------------------

    /**
     * Esegue una richiesta e garantisce che il risultato decodificato sia un
     * array (oggetto JSON), lanciando un errore altrimenti.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $endpoint, ?array $body = null, array $query = []): array
    {
        $data = $this->rawRequest($method, $endpoint, $body, $query);

        if (! is_array($data)) {
            throw new HotelbedsApiException(
                "Hotelbeds: risposta inattesa per {$endpoint}: atteso un oggetto JSON.",
                errors: [],
                context: ['raw' => $data],
            );
        }

        return $data;
    }

    /**
     * Unico punto di uscita HTTP verso l'API Hotelbeds. Gestisce: header di
     * autenticazione (firma ricalcolata a ogni tentativo), retry sugli errori
     * transitori, decodifica JSON e traduzione degli errori (HTTP e
     * applicativi) nella gerarchia di eccezioni del namespace {@see Exceptions}.
     *
     * A differenza di {@see \App\Services\FlightX\FlightXClient::rawRequest()}
     * non c'è alcun re-login su 401: la firma è stateless e un 401 significa
     * apiKey/secret errati o clock desincronizzato, non un token da rinnovare.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws HotelbedsException
     */
    private function rawRequest(string $method, string $endpoint, ?array $body = null, array $query = []): mixed
    {
        return $this->decodeOrThrow($this->send($method, $endpoint, $body, $query), $endpoint);
    }

    /**
     * Costruisce ed esegue la richiesta HTTP, ritentando gli errori transitori
     * (connessione, 429, 5xx) fino a `$config->retries` volte in più. Un 4xx di
     * validazione (incluso 401) non viene mai ritentato. La firma è ricalcolata
     * a OGNI tentativo, non solo al primo, perché ha validità di pochi minuti.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     *
     * @throws HotelbedsConnectionException
     */
    private function send(string $method, string $endpoint, ?array $body, array $query): ResponseInterface
    {
        $maxAttempts = max(1, $this->config->retries + 1);
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $headers = $this->signatureHeaders();
            if (strtoupper($method) !== 'GET') {
                $headers['Content-Type'] = 'application/json';
            }

            $this->logRequest($method, $endpoint, $headers, $body, $query, $attempt);

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

                throw new HotelbedsConnectionException(
                    "Hotelbeds: errore di connessione verso {$endpoint}: {$e->getMessage()}",
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
        throw new HotelbedsConnectionException(
            "Hotelbeds: impossibile completare la richiesta verso {$endpoint}.",
            previous: $lastConnectionError,
        );
    }

    /**
     * Header di autenticazione HBX, ricalcolati a ogni chiamata a {@see self::send()}.
     *
     * La firma è `sha256(apiKey + secret + timestamp_unix_secondi)` in
     * esadecimale minuscolo, senza separatori fra i tre pezzi. Ha validità di
     * pochi minuti (verificato: accettata a 5 minuti, rifiutata con 401
     * "Request signature verification failed" a 15): per questo non viene mai
     * memorizzata, ma rigenerata per ciascun tentativo HTTP — così anche un
     * retry dopo `retryDelayMs` parte con una firma fresca.
     *
     * @return array<string, string>
     */
    private function signatureHeaders(): array
    {
        $timestamp = (string) time();

        return [
            'Accept' => 'application/json',
            'Api-key' => $this->config->apiKey,
            'X-Signature' => hash('sha256', $this->config->apiKey.$this->config->secret.$timestamp),
        ];
    }

    /**
     * Decodifica il corpo JSON della risposta e traduce sia gli errori HTTP sia
     * gli errori applicativi (chiave `error` con HTTP 200) nell'eccezione appropriata.
     *
     * @throws HotelbedsException
     */
    private function decodeOrThrow(ResponseInterface $response, string $endpoint): mixed
    {
        $rawBody = (string) $response->getBody();
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        if ($rawBody !== '' && json_last_error() !== JSON_ERROR_NONE) {
            // Corpo non-JSON: preservato per il debug.
            $decoded = ['rawText' => $rawBody];
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $detail = $this->extractErrorDetail($decoded, $rawBody);
            $context = is_array($decoded) ? $decoded : null;

            if ($status === 401 || $status === 403) {
                $message = "Hotelbeds [{$status}] {$endpoint}: {$detail}. Verificare apiKey/secret e la sincronizzazione dell'orologio di sistema (la firma include il timestamp Unix).";

                throw new HotelbedsAuthenticationException($message, $context);
            }

            $message = "Hotelbeds [{$status}] {$endpoint}: {$detail}";

            throw match (true) {
                $status === 429 => new HotelbedsRateLimitException(
                    $message,
                    $this->retryAfterFromHeader($response),
                    array_merge($context ?? [], $this->rateLimitHeaders($response)),
                ),
                $status >= 500 => new HotelbedsServerException($message, $context),
                default => new HotelbedsValidationException($message, $context),
            };
        }

        // Envelope applicativo: HTTP 200 non garantisce successo, va sempre
        // controllato anche il campo `error` del corpo risposta.
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            $errors = [[
                'code' => isset($error['code']) ? (string) $error['code'] : null,
                'text' => isset($error['message']) ? (string) $error['message'] : null,
            ]];

            $summary = (string) ($errors[0]['text'] ?? $errors[0]['code'] ?? '');

            throw new HotelbedsApiException(
                "Hotelbeds: {$endpoint} ha risposto con un errore applicativo".($summary !== '' ? " ({$summary})" : '.'),
                errors: $errors,
                context: $decoded,
            );
        }

        return $decoded;
    }

    private function extractErrorDetail(mixed $decoded, string $rawBody): string
    {
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error) && isset($error['message'])) {
                return (string) $error['message'];
            }

            return (string) ($decoded['message'] ?? (is_string($error) ? $error : null) ?? json_encode($decoded));
        }

        return $rawBody !== '' ? $rawBody : '(corpo risposta vuota)';
    }

    private function retryAfterFromHeader(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Retry-After');

        return $value !== '' && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function rateLimitHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach (['X-Ratelimit-Limit', 'X-Ratelimit-Remaining'] as $name) {
            $value = $response->getHeaderLine($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * Registra la richiesta su STDERR se `$config->logRequests` è attivo, oscurando
     * sempre le credenziali (ApiKey, Secret, X-Signature) sia negli header sia nel body.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     */
    private function logRequest(string $method, string $endpoint, array $headers, ?array $body, array $query, int $attempt): void
    {
        if (! $this->config->logRequests) {
            return;
        }

        fwrite(STDERR, '[Hotelbeds] ' . json_encode([
            'method' => $method,
            'endpoint' => $endpoint,
            'attempt' => $attempt,
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
        foreach (['apiKey', 'api_key', 'Api-key', 'Api-Key', 'secret', 'Secret', 'X-Signature', 'x-signature'] as $key) {
            if (array_key_exists($key, $redacted)) {
                $redacted[$key] = '***';
            }
        }

        return $redacted;
    }
}

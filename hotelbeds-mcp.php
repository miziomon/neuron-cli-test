<?php

declare(strict_types=1);

/**
 * Server MCP su stdio che espone la ricerca hotel (geocoding Nominatim +
 * disponibilità Hotelbeds) come tool JSON-RPC 2.0 newline-delimited,
 * compatibile con NeuronAI\MCP\McpConnector.
 *
 * Protocollo: una richiesta JSON per riga su STDIN, una risposta JSON per riga
 * su STDOUT. Ogni diagnostica va su STDERR: STDOUT è riservato al protocollo.
 *
 * Credenziali lette dalle variabili d'ambiente HOTELBEDS_BASE_URL,
 * HOTELBEDS_API_KEY, HOTELBEDS_SECRET, NOMINATIM_USER_AGENT e opzionalmente
 * NOMINATIM_BASE_URL / NOMINATIM_EMAIL (passate dal connettore MCP dell'agente).
 */

use App\Services\Geocoding\NominatimClient;
use App\Services\Geocoding\NominatimConfig;
use App\Services\Hotelbeds\HotelbedsClient;
use App\Services\Hotelbeds\HotelbedsConfig;

require __DIR__ . '/vendor/autoload.php';

// I client sono stateless (firma ricalcolata a ogni richiesta): un'istanza per processo.
$nominatim = null;
$geocoder = static function () use (&$nominatim): NominatimClient {
    return $nominatim ??= new NominatimClient(NominatimConfig::fromArray([
        'base_url' => getenv('NOMINATIM_BASE_URL') ?: 'https://nominatim.openstreetmap.org',
        'user_agent' => getenv('NOMINATIM_USER_AGENT') ?: '',
        'email' => getenv('NOMINATIM_EMAIL') ?: null,
        'accept_language' => 'it',
    ]));
};

$hotelbeds = null;
$hotels = static function () use (&$hotelbeds): HotelbedsClient {
    return $hotelbeds ??= new HotelbedsClient(HotelbedsConfig::fromArray([
        'base_url' => getenv('HOTELBEDS_BASE_URL') ?: '',
        'api_key' => getenv('HOTELBEDS_API_KEY') ?: '',
        'secret' => getenv('HOTELBEDS_SECRET') ?: '',
    ]));
};

$schemi = [
    'cerca_hotel' => [
        'name' => 'cerca_hotel',
        'description' => 'Geocodifica una località (città o indirizzo) e cerca gli hotel disponibili '
            . 'nel raggio di 20 km, restituendo un elenco leggibile delle opzioni (nome, categoria, '
            . 'prezzo totale del soggiorno, trattamento). Nessuna prenotazione viene effettuata.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'destinazione' => ['type' => 'string', 'description' => 'Nome della località da geocodificare (es. "Barcellona" o "Barcellona, Spagna")'],
                'check_in' => ['type' => 'string', 'description' => 'Data di check-in YYYY-MM-DD, non nel passato'],
                'check_out' => ['type' => 'string', 'description' => 'Data di check-out YYYY-MM-DD, successiva al check-in'],
                'adulti' => ['type' => 'integer', 'description' => 'Numero di adulti per camera (default 2)'],
                'bambini' => ['type' => 'integer', 'description' => 'Numero di bambini per camera (default 0)'],
                'eta_bambini' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Età (0-17) di ciascun bambino: obbligatoria se bambini > 0, tanti valori quanti sono i bambini'],
                'camere' => ['type' => 'integer', 'description' => 'Numero di camere (default 1)'],
            ],
            'required' => ['destinazione', 'check_in', 'check_out'],
        ],
    ],
];

/** Converte il codice categoria HBX (es. "4EST", "1_2EST") in una dicitura leggibile. */
$categoria = static function (?string $codice): string {
    if ($codice === null || $codice === '') {
        return '';
    }
    if (preg_match('/^(\d)_(\d)EST$/', $codice, $m) === 1) {
        return "{$m[1]}-{$m[2]} stelle";
    }
    if (preg_match('/^(\d)EST$/', $codice, $m) === 1) {
        return "{$m[1]} " . ((int) $m[1] === 1 ? 'stella' : 'stelle');
    }

    return $codice;
};

/** Formatta i risultati di searchByGeolocation() come elenco leggibile (max 5 opzioni). */
$formattaHotel = static function (array $risultato, string $localita) use ($categoria): string {
    $elenco = $risultato['hotels']['hotels'] ?? [];
    if (!is_array($elenco) || $elenco === []) {
        return "Nessun hotel disponibile a {$localita} per le date e i partecipanti indicati.";
    }

    $righe = [];
    foreach (array_slice($elenco, 0, 5) as $indice => $hotel) {
        $hotel = (array) $hotel;
        $nome = (string) ($hotel['name'] ?? '(nome non disponibile)');
        $stelle = $categoria(isset($hotel['categoryCode']) ? (string) $hotel['categoryCode'] : null);

        // Prezzo minimo fra le tariffe delle camere (gli importi HBX sono stringhe)
        $prezzoMin = null;
        $trattamenti = [];
        foreach ((array) ($hotel['rooms'] ?? []) as $camera) {
            foreach ((array) ($camera['rates'] ?? []) as $tariffa) {
                $tariffa = (array) $tariffa;
                $netto = $tariffa['sellingRate'] ?? $tariffa['net'] ?? null;
                if (is_numeric($netto)) {
                    $prezzoMin = $prezzoMin === null ? (float) $netto : min($prezzoMin, (float) $netto);
                }
                if (isset($tariffa['boardName'])) {
                    $trattamenti[(string) $tariffa['boardName']] = true;
                }
            }
        }
        $valuta = (string) ($hotel['currency'] ?? 'EUR');

        $dettagli = array_filter([
            $stelle !== '' ? $stelle : null,
            $prezzoMin !== null
                ? 'da ' . number_format($prezzoMin, 2, ',', '') . " {$valuta} per l'intero soggiorno"
                : 'prezzo n/d',
            $trattamenti !== [] ? 'trattamento: ' . implode(', ', array_keys($trattamenti)) : null,
        ]);

        $righe[] = sprintf('%d) %s', $indice + 1, $nome) . ($dettagli !== [] ? "\n   " . implode(' · ', $dettagli) : '');
    }

    $totale = count($elenco);

    return "Hotel trovati a {$localita}: {$totale} (prime " . count($righe) . " opzioni, ordinate dal fornitore):\n\n"
        . implode("\n\n", $righe)
        . "\n\nPrezzi in EUR per l'intero soggiorno, ricercati il " . date('d/m/Y \a\l\l\e H:i')
        . ": possono variare fino al momento della prenotazione.";
};

/** Geocodifica la destinazione e cerca gli hotel disponibili nelle vicinanze. */
$cercaHotel = static function (array $args) use ($geocoder, $hotels, $formattaHotel): string {
    $destinazione = trim((string) ($args['destinazione'] ?? ''));

    $punto = $geocoder()->geocode($destinazione);
    if ($punto === null) {
        return "La località \"{$destinazione}\" non è stata trovata dal servizio di geocodifica: "
            . "prova con un nome più specifico (es. aggiungendo la nazione).";
    }

    $bambini = (int) ($args['bambini'] ?? 0);
    $risultato = $hotels()->searchByGeolocation(
        latitude: $punto['latitude'],
        longitude: $punto['longitude'],
        checkIn: (string) ($args['check_in'] ?? ''),
        checkOut: (string) ($args['check_out'] ?? ''),
        radius: 20,
        rooms: max(1, (int) ($args['camere'] ?? 1)),
        adults: (int) ($args['adulti'] ?? 2),
        children: $bambini,
        childrenAges: $bambini > 0 ? array_map('intval', (array) ($args['eta_bambini'] ?? [])) : [],
        filter: ['maxHotels' => 25],
    );

    return $formattaHotel($risultato, $punto['displayName'] !== '' ? $punto['displayName'] : $destinazione);
};

$rispondi = static function (mixed $id, mixed $result): void {
    fwrite(STDOUT, json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
};

$errore = static function (mixed $id, int $codice, string $messaggio): void {
    fwrite(STDOUT, json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => $codice, 'message' => $messaggio],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
};

while (($linea = fgets(STDIN)) !== false) {
    $linea = trim($linea);
    if ($linea === '') {
        continue;
    }

    $richiesta = json_decode($linea, true);
    if (!is_array($richiesta)) {
        $errore(null, -32700, 'JSON non valido');
        continue;
    }

    $id = $richiesta['id'] ?? null;
    $metodo = (string) ($richiesta['method'] ?? '');

    switch ($metodo) {
        case 'initialize':
            $rispondi($id, [
                'protocolVersion' => $richiesta['params']['protocolVersion'] ?? '2024-11-05',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'hotelbeds-mcp', 'version' => '1.0.0'],
            ]);
            break;

        case 'notifications/initialized':
            // Notifica senza risposta.
            break;

        case 'ping':
            $rispondi($id, (object) []);
            break;

        case 'tools/list':
            $rispondi($id, ['tools' => array_values($schemi)]);
            break;

        case 'tools/call':
            $nome = (string) ($richiesta['params']['name'] ?? '');
            $argomenti = (array) ($richiesta['params']['arguments'] ?? []);
            try {
                $testo = match ($nome) {
                    'cerca_hotel' => $cercaHotel($argomenti),
                    default => throw new RuntimeException("Tool sconosciuto: {$nome}"),
                };
                $rispondi($id, ['content' => [['type' => 'text', 'text' => $testo]], 'isError' => false]);
            } catch (Throwable $e) {
                $rispondi($id, ['content' => [['type' => 'text', 'text' => "Errore ricerca hotel: {$e->getMessage()}"]], 'isError' => true]);
            }
            break;

        default:
            if ($id !== null) {
                $errore($id, -32601, "Metodo non supportato: {$metodo}");
            }
    }
}

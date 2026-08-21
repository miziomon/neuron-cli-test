<?php

declare(strict_types=1);

/**
 * Server MCP su stdio che espone i servizi FlightX (ricerca e selezione voli)
 * come tool JSON-RPC 2.0 newline-delimited, compatibile con NeuronAI\MCP\McpConnector.
 *
 * Protocollo: una richiesta JSON per riga su STDIN, una risposta JSON per riga su
 * STDOUT. Ogni diagnostica va su STDERR: STDOUT è riservato al protocollo.
 *
 * Credenziali lette dalle variabili d'ambiente FLIGHTX_BASE_URL, FLIGHTX_API_KEY,
 * FLIGHTX_USERNAME, FLIGHTX_PASSWORD_MD5 (passate dal connettore MCP dell'agente).
 */

use App\Services\FlightX\FlightXClient;
use App\Services\FlightX\FlightXConfig;

require __DIR__ . '/vendor/autoload.php';

// Il client è stateful (token JWT + ultima ricerca): un'unica istanza per processo.
$client = null;
$cliente = static function () use (&$client): FlightXClient {
    return $client ??= new FlightXClient(FlightXConfig::fromArray([
        'base_url' => getenv('FLIGHTX_BASE_URL') ?: '',
        'api_key' => getenv('FLIGHTX_API_KEY') ?: '',
        'username' => getenv('FLIGHTX_USERNAME') ?: '',
        'password_md5' => getenv('FLIGHTX_PASSWORD_MD5') ?: null,
    ]));
};

$schemi = [
    'cerca_voli' => [
        'name' => 'cerca_voli',
        'description' => 'Cerca voli disponibili su FlightX e restituisce un elenco leggibile delle opzioni '
            . '(compagnie, orari, scali, prezzo), con i riferimenti tecnici per una eventuale selezione.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'departure_airport' => ['type' => 'string', 'description' => 'Codice IATA aeroporto di partenza (3 lettere, es. LIN)'],
                'arrival_airport' => ['type' => 'string', 'description' => 'Codice IATA aeroporto di destinazione (3 lettere, es. BCN)'],
                'departure_date' => ['type' => 'string', 'description' => 'Data di partenza YYYY-MM-DD'],
                'return_date' => ['type' => 'string', 'description' => 'Data di ritorno YYYY-MM-DD (solo per andata e ritorno)'],
                'adults' => ['type' => 'integer', 'description' => 'Numero di adulti (default 1)'],
                'children' => ['type' => 'integer', 'description' => 'Numero di bambini 2-11 anni (default 0)'],
                'infants' => ['type' => 'integer', 'description' => 'Numero di neonati fino a 2 anni (default 0)'],
                'search_type' => ['type' => 'string', 'enum' => ['OW', 'RT'], 'description' => 'OW = sola andata, RT = andata e ritorno (default OW)'],
            ],
            'required' => ['departure_airport', 'arrival_airport', 'departure_date'],
        ],
    ],
    'seleziona_volo' => [
        'name' => 'seleziona_volo',
        'description' => 'Verifica disponibilità e prezzo di una delle opzioni trovate con cerca_voli '
            . 'e crea un dossier temporaneo (24 ore). NON effettua alcuna prenotazione.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'item_id' => ['type' => 'string', 'description' => 'Riferimento opzione nella forma "<ItemId>_<OptionListId>" (es. "1_1")'],
                'item_key' => ['type' => 'string', 'description' => 'ItemKey dell\'opzione, restituito da cerca_voli'],
                'option_keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'OptionKey dell\'opzione scelta'],
                'adults' => ['type' => 'integer', 'description' => 'Numero di adulti (deve coincidere con la ricerca)'],
                'children' => ['type' => 'integer', 'description' => 'Numero di bambini (default 0)'],
                'infants' => ['type' => 'integer', 'description' => 'Numero di neonati (default 0)'],
            ],
            'required' => ['item_id', 'item_key', 'option_keys', 'adults'],
        ],
    ],
];

/** Estrae il primo valore presente tra una serie di chiavi possibili. */
$pick = static fn(array $dati, string ...$chiavi): mixed
    => current(array_filter(array_map(static fn(string $k): mixed => $dati[$k] ?? null, $chiavi), static fn(mixed $v): bool => $v !== null && $v !== ''));

/** Formatta una data/ora ISO dell'API come "dd/mm HH:MM". */
$ora = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '?';
    }
    $dt = date_create($iso);

    return $dt === false ? $iso : $dt->format('d/m H:i');
};

/** Formatta una durata in minuti come "1h 40m". */
$durata = static fn(mixed $minuti): string
    => is_numeric($minuti) ? intdiv((int) $minuti, 60) . 'h' . str_pad((string) ((int) $minuti % 60), 2, '0', STR_PAD_LEFT) . 'm' : '';

/** Formatta i risultati di searchFlights() come elenco leggibile (max 5 opzioni). */
$formattaVoli = static function (array $risultato) use ($pick, $ora, $durata): string {
    $items = $risultato['Result']['Items'] ?? [];
    if (!is_array($items) || $items === []) {
        return "Nessun volo trovato per i parametri indicati.";
    }

    $righe = [];
    foreach (array_slice($items, 0, 5) as $indice => $item) {
        $listOption = $item['ListOptions'][0] ?? [];
        $option = $listOption['Options'][0] ?? [];
        $voli = $option['Flights'] ?? [];

        $tratte = [];
        $bagaglio = null;
        foreach ((array) $voli as $volo) {
            $volo = (array) $volo;
            $da = $pick($volo, 'FromIATA', 'DepartureAirport', 'From');
            $a = $pick($volo, 'ToIATA', 'ArrivalAirport', 'To');
            $partenza = $ora($pick($volo, 'DepartureDate', 'DepartureDateTime', 'Depart'));
            $arrivo = $ora($pick($volo, 'ArrivalDate', 'ArrivalDateTime', 'Arrive'));
            $compagnia = $pick($volo, 'MarketingCompanyDescription', 'AirlineName', 'OperatingCompany');
            $numero = $pick($volo, 'FlightNumber', 'FlightId');
            $bagaglio ??= $pick($volo, 'FreeBaggageCoverage');
            $tratte[] = trim("{$da} → {$a} {$partenza}–{$arrivo}"
                . ($compagnia !== null ? " · {$compagnia}" : '')
                . ($numero !== null ? " {$numero}" : ''));
        }

        $prezzi = (array) ($item['Prices'] ?? []);
        $totale = $pick($prezzi, 'Total', 'TotalPrice', 'Amount');
        $perPersona = $pick($prezzi, 'PricePerPassenger');
        $scali = $pick((array) $option, 'Stops') ?? max(0, count($tratte) - 1);
        $durataOpzione = $durata($pick((array) $option, 'TotalDuration'));

        $itemId = $pick((array) $item, 'ItemId') ?? $indice + 1;
        $optionListId = $pick((array) $listOption, 'OptionListId') ?? '1';
        $itemKey = $pick((array) $item, 'ItemKey') ?? '';
        $optionKey = $pick((array) $option, 'OptionKey') ?? '';

        $dettagli = array_filter([
            ((int) $scali) === 0 ? 'diretto' : "{$scali} " . ((int) $scali === 1 ? 'scalo' : 'scali'),
            $durataOpzione !== '' ? "durata {$durataOpzione}" : null,
            $totale !== null ? number_format((float) $totale, 2, ',', '') . ' EUR'
                . ($perPersona !== null ? ' (' . number_format((float) $perPersona, 2, ',', '') . ' a persona)' : '')
                : 'prezzo n/d',
            $bagaglio,
        ]);

        $righe[] = sprintf(
            "%d) %s\n   %s\n   Riferimenti per la selezione: item_id \"%s_%s\", item_key \"%s\", option_keys [\"%s\"]",
            $indice + 1,
            $tratte !== [] ? implode(' + ', $tratte) : '(dettagli tratta non disponibili)',
            implode(' · ', $dettagli),
            $itemId,
            $optionListId,
            $itemKey,
            $optionKey,
        );
    }

    $totale = count($items);

    return "Voli trovati: {$totale} (prime " . count($righe) . " opzioni):\n\n" . implode("\n\n", $righe)
        . "\n\nPrezzi in EUR, ricercati il " . date('d/m/Y \a\l\l\e H:i') . ": possono variare fino al momento della prenotazione.";
};

/** Esegue la ricerca voli e ne formatta il risultato. */
$cercaVoli = static function (array $args) use ($cliente, $formattaVoli): string {
    $risultato = $cliente()->searchFlights(
        departureAirport: (string) ($args['departure_airport'] ?? ''),
        arrivalAirport: (string) ($args['arrival_airport'] ?? ''),
        departureDate: (string) ($args['departure_date'] ?? ''),
        returnDate: $args['return_date'] ?? null,
        adults: (int) ($args['adults'] ?? 1),
        children: (int) ($args['children'] ?? 0),
        infants: (int) ($args['infants'] ?? 0),
        searchType: (string) ($args['search_type'] ?? 'OW'),
    );

    return $formattaVoli($risultato);
};

/** Seleziona un'opzione di volo e restituisce l'esito leggibile. */
$selezionaVolo = static function (array $args) use ($cliente): string {
    $risultato = $cliente()->selectFlightOption(
        itemId: (string) ($args['item_id'] ?? ''),
        itemKey: (string) ($args['item_key'] ?? ''),
        optionKeys: array_map('strval', (array) ($args['option_keys'] ?? [])),
        adults: (int) ($args['adults'] ?? 1),
        children: (int) ($args['children'] ?? 0),
        infants: (int) ($args['infants'] ?? 0),
    );

    $dossierId = $risultato['Result']['VirtualDossierId']
        ?? $risultato['Result']['DossierId']
        ?? $risultato['VirtualDossierId']
        ?? null;

    return "Opzione verificata e disponibile."
        . ($dossierId !== null ? " Dossier temporaneo creato (valido 24 ore): {$dossierId}." : '')
        . " Nessuna prenotazione è stata effettuata.";
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
                'serverInfo' => ['name' => 'flightx-mcp', 'version' => '1.0.0'],
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
                    'cerca_voli' => $cercaVoli($argomenti),
                    'seleziona_volo' => $selezionaVolo($argomenti),
                    default => throw new RuntimeException("Tool sconosciuto: {$nome}"),
                };
                $rispondi($id, ['content' => [['type' => 'text', 'text' => $testo]], 'isError' => false]);
            } catch (Throwable $e) {
                $rispondi($id, ['content' => [['type' => 'text', 'text' => "Errore FlightX: {$e->getMessage()}"]], 'isError' => true]);
            }
            break;

        default:
            if ($id !== null) {
                $errore($id, -32601, "Metodo non supportato: {$metodo}");
            }
    }
}

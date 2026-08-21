<?php

declare(strict_types=1);

use App\Neuron\HotelAgent;
use App\Neuron\ReceptionistAgent;
use App\Neuron\TurnoHotel;
use App\Neuron\TurnoReceptionist;
use App\Neuron\TurnoVolo;
use App\Neuron\VoliAgent;
use App\Support\Pratica;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\Usage;

require __DIR__ . '/vendor/autoload.php';

// Caricamento minimale del file .env (le variabili d'ambiente reali hanno precedenza)
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $env = getenv($key);
    $_ENV[$key] = $env !== false ? $env : trim(trim($value), "\"'");
}

if (empty($_ENV['OPENROUTER_API_KEY']) || empty($_ENV['OPENROUTER_MODEL'])) {
    fwrite(STDERR, "Errore: OPENROUTER_API_KEY e OPENROUTER_MODEL devono essere definite nel file .env\n");
    exit(1);
}

// Colori ANSI (disattivabili con NO_COLOR=1)
if (function_exists('sapi_windows_vt100_support')) {
    @sapi_windows_vt100_support(STDOUT);
}
$colori = getenv('NO_COLOR') === false;
$c = static fn(string $codice, string $testo): string => $colori ? "\033[{$codice}m{$testo}\033[0m" : $testo;
$ciano = static fn(string $t): string => $c('36', $t);   // battute dell'agente
$grigio = static fn(string $t): string => $c('90', $t);  // conteggio token
$verde = static fn(string $t): string => $c('32', $t);   // messaggi finali

// Numero massimo di turni dell'agente prima della chiusura automatica (vale per ogni fase)
$maxIterazioni = max(1, (int) ($_ENV['MAX_ITERAZIONI'] ?? 6));

// Nome, cognome e destinazione devono essere valori reali: solo lettere, spazi, apostrofi e trattini
$soloLettere = static fn(?string $valore): bool =>
    $valore !== null && preg_match('/^[\p{L}][\p{L}\s\'-]*$/u', trim($valore)) === 1;

/**
 * Esegue una fase di raccolta dati con un agente: kickoff, loop di conversazione,
 * conteggio token, retry su rate limit e limite di iterazioni (riparte sempre da #1).
 *
 * Restituisce il record raccolto, oppure null se l'utente ha rifiutato di fornire i dati.
 *
 * Se l'agente conferma ma alcuni dati NON SUPERANO la validazione (campi valorizzati
 * ma malformati, es. email senza chiocciola o IATA non di 3 lettere), la closure
 * $erroriValidazione elenca i problemi: la fase NON termina, gli errori vengono
 * rimandati all'agente che chiede la correzione e poi una nuova conferma.
 *
 * Se è fornita $mostraRiepilogo, l'input "riepilogo" (o "servizi") stampa lo stato
 * della pratica SENZA chiamare il modello e senza consumare iterazioni.
 */
$eseguiFase = static function (
    Agent $agent,
    string $classeTurno,
    Closure $datiCompleti,
    Closure $estraiRecord,
    string $messaggioAvvio = 'Ciao!',
    ?Closure $erroriValidazione = null,
    ?Closure $mostraRiepilogo = null,
) use ($ciano, $grigio, $maxIterazioni): ?array {
    // Esegue un turno strutturato con retry in caso di rate limit (429)
    $turno = static function (string $input) use ($agent, $classeTurno): object {
        $attesa = 5;
        for ($tentativo = 1; $tentativo <= 4; $tentativo++) {
            try {
                return $agent->structured(new UserMessage($input), $classeTurno);
            } catch (Throwable $e) {
                if ($tentativo === 4 || !str_contains($e->getMessage(), '429')) {
                    fwrite(STDERR, "Errore di comunicazione con il modello: {$e->getMessage()}\n");
                    exit(1);
                }
                echo "(il modello è momentaneamente occupato, riprovo tra {$attesa} secondi...)\n";
                sleep($attesa);
                $attesa *= 2;
            }
        }
    };

    // Messaggio di avvio: l'agente inizia la raccolta dei dati
    $turnoAgente = $turno($messaggioAvvio);
    $iterazione = 0;

    while (true) {
        $iterazione++;
        echo $ciano("#{$iterazione} Neuron: {$turnoAgente->risposta}") . "\n";

        // Conteggio token del turno appena concluso
        $usage = $agent->resolveState()->getChatHistory()->getLastMessage()->getUsage();
        if ($usage instanceof Usage) {
            echo $grigio("   [token: in {$usage->inputTokens} · out {$usage->outputTokens} · totale {$usage->getTotal()}]") . "\n";
        }
        echo "\n";

        if ($turnoAgente->confermato) {
            if ($datiCompleti($turnoAgente)) {
                return $estraiRecord($turnoAgente);
            }

            // Campi valorizzati ma non validi: la fase continua chiedendo la correzione
            $errori = $erroriValidazione !== null ? $erroriValidazione($turnoAgente) : [];
            if ($errori !== []) {
                echo $grigio("   (alcuni dati non sono validi, chiedo la correzione...)") . "\n\n";
                $turnoAgente = $turno(
                    "Attenzione, questi dati che ho raccolto non sono validi: " . implode('; ', $errori)
                    . ". Chiedimi gentilmente di correggerli, aggiorna i campi corrispondenti e poi chiedimi di nuovo conferma del ricapitolo completo."
                );
                continue;
            }

            // Nessun dato raccolto o solo campi mancanti: rifiuto dell'utente
            return null;
        }

        if ($iterazione >= $maxIterazioni) {
            echo "Neuron: Purtroppo è stato raggiunto il numero massimo di interazioni e devo chiudere la conversazione. Ti auguro buona giornata!\n";
            exit(2);
        }

        echo "Tu: ";
        while (true) {
            $input = '';
            while ($input === '') {
                $riga = fgets(STDIN);
                if ($riga === false) {
                    echo "\nInput terminato, la chat si chiude.\n";
                    exit(0);
                }
                $input = trim($riga);
            }
            if (in_array(strtolower($input), ['esci', 'exit', 'quit'], true)) {
                echo "Alla prossima!\n";
                exit(0);
            }

            // Comando locale: mostra i servizi selezionati senza chiamare il modello
            if ($mostraRiepilogo !== null && in_array(strtolower($input), ['riepilogo', 'servizi'], true)) {
                $mostraRiepilogo();
                echo "Tu: ";
                continue;
            }

            break;
        }

        $turnoAgente = $turno($input);
    }
};

// Codici IATA di 3 lettere e date in formato YYYY-MM-DD
$iataValido = static fn(?string $valore): bool =>
    $valore !== null && preg_match('/^[A-Za-z]{3}$/', trim($valore)) === 1;
$dataValida = static function (?string $valore): bool {
    if ($valore === null) {
        return false;
    }
    $data = DateTime::createFromFormat('Y-m-d', trim($valore));

    return $data !== false && $data->format('Y-m-d') === trim($valore);
};
// Il modello a volte restituisce "" invece di null per i campi opzionali
$vuoto = static fn(?string $valore): bool => $valore === null || trim($valore) === '';

// Stato condiviso della pratica: alimentato dopo ogni fase, letto dal comando "riepilogo"
$stato = [
    'utente' => null,
    'viaggio' => null,
    'volo_selezionato' => null,
    'hotel_selezionato' => null,
];

/** Stampa i dati raccolti e i servizi selezionati finora (comando "riepilogo"). */
$mostraRiepilogo = static function () use (&$stato, $ciano, $grigio, $verde): void {
    echo "\n" . $verde("--- Riepilogo pratica ---") . "\n";
    if ($stato['utente'] === null) {
        echo $grigio("Nessun dato raccolto finora.") . "\n\n";

        return;
    }

    $u = $stato['utente'];
    $v = $stato['viaggio'];
    echo $ciano("Anagrafica: {$u['nome']} {$u['cognome']} ({$u['email']})") . "\n";
    $passeggeri = "{$v['adulti']} " . ($v['adulti'] === 1 ? 'adulto' : 'adulti')
        . ($v['bambini'] > 0 ? " e {$v['bambini']} " . ($v['bambini'] === 1 ? 'bambino' : 'bambini') : '');
    $date = $v['data_partenza'] . ($v['data_ritorno'] !== null ? " → ritorno {$v['data_ritorno']}" : ', sola andata');
    echo $ciano("Viaggio: {$v['aeroporto_partenza']} → {$v['aeroporto_destinazione']} ({$v['destinazione']}), {$date}, {$passeggeri}") . "\n";
    echo $ciano("Volo: " . ($stato['volo_selezionato']['descrizione'] ?? 'nessuna selezione')) . "\n";
    echo $ciano("Hotel: " . ($stato['hotel_selezionato']['descrizione'] ?? 'nessuna selezione')) . "\n";
    echo "\n";
};

// --- Fase 1: receptionist (anagrafica + viaggio + parametri di ricerca voli) ---

$datiCompletiReceptionist = static function (TurnoReceptionist $t) use ($soloLettere, $iataValido, $dataValida, $vuoto): bool {
    $ritorno = $vuoto($t->dataRitorno) ? null : trim($t->dataRitorno);

    return $soloLettere($t->nome)
        && $soloLettere($t->cognome)
        && $t->email !== null && filter_var(trim($t->email), FILTER_VALIDATE_EMAIL) !== false
        && $soloLettere($t->destinazione)
        && $iataValido($t->aeroportoPartenza)
        && $iataValido($t->aeroportoDestinazione)
        && $dataValida($t->dataPartenza)
        && ($ritorno === null || ($dataValida($ritorno) && $ritorno >= trim($t->dataPartenza)))
        && $t->adulti !== null && $t->adulti >= 1
        && $t->bambini !== null && $t->bambini >= 0
        && ($t->adulti + $t->bambini) <= 9;
};

// Elenca solo i campi VALORIZZATI ma non validi: i campi ancora null non contano
$erroriReceptionist = static function (TurnoReceptionist $t) use ($soloLettere, $iataValido, $dataValida, $vuoto): array {
    $errori = [];
    if (!$vuoto($t->nome) && !$soloLettere($t->nome)) {
        $errori[] = "il nome \"{$t->nome}\" contiene caratteri non ammessi (solo lettere)";
    }
    if (!$vuoto($t->cognome) && !$soloLettere($t->cognome)) {
        $errori[] = "il cognome \"{$t->cognome}\" contiene caratteri non ammessi (solo lettere)";
    }
    if (!$vuoto($t->email) && filter_var(trim($t->email), FILTER_VALIDATE_EMAIL) === false) {
        $errori[] = "l'email \"{$t->email}\" non è un indirizzo valido";
    }
    if (!$vuoto($t->destinazione) && !$soloLettere($t->destinazione)) {
        $errori[] = "la destinazione \"{$t->destinazione}\" contiene caratteri non ammessi (solo lettere: indica la città, non il codice aeroporto)";
    }
    if (!$vuoto($t->aeroportoPartenza) && !$iataValido($t->aeroportoPartenza)) {
        $errori[] = "l'aeroporto di partenza \"{$t->aeroportoPartenza}\" non è un codice IATA di 3 lettere";
    }
    if (!$vuoto($t->aeroportoDestinazione) && !$iataValido($t->aeroportoDestinazione)) {
        $errori[] = "l'aeroporto di destinazione \"{$t->aeroportoDestinazione}\" non è un codice IATA di 3 lettere";
    }
    if (!$vuoto($t->dataPartenza) && !$dataValida($t->dataPartenza)) {
        $errori[] = "la data di partenza \"{$t->dataPartenza}\" non è nel formato YYYY-MM-DD";
    }
    if (!$vuoto($t->dataRitorno)) {
        if (!$dataValida($t->dataRitorno)) {
            $errori[] = "la data di ritorno \"{$t->dataRitorno}\" non è nel formato YYYY-MM-DD";
        } elseif ($dataValida($t->dataPartenza) && trim($t->dataRitorno) < trim($t->dataPartenza)) {
            $errori[] = "la data di ritorno precede la partenza";
        }
    }
    if ($t->adulti !== null && $t->adulti < 1) {
        $errori[] = "deve esserci almeno 1 adulto";
    }
    if ($t->bambini !== null && $t->bambini < 0) {
        $errori[] = "il numero di bambini non può essere negativo";
    }
    if ($t->adulti !== null && $t->bambini !== null && ($t->adulti + $t->bambini) > 9) {
        $errori[] = "i passeggeri totali non possono superare 9";
    }

    return $errori;
};

$dati = $eseguiFase(
    ReceptionistAgent::make(),
    TurnoReceptionist::class,
    datiCompleti: $datiCompletiReceptionist,
    estraiRecord: static fn(TurnoReceptionist $t): array => [
        'nome' => trim($t->nome),
        'cognome' => trim($t->cognome),
        'email' => trim($t->email),
        'destinazione' => trim($t->destinazione),
        'aeroporto_partenza' => strtoupper(trim($t->aeroportoPartenza)),
        'aeroporto_destinazione' => strtoupper(trim($t->aeroportoDestinazione)),
        'data_partenza' => trim($t->dataPartenza),
        'data_ritorno' => $vuoto($t->dataRitorno) ? null : trim($t->dataRitorno),
        'adulti' => $t->adulti,
        'bambini' => $t->bambini,
    ],
    erroriValidazione: $erroriReceptionist,
    mostraRiepilogo: $mostraRiepilogo,
);

if ($dati === null) {
    echo "Va bene, nessun dato salvato. Alla prossima!\n";
    exit(0);
}

$utente = [
    'nome' => $dati['nome'],
    'cognome' => $dati['cognome'],
    'email' => $dati['email'],
];
// Il viaggio è collegato all'utente tramite l'email
$viaggio = [
    'email' => $dati['email'],
    'destinazione' => $dati['destinazione'],
    'aeroporto_partenza' => $dati['aeroporto_partenza'],
    'aeroporto_destinazione' => $dati['aeroporto_destinazione'],
    'data_partenza' => $dati['data_partenza'],
    'data_ritorno' => $dati['data_ritorno'],
    'adulti' => $dati['adulti'],
    'bambini' => $dati['bambini'],
];

// Un file per utente con tutte le info: anagrafica, viaggio e selezioni (volo, hotel)
$stato['utente'] = $utente;
$stato['viaggio'] = $viaggio;
$pratica = Pratica::crea(__DIR__ . '/data', [
    'utente' => $utente,
    'viaggio' => $viaggio,
    'volo_selezionato' => null,
    'hotel_selezionato' => null,
]);
echo $verde("Neuron: Grazie {$utente['nome']} {$utente['cognome']}, ti ringrazio per avermi dato queste informazioni.") . "\n";
echo $grigio("(pratica salvata in {$pratica->percorso()}; digita \"riepilogo\" in qualsiasi momento per vedere i servizi selezionati)") . "\n\n";

// --- Fase 2: ricerca e scelta dei voli tramite il server MCP FlightX ---

$ricercaVoli = $eseguiFase(
    VoliAgent::make($viaggio),
    TurnoVolo::class,
    datiCompleti: static fn(TurnoVolo $t): bool => $t->ricercaCompletata,
    estraiRecord: static fn(TurnoVolo $t): array => [
        'volo_selezionato' => $t->voloSelezionato !== null ? trim($t->voloSelezionato) : null,
    ],
    messaggioAvvio: 'Procediamo con la ricerca dei voli.',
    mostraRiepilogo: $mostraRiepilogo,
);

if ($ricercaVoli === null) {
    echo "Va bene, nessuna ricerca effettuata.\n";
} elseif ($ricercaVoli['volo_selezionato'] !== null && $ricercaVoli['volo_selezionato'] !== '') {
    $stato['volo_selezionato'] = [
        'descrizione' => $ricercaVoli['volo_selezionato'],
        'selezionato_il' => date(DATE_ATOM),
    ];
    $pratica->aggiorna(['volo_selezionato' => $stato['volo_selezionato']]);
    echo $grigio("(volo selezionato aggiunto alla pratica)") . "\n";
}

// --- Fase 3: ricerca e scelta dell'hotel tramite il server MCP Hotelbeds ---

// Le età dei bambini sono obbligatorie per l'API Hotelbeds: tante età quanti sono i bambini
$parseEtaBambini = static function (?string $valore): array {
    if ($valore === null || trim($valore) === '') {
        return [];
    }

    return array_values(array_map('intval', preg_split('/\s*,\s*/', trim($valore)) ?: []));
};

$datiCompletiHotel = static function (TurnoHotel $t) use ($dataValida, $vuoto, $parseEtaBambini, $viaggio): bool {
    if ($t->hotelRichiesto === false) {
        return true; // nessun hotel: la fase è conclusa senza selezione
    }
    if ($t->hotelSelezionato === null || trim($t->hotelSelezionato) === '') {
        return false;
    }

    $checkOut = $vuoto($t->dataCheckOut) ? null : trim($t->dataCheckOut);
    $eta = $parseEtaBambini($t->etaBambini);

    return $dataValida($t->dataCheckIn)
        && $checkOut !== null && $dataValida($checkOut) && $checkOut > trim($t->dataCheckIn)
        && count($eta) === $viaggio['bambini'];
};

// Errori solo sui campi VALORIZZATI ma non validi (la rinuncia non genera errori)
$erroriHotel = static function (TurnoHotel $t) use ($dataValida, $vuoto, $parseEtaBambini, $viaggio): array {
    if ($t->hotelSelezionato === null || trim($t->hotelSelezionato) === '') {
        return [];
    }

    $errori = [];
    if (!$dataValida($t->dataCheckIn)) {
        $errori[] = "la data di check-in \"{$t->dataCheckIn}\" non è nel formato YYYY-MM-DD";
    }
    if (!$vuoto($t->dataCheckOut)) {
        if (!$dataValida($t->dataCheckOut)) {
            $errori[] = "la data di check-out \"{$t->dataCheckOut}\" non è nel formato YYYY-MM-DD";
        } elseif ($dataValida($t->dataCheckIn) && trim($t->dataCheckOut) <= trim($t->dataCheckIn)) {
            $errori[] = 'la data di check-out deve essere successiva al check-in';
        }
    }
    $eta = $parseEtaBambini($t->etaBambini);
    if (count($eta) !== $viaggio['bambini']) {
        $errori[] = "le età dei bambini devono essere esattamente {$viaggio['bambini']}";
    }

    return $errori;
};

$ricercaHotel = $eseguiFase(
    HotelAgent::make($viaggio),
    TurnoHotel::class,
    datiCompleti: $datiCompletiHotel,
    estraiRecord: static fn(TurnoHotel $t): array => [
        'hotel_selezionato' => $t->hotelSelezionato !== null ? trim($t->hotelSelezionato) : null,
        'check_in' => $t->dataCheckIn !== null ? trim($t->dataCheckIn) : null,
        'check_out' => $t->dataCheckOut !== null ? trim($t->dataCheckOut) : null,
        'camere' => $t->camere ?? 1,
    ],
    messaggioAvvio: 'Volo sistemato. Passiamo agli hotel.',
    erroriValidazione: $erroriHotel,
    mostraRiepilogo: $mostraRiepilogo,
);

if ($ricercaHotel !== null && $ricercaHotel['hotel_selezionato'] !== null && $ricercaHotel['hotel_selezionato'] !== '') {
    $stato['hotel_selezionato'] = [
        'descrizione' => $ricercaHotel['hotel_selezionato'],
        'check_in' => $ricercaHotel['check_in'],
        'check_out' => $ricercaHotel['check_out'],
        'camere' => $ricercaHotel['camere'],
        'selezionato_il' => date(DATE_ATOM),
    ];
    $pratica->aggiorna(['hotel_selezionato' => $stato['hotel_selezionato']]);
    echo $grigio("(hotel selezionato aggiunto alla pratica)") . "\n";
}

// Riepilogo finale dei dati raccolti e dei servizi selezionati
$mostraRiepilogo();
echo $grigio("(pratica completa in {$pratica->percorso()})") . "\n";
exit(0);

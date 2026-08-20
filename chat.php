<?php

declare(strict_types=1);

use App\Neuron\NeuronAgent;
use App\Neuron\TurnoAgente;
use App\Neuron\TurnoViaggio;
use App\Neuron\ViaggioAgent;
use App\Support\Archivio;
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

echo $ciano("Ciao, sono Neuron il tuo assistente virtuale.") . "\n\n";

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
 */
$eseguiFase = static function (
    Agent $agent,
    string $classeTurno,
    Closure $datiCompleti,
    Closure $estraiRecord,
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
    $turnoAgente = $turno("Ciao!");
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
            // Con dati validi la raccolta è completa; altrimenti è un rifiuto dell'utente
            return $datiCompleti($turnoAgente) ? $estraiRecord($turnoAgente) : null;
        }

        if ($iterazione >= $maxIterazioni) {
            echo "Neuron: Purtroppo è stato raggiunto il numero massimo di interazioni e devo chiudere la conversazione. Ti auguro buona giornata!\n";
            exit(2);
        }

        echo "Tu: ";
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

        $turnoAgente = $turno($input);
    }
};

// --- Fase 1: nome, cognome ed email ---

$utente = $eseguiFase(
    NeuronAgent::make(),
    TurnoAgente::class,
    datiCompleti: static fn(TurnoAgente $t): bool =>
        $soloLettere($t->nome)
        && $soloLettere($t->cognome)
        && $t->email !== null
        && filter_var($t->email, FILTER_VALIDATE_EMAIL) !== false,
    estraiRecord: static fn(TurnoAgente $t): array => [
        'nome' => trim($t->nome),
        'cognome' => trim($t->cognome),
        'email' => trim($t->email),
    ],
);

if ($utente === null) {
    echo "Va bene, nessun dato salvato. Alla prossima!\n";
    exit(0);
}

(new Archivio(__DIR__ . '/data/utenti.json'))->salva($utente);
echo $verde("Neuron: Grazie {$utente['nome']} {$utente['cognome']}, ti ringrazio per avermi dato queste informazioni.") . "\n";
echo $grigio("(dati salvati in data/utenti.json)") . "\n\n";

// --- Fase 2: destinazione, numero di persone e periodo del viaggio ---

echo $ciano("Passiamo ora all'organizzazione del tuo viaggio.") . "\n\n";

$viaggio = $eseguiFase(
    ViaggioAgent::make(),
    TurnoViaggio::class,
    datiCompleti: static fn(TurnoViaggio $t): bool =>
        $soloLettere($t->destinazione)
        && $t->numeroPersone !== null && $t->numeroPersone >= 1
        && $t->periodo !== null && trim($t->periodo) !== '',
    estraiRecord: static fn(TurnoViaggio $t): array => [
        'destinazione' => trim($t->destinazione),
        'numero_persone' => $t->numeroPersone,
        'periodo' => trim($t->periodo),
    ],
);

if ($viaggio === null) {
    echo "Va bene, nessun viaggio salvato. Alla prossima!\n";
    exit(0);
}

// Il viaggio è collegato all'utente tramite l'email raccolta nella fase 1
(new Archivio(__DIR__ . '/data/viaggi.json'))->salva(['email' => $utente['email'], ...$viaggio]);
echo $verde("Neuron: Grazie {$utente['nome']}! Viaggio registrato: {$viaggio['destinazione']}, {$viaggio['numero_persone']} persone, {$viaggio['periodo']}. Buon viaggio!") . "\n";
echo $grigio("(dati salvati in data/viaggi.json)") . "\n";

// Riepilogo finale dei dati raccolti nelle due fasi
echo "\n" . $verde("--- Riepilogo ---") . "\n";
echo $ciano("Anagrafica: {$utente['nome']} {$utente['cognome']} ({$utente['email']})") . "\n";
echo $ciano("Viaggio: {$viaggio['destinazione']}, {$viaggio['numero_persone']} persone, {$viaggio['periodo']}") . "\n";
exit(0);

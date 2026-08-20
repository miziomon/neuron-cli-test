<?php

declare(strict_types=1);

use App\Neuron\NeuronAgent;
use App\Neuron\TurnoAgente;
use App\Support\Archivio;
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
$verde = static fn(string $t): string => $c('32', $t);   // messaggio finale

$agent = NeuronAgent::make();
$archivio = new Archivio(__DIR__ . '/data/utenti.json');

// Esegue un turno strutturato con retry in caso di rate limit (429)
$turno = static function (string $input) use ($agent): TurnoAgente {
    $attesa = 5;
    for ($tentativo = 1; $tentativo <= 4; $tentativo++) {
        try {
            /** @var TurnoAgente $risultato */
            $risultato = $agent->structured(new UserMessage($input), TurnoAgente::class);
            return $risultato;
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

// Conteggio token del turno appena concluso
$conteggioToken = static function () use ($agent, $grigio): void {
    $usage = $agent->resolveState()->getChatHistory()->getLastMessage()->getUsage();
    if ($usage instanceof Usage) {
        echo $grigio("   [token: in {$usage->inputTokens} · out {$usage->outputTokens} · totale {$usage->getTotal()}]") . "\n";
    }
};

// Nome e cognome devono essere valori reali: solo lettere, spazi, apostrofi e trattini
$datiValidi = static fn(?string $valore): bool =>
    $valore !== null && preg_match('/^[\p{L}][\p{L}\s\'-]*$/u', trim($valore)) === 1;

// Numero massimo di turni dell'agente prima della chiusura automatica
$maxIterazioni = max(1, (int) ($_ENV['MAX_ITERAZIONI'] ?? 6));

// Messaggio di avvio: l'agente inizia la raccolta dei dati
$turnoAgente = $turno("Ciao!");
$iterazione = 0;

while (true) {
    $iterazione++;
    echo $ciano("#{$iterazione} Neuron: {$turnoAgente->risposta}") . "\n";
    $conteggioToken();
    echo "\n";

    if ($turnoAgente->confermato && $datiValidi($turnoAgente->nome) && $datiValidi($turnoAgente->cognome)) {
        $archivio->salva($turnoAgente->nome, $turnoAgente->cognome);
        echo $verde("Neuron:Grazie {$turnoAgente->nome} {$turnoAgente->cognome}, ti ringrazio per avermi dato queste informazioni.") . "\n";
        echo $grigio("(dati salvati in data/utenti.json)") . "\n";
        exit(0);
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

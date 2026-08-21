<?php

declare(strict_types=1);

use App\Support\ArchivioSqlite;
use App\Workflow\RichiestaInput;
use App\Workflow\TravelWorkflow;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\FilePersistence;
use NeuronAI\Workflow\WorkflowState;

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

/** Stampa i dati raccolti e i servizi selezionati finora (comando "riepilogo"). */
$mostraRiepilogo = static function (WorkflowState $stato) use ($ciano, $grigio, $verde): void {
    echo "\n" . $verde("--- Riepilogo pratica ---") . "\n";

    $utente = $stato->get('utente');
    $viaggio = $stato->get('viaggio');

    if ($utente === null && $viaggio === null) {
        echo $grigio("Nessun dato raccolto finora.") . "\n\n";

        return;
    }

    if ($utente !== null) {
        $anagrafica = trim("{$utente['nome']} {$utente['cognome']}");
        echo $ciano("Anagrafica: " . ($anagrafica !== '' ? $anagrafica : '(non fornita)')
            . ($utente['email'] !== null ? " ({$utente['email']})" : '')) . "\n";
    } else {
        echo $ciano("Anagrafica: non fornita") . "\n";
    }

    if ($viaggio !== null) {
        $passeggeri = "{$viaggio['adulti']} " . ($viaggio['adulti'] === 1 ? 'adulto' : 'adulti')
            . ($viaggio['bambini'] > 0 ? " e {$viaggio['bambini']} " . ($viaggio['bambini'] === 1 ? 'bambino' : 'bambini') : '');
        $date = $viaggio['data_partenza'] . ($viaggio['data_ritorno'] !== null ? " → ritorno {$viaggio['data_ritorno']}" : ', sola andata');
        echo $ciano("Viaggio: {$viaggio['aeroporto_partenza']} → {$viaggio['aeroporto_destinazione']} ({$viaggio['destinazione']}), {$date}, {$passeggeri}") . "\n";
        echo $ciano("Volo: " . ($stato->get('volo_selezionato')['descrizione'] ?? 'nessuna selezione')) . "\n";
        echo $ciano("Hotel: " . ($stato->get('hotel_selezionato')['descrizione'] ?? 'nessuna selezione')) . "\n";
    }
    echo "\n";
};

// --- Ciclo del workflow: run → interruzione → input utente → resume ---

$dirWorkflow = __DIR__ . '/data/workflow';
if (!is_dir($dirWorkflow) && !mkdir($dirWorkflow, 0777, true)) {
    fwrite(STDERR, "Errore: impossibile creare la directory {$dirWorkflow}\n");
    exit(1);
}
$persistence = new FilePersistence($dirWorkflow);

$workflowId = null;   // valorizzato al primo WorkflowInterrupt
$ripresa = null;      // RichiestaInput con l'input dell'utente
$contatori = [];      // turni per fase (chiave: classe del nodo)
$archivio = null;     // ArchivioSqlite, creato al primo interrupt (serve il workflowId)

while (true) {
    try {
        $workflow = TravelWorkflow::make($persistence, $workflowId);
        $statoFinale = $workflow->init($ripresa)->run();

        // StopEvent raggiunto: conversazione conclusa
        $mostraRiepilogo($statoFinale);
        $percorso = $statoFinale->get('pratica_percorso');
        if (is_string($percorso)) {
            echo $grigio("(pratica completa in {$percorso})") . "\n";
        } else {
            echo "Va bene, nessun dato salvato. Alla prossima!\n";
        }
        exit(0);
    } catch (WorkflowInterrupt $interrupt) {
        $richiesta = $interrupt->getRequest();
        if (!$richiesta instanceof RichiestaInput) {
            throw $interrupt;
        }

        $workflowId = $interrupt->getWorkflowId();
        $nodo = $interrupt->getNode()::class;

        // Archivio SQLite della conversazione (data/neuron.sqlite)
        $archivio ??= new ArchivioSqlite(__DIR__ . '/data/neuron.sqlite');

        // Il contatore delle iterazioni riparte da #1 a ogni fase
        $contatori[$nodo] = ($contatori[$nodo] ?? 0) + 1;
        if ($contatori[$nodo] > $maxIterazioni) {
            echo "Neuron: Purtroppo è stato raggiunto il numero massimo di interazioni e devo chiudere la conversazione. Ti auguro buona giornata!\n";
            exit(2);
        }

        echo $ciano("#{$contatori[$nodo]} Neuron: {$richiesta->getMessage()}") . "\n";

        // Conteggio token del turno appena concluso
        $usage = $interrupt->getState()->get('ultimo_usage');
        if (is_array($usage)) {
            echo $grigio("   [token: in {$usage['in']} · out {$usage['out']} · totale {$usage['tot']}]") . "\n";
        }
        echo "\n";

        // Messaggio dell'agente con il dettaglio dei token
        $archivio->registraMessaggio($workflowId, 'agente', $richiesta->getMessage(), is_array($usage) ? $usage : null);

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

            // Comando locale: mostra lo stato della pratica SENZA riprendere il workflow
            if (in_array(strtolower($input), ['riepilogo', 'servizi'], true)) {
                $mostraRiepilogo($interrupt->getState());
                echo "Tu: ";
                continue;
            }

            break;
        }

        // Risposta dell'utente (i comandi locali riepilogo/esci non sono conversazione)
        $archivio->registraMessaggio($workflowId, 'utente', $input);

        $ripresa = new RichiestaInput('', $input);
    }
}

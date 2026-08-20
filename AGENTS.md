# AGENTS.md

## Panoramica del progetto

Applicazione CLI PHP didattica basata sul framework **Neuron AI** (`neuron-core/neuron-ai`, v3). Un agente conversazionale chiamato "Neuron" dialoga con l'utente in italiano in **due fasi sequenziali**:

1. **Fase anagrafica** (`NeuronAgent`): raccoglie **nome, cognome ed email**, chiede conferma esplicita e salva in `data/utenti.json`.
2. **Fase viaggio** (`ViaggioAgent`): parte solo dopo il completamento della prima fase; raccoglie **destinazione, numero di persone e periodo** e salva in `data/viaggi.json`, collegando il record all'utente tramite l'email.

Entrambi gli agenti usano il provider OpenRouter (compatibile OpenAI) tramite `NeuronAI\Providers\OpenAILike` e lo structured output di Neuron AI: ogni turno restituisce un oggetto tipizzato (`TurnoAgente` / `TurnoViaggio`) invece di testo libero. Il loop di conversazione è condiviso (closure `eseguiFase` in `chat.php`); il contatore delle iterazioni riparte da `#1` a ogni fase.

## Stack tecnologico

- **PHP >= 8.1** (ambiente verificato: PHP 8.4)
- **Composer** per dipendenze e autoloading PSR-4 (`App\` → `src/`, `App\Tests\` → `tests/`)
- **Neuron AI ^3.16** — framework per agenti LLM (agent, system prompt, structured output, chat history, usage dei token)
- **PHPUnit ^13.3** per i test
- Nessun database, nessun server web: esecuzione interamente da riga di comando.

## Struttura del codice

- `chat.php` — entry point della chat interattiva. Carica manualmente il file `.env` (le variabili d'ambiente reali hanno precedenza), esegue le due fasi di raccolta tramite la closure condivisa `eseguiFase` (loop di conversazione, retry con backoff esponenziale su HTTP 429, conteggio dei token, limite di iterazioni), valida i dati e li salva. Codici di uscita: `0` successo/rifiuto/uscita volontaria, `1` errore di configurazione o di comunicazione, `2` raggiunto il numero massimo di iterazioni.
- `src/Neuron/OpenRouterAgent.php` — classe base astratta con il provider OpenRouter (`OpenAILike` su `https://openrouter.ai/api/v1`) condiviso dagli agenti.
- `src/Neuron/NeuronAgent.php` — agente della fase anagrafica: system prompt in italiano costruito con `SystemPrompt` (background, steps, output).
- `src/Neuron/TurnoAgente.php` — DTO dello structured output della fase 1 con attributi `#[SchemaProperty]`: `risposta` (string, obbligatoria), `nome`, `cognome` ed `email` (nullable), `confermato` (bool, obbligatorio).
- `src/Neuron/ViaggioAgent.php` — agente della fase viaggio: estende `OpenRouterAgent` e definisce il proprio system prompt.
- `src/Neuron/TurnoViaggio.php` — DTO dello structured output della fase 2: `risposta`, `destinazione`, `numeroPersone` (?int), `periodo` (nullable), `confermato`.
- `src/Support/Archivio.php` — persistenza minimale su file JSON: legge (`tutti()`) e accoda (`salva(array $record)`) record arricchiti con `raccolto_il`, creando la directory se mancante.
- `tests/` — `NeuronAgentTest.php`, `ViaggioAgentTest.php`, `ArchivioTest.php` e i doppioni di test `FakeProvider.php` / `AgenteFinto.php`.
- `data/utenti.json`, `data/viaggi.json` — dati raccolti a runtime (git-ignored).

## Build e test

Non esiste una fase di build; Composer gestisce tutto.

```bash
composer install        # installa le dipendenze
php chat.php            # avvia la chat interattiva (richiede .env)
vendor/bin/phpunit      # esegue la suite di test (7 test, configurazione in phpunit.xml)
```

## Configurazione richiesta

Il file `.env` (git-ignored, non presente nel repository) deve definire:

```
OPENROUTER_API_KEY=...
OPENROUTER_MODEL=...        # es. un modello disponibile su OpenRouter
MAX_ITERAZIONI=6            # opzionale, default 6
```

Senza `OPENROUTER_API_KEY` e `OPENROUTER_MODEL` lo script esce con errore. I colori ANSI dell'output si disattivano con `NO_COLOR=1`.

## Convenzioni di codice

- Tutti i file PHP iniziano con `declare(strict_types=1);`.
- Commenti, docblock, nomi di variabili/metodi e messaggi utente sono **in italiano**: mantenere questa lingua in tutto il codice del progetto.
- Stile moderno: tipi dichiarati ovunque, proprietà `readonly`, arrow function e closure per la logica procedurale dell'entry point, eccezioni `RuntimeException` per gli errori di I/O.
- Il codice applicativo usa il namespace `App\`; le classi sono piccole e a responsabilità singola.

## Strategia di testing

- Suite PHPUnit configurata in `phpunit.xml` (bootstrap `vendor/autoload.php`, cache in `.phpunit.cache`, tutta la directory `tests`).
- I test **non effettuano chiamate HTTP reali**: `tests/FakeProvider.php` implementa `AIProviderInterface` restituendo un `AssistantMessage` predefinito con usage fittizio, permettendo di testare il mapping dello structured output e il conteggio dei token in modo deterministico.
- I test di persistenza (`ArchivioTest`) usano file temporanei in `sys_get_temp_dir()` con pulizia in `tearDown()`: mai toccare `data/utenti.json` nei test.

## Considerazioni di sicurezza

- `.env` contiene la chiave API OpenRouter ed è escluso da git (`.gitignore` copre `/vendor/`, `.env`, `/data/`): non committarlo mai e non leggerne il contenuto per esporlo.
- I dati raccolti (anagrafiche e viaggi degli utenti) sono dati personali salvati in chiaro in `data/utenti.json` e `data/viaggi.json`, entrambi git-ignored.
- La validazione in `chat.php` accetta solo lettere, spazi, apostrofi e trattini (regex Unicode `/^[\p{L}][\p{L}\s\'-]*$/u`) per nome, cognome e destinazione; l'email è validata con `filter_var(..., FILTER_VALIDATE_EMAIL)`; il numero di persone deve essere un intero >= 1. Il salvataggio avviene solo a validazione superata.

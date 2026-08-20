# AGENTS.md

## Panoramica del progetto

Applicazione CLI PHP didattica basata sul framework **Neuron AI** (`neuron-core/neuron-ai`, v3). Un agente conversazionale chiamato "Neuron" dialoga con l'utente in italiano con l'unico obiettivo di raccogliere **nome e cognome**, chiedere conferma esplicita e salvarli in un file JSON (`data/utenti.json`). L'agente usa il provider OpenRouter (compatibile OpenAI) tramite `NeuronAI\Providers\OpenAILike` e sfrutta lo structured output di Neuron AI: ogni turno di conversazione restituisce un oggetto tipizzato (`TurnoAgente`) invece di testo libero.

## Stack tecnologico

- **PHP >= 8.1** (ambiente verificato: PHP 8.4)
- **Composer** per dipendenze e autoloading PSR-4 (`App\` → `src/`, `App\Tests\` → `tests/`)
- **Neuron AI ^3.16** — framework per agenti LLM (agent, system prompt, structured output, chat history, usage dei token)
- **PHPUnit ^13.3** per i test
- Nessun database, nessun server web: esecuzione interamente da riga di comando.

## Struttura del codice

- `chat.php` — entry point della chat interattiva. Carica manualmente il file `.env` (le variabili d'ambiente reali hanno precedenza), esegue il loop di conversazione, gestisce il retry con backoff esponenziale in caso di rate limit (HTTP 429), mostra il conteggio dei token e salva i dati confermati. Codici di uscita: `0` successo/uscita volontaria, `1` errore di configurazione o di comunicazione, `2` raggiunto il numero massimo di iterazioni.
- `src/Neuron/NeuronAgent.php` — definizione dell'agente: provider OpenRouter (`https://openrouter.ai/api/v1`) e system prompt in italiano costruito con `SystemPrompt` (background, steps, output).
- `src/Neuron/TurnoAgente.php` — classe DTO dello structured output con attributi `#[SchemaProperty]`: `risposta` (string, obbligatoria), `nome` e `cognome` (nullable), `confermato` (bool, obbligatorio).
- `src/Support/Archivio.php` — persistenza minimale su file JSON: legge (`tutti()`) e accoda (`salva()`) record `{nome, cognome, raccolto_il}`, creando la directory se mancante.
- `tests/` — `NeuronAgentTest.php`, `ArchivioTest.php` e il doppione di test `FakeProvider.php`.
- `data/utenti.json` — dati raccolti a runtime (git-ignored).

## Build e test

Non esiste una fase di build; Composer gestisce tutto.

```bash
composer install        # installa le dipendenze
php chat.php            # avvia la chat interattiva (richiede .env)
vendor/bin/phpunit      # esegue la suite di test (4 test, configurazione in phpunit.xml)
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
- I dati raccolti (nome e cognome degli utenti) sono dati personali salvati in chiaro in `data/utenti.json`, anch'esso git-ignored.
- La validazione di nome/cognome in `chat.php` accetta solo lettere, spazi, apostrofi e trattini (regex Unicode `/^[\p{L}][\p{L}\s\'-]*$/u`) prima del salvataggio.

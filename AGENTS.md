# AGENTS.md

## Panoramica del progetto

Applicazione CLI PHP didattica basata sul framework **Neuron AI** (`neuron-core/neuron-ai`, v3). Gli agenti conversazionali dialogano con l'utente in italiano in **due fasi sequenziali**:

1. **Fase receptionist** (`ReceptionistAgent`): un unico agente raccoglie **nome, cognome, email, destinazione, aeroporti IATA di partenza/destinazione, data di partenza (ed eventuale ritorno), adulti e bambini**; chiede conferma esplicita del ricapitolo completo e salva in `data/utenti.json` e `data/viaggi.json` (collegati tramite email).
2. **Fase voli** (`VoliAgent`): riceve tutti i parametri già raccolti, chiede una sola conferma e cerca i voli tramite il server MCP FlightX (`flightx-mcp.php`, tool `cerca_voli`/`seleziona_volo` collegati con `McpConnector`). Nessuna persistenza: è una consultazione.

Gli agenti usano il provider OpenRouter (compatibile OpenAI) tramite `NeuronAI\Providers\OpenAILike` e lo structured output di Neuron AI: ogni turno restituisce un oggetto tipizzato (`TurnoReceptionist` / `TurnoVolo`) invece di testo libero. Il loop di conversazione è condiviso (closure `eseguiFase` in `chat.php`); il contatore delle iterazioni riparte da `#1` a ogni fase.

## Stack tecnologico

- **PHP >= 8.1** (ambiente verificato: PHP 8.4)
- **Composer** per dipendenze e autoloading PSR-4 (`App\` → `src/`, `App\Tests\` → `tests/`)
- **Neuron AI ^3.16** — framework per agenti LLM (agent, system prompt, structured output, chat history, usage dei token)
- **PHPUnit ^13.3** per i test
- Nessun database, nessun server web: esecuzione interamente da riga di comando.

## Struttura del codice

- `chat.php` — entry point della chat interattiva. Carica manualmente il file `.env` (le variabili d'ambiente reali hanno precedenza), esegue le due fasi tramite la closure condivisa `eseguiFase` (loop di conversazione, retry con backoff esponenziale su HTTP 429, conteggio dei token, limite di iterazioni), valida i dati e li salva. Se l'agente conferma ma alcuni campi valorizzati non superano la validazione, `eseguiFase` non esce: rimanda gli errori all'agente (parametro `erroriValidazione`) e la fase continua finché i dati non sono corretti. Codici di uscita: `0` successo/rifiuto/uscita volontaria, `1` errore di configurazione o di comunicazione, `2` raggiunto il numero massimo di iterazioni.
- `flightx-mcp.php` — server MCP su stdio (JSON-RPC 2.0 newline-delimited) che espone i servizi FlightX come tool `cerca_voli` e `seleziona_volo`; risponde SOLO su STDOUT, diagnostica su STDERR. Credenziali lette dalle variabili d'ambiente `FLIGHTX_*` passate dal connettore. Il formatter di `cerca_voli` restituisce al massimo 5 opzioni leggibili con prezzi in EUR e timestamp di recupero.
- `src/Neuron/OpenRouterAgent.php` — classe base astratta con il provider OpenRouter (`OpenAILike` su `https://openrouter.ai/api/v1`) condiviso dagli agenti.
- `src/Neuron/ReceptionistAgent.php` — unico agente di raccolta: system prompt in italiano costruito con `SystemPrompt` (background, steps, output).
- `src/Neuron/TurnoReceptionist.php` — DTO dello structured output della fase receptionist con attributi `#[SchemaProperty]`: `risposta` (obbligatoria), `nome`, `cognome`, `email`, `destinazione`, `aeroportoPartenza`, `aeroportoDestinazione`, `dataPartenza`, `dataRitorno`, `adulti`, `bambini` (nullable), `confermato` (bool, obbligatorio).
- `src/Neuron/VoliAgent.php` — agente della fase voli: riceve i parametri di ricerca nel costruttore (li inserisce nel system prompt) e dichiara in `tools()` i tool MCP FlightX tramite `McpConnector` con `LineStdioTransport`.
- `src/Neuron/TurnoVolo.php` — DTO dello structured output della fase voli: `risposta`, `aeroportoPartenza`, `aeroportoDestinazione`, `dataPartenza`, `dataRitorno`, `adulti`, `bambini`, `ricercaCompletata`, `confermato`.
- `src/MCP/LineStdioTransport.php` — transport MCP stdio che accumula la risposta finché non è JSON completo (lo `StdioTransport` di Neuron fallisce con payload oltre 4 KB).
- `src/Services/FlightX/` — wrapper delle API FlightX (`FlightXClient`, `FlightXConfig`, gerarchia `Exceptions`): stateful (token JWT + ultima ricerca), validazione locale IATA/date/passeggeri, layer HTTP Guzzle, password in chiaro oppure pre-hashata (`passwordMd5`).
- `src/Support/Archivio.php` — persistenza minimale su file JSON: legge (`tutti()`) e accoda (`salva(array $record)`) record arricchiti con `raccolto_il`, creando la directory se mancante.
- `tests/` — `ReceptionistAgentTest.php`, `FlightXClientTest.php`, `FlightXMcpServerTest.php`, `ArchivioTest.php` e i doppioni di test `FakeProvider.php` / `AgenteFinto.php`.
- `data/utenti.json`, `data/viaggi.json` — dati raccolti a runtime (git-ignored).

## Build e test

Non esiste una fase di build; Composer gestisce tutto.

```bash
composer install        # installa le dipendenze
php chat.php            # avvia la chat interattiva (richiede .env)
vendor/bin/phpunit      # esegue la suite di test (16 test, configurazione in phpunit.xml)
```

## Configurazione richiesta

Il file `.env` (git-ignored, non presente nel repository) deve definire:

```
OPENROUTER_API_KEY=...
OPENROUTER_MODEL=...        # es. un modello disponibile su OpenRouter
MAX_ITERAZIONI=6            # opzionale, default 6
FLIGHTX_BASE_URL=...        # es. https://api.stage.flightx.app
FLIGHTX_API_KEY=...
FLIGHTX_USERNAME=...
FLIGHTX_PASSWORD_MD5=...    # password già hashata con md5(strtolower(...))
```

Senza `OPENROUTER_API_KEY` e `OPENROUTER_MODEL` lo script esce con errore; senza le variabili `FLIGHTX_*` la fase voli fallisce. I colori ANSI dell'output si disattivano con `NO_COLOR=1`.

## Convenzioni di codice

- Tutti i file PHP iniziano con `declare(strict_types=1);`.
- Commenti, docblock, nomi di variabili/metodi e messaggi utente sono **in italiano**: mantenere questa lingua in tutto il codice del progetto.
- Stile moderno: tipi dichiarati ovunque, proprietà `readonly`, arrow function e closure per la logica procedurale dell'entry point, eccezioni `RuntimeException` per gli errori di I/O.
- Il codice applicativo usa il namespace `App\`; le classi sono piccole e a responsabilità singola.
- Nei system prompt: l'anno corrente non è mai cablato, va iniettato con `date('Y')`; le regole di comportamento si distribuiscono nelle sezioni `SystemPrompt` (`background` per identità/contesto, `steps` per il flusso, `output` per formato e vincoli di risposta, `toolsUsage` per l'uso dei tool).

## Strategia di testing

- Suite PHPUnit configurata in `phpunit.xml` (bootstrap `vendor/autoload.php`, cache in `.phpunit.cache`, tutta la directory `tests`).
- I test **non effettuano chiamate HTTP reali**: `tests/FakeProvider.php` implementa `AIProviderInterface` restituendo un `AssistantMessage` predefinito con usage fittizio, permettendo di testare il mapping dello structured output e il conteggio dei token in modo deterministico.
- I test FlightX sono offline: `FlightXClientTest` copre solo le validazioni locali (eseguite prima di qualsiasi HTTP), `FlightXMcpServerTest` avvia `flightx-mcp.php` come processo e verifica handshake e `tools/list` senza mai invocare i tool.
- I test di persistenza (`ArchivioTest`) usano file temporanei in `sys_get_temp_dir()` con pulizia in `tearDown()`: mai toccare `data/utenti.json` nei test.

## Considerazioni di sicurezza

- `.env` contiene la chiave API OpenRouter e le credenziali FlightX ed è escluso da git (`.gitignore` copre `/vendor/`, `.env`, `/data/`): non committarlo mai e non leggerne il contenuto per esporlo.
- Le credenziali FlightX passano al server MCP solo come variabili d'ambiente del processo figlio; il wrapper le oscura nei log (`redact()`).
- Il wrapper FlightX è di sola consultazione: non espone prenotazione (`bookItem`) né emissione biglietti (`issueTickets`); `seleziona_volo` crea solo un dossier temporaneo di 24 ore.
- I dati raccolti (anagrafiche e viaggi degli utenti) sono dati personali salvati in chiaro in `data/utenti.json` e `data/viaggi.json`, entrambi git-ignored.
- La validazione in `chat.php` accetta solo lettere, spazi, apostrofi e trattini (regex Unicode `/^[\p{L}][\p{L}\s\'-]*$/u`) per nome, cognome e destinazione; l'email è validata con `filter_var(..., FILTER_VALIDATE_EMAIL)`; gli aeroporti devono essere codici IATA di 3 lettere; le date devono essere `YYYY-MM-DD` valide (il ritorno non può precedere la partenza); i passeggeri devono includere almeno 1 adulto e non superare 9 in totale. Il salvataggio avviene solo a validazione superata.

# AGENTS.md

## Panoramica del progetto

Applicazione CLI PHP didattica basata sul framework **Neuron AI** (`neuron-core/neuron-ai`, v3). Gli agenti conversazionali dialogano con l'utente in italiano in **tre fasi sequenziali**:

1. **Fase receptionist** (`ReceptionistAgent`): un unico agente raccoglie **nome, cognome, email, destinazione, aeroporti IATA di partenza/destinazione, data di partenza (ed eventuale ritorno), adulti e bambini**; chiede conferma esplicita del ricapitolo completo e salva la pratica in `data/pratica_YYYYmmdd_His.json` (un file per utente, mai eliminato).
2. **Fase voli** (`VoliAgent`): riceve tutti i parametri già raccolti, chiede una sola conferma e cerca i voli tramite il server MCP FlightX (`flightx-mcp.php`, tool `cerca_voli` collegato con `McpConnector`); l'utente **sceglie una delle opzioni** e la scelta è registrata nella pratica. Nessuna operazione verso il fornitore: il dossier FlightX non esiste più.
3. **Fase hotel** (`HotelAgent`): chiede se serve un hotel; in caso affermativo cerca tramite il server MCP Hotelbeds (`hotelbeds-mcp.php`, tool `cerca_hotel` che geocodifica la destinazione con Nominatim e interroga la disponibilità Hotelbeds) e fa scegliere una delle opzioni, registrata nella pratica. Se ci sono bambini, chiede le età (0-17) prima della ricerca.

In qualsiasi prompt l'utente può digitare **`riepilogo`** (o `servizi`) per vedere anagrafica, viaggio e servizi selezionati finora: il comando è intercettato dal loop prima di chiamare il modello (nessun consumo di token né di iterazioni).

Gli agenti usano il provider OpenRouter (compatibile OpenAI) tramite `NeuronAI\Providers\OpenAILike` e lo structured output di Neuron AI: ogni turno restituisce un oggetto tipizzato (`TurnoReceptionist` / `TurnoVolo` / `TurnoHotel`) invece di testo libero. Il loop di conversazione è condiviso (closure `eseguiFase` in `chat.php`); il contatore delle iterazioni riparte da `#1` a ogni fase.

## Stack tecnologico

- **PHP >= 8.1** (ambiente verificato: PHP 8.4)
- **Composer** per dipendenze e autoloading PSR-4 (`App\` → `src/`, `App\Tests\` → `tests/`)
- **Neuron AI ^3.16** — framework per agenti LLM (agent, system prompt, structured output, chat history, usage dei token)
- **PHPUnit ^13.3** per i test
- Nessun database, nessun server web: esecuzione interamente da riga di comando.

## Struttura del codice

- `chat.php` — entry point della chat interattiva. Carica manualmente il file `.env` (le variabili d'ambiente reali hanno precedenza), esegue le tre fasi tramite la closure condivisa `eseguiFase` (loop di conversazione, retry con backoff esponenziale su HTTP 429, conteggio dei token, limite di iterazioni, comando locale `riepilogo`/`servizi`), valida i dati e li salva nella pratica dopo ogni fase. Se l'agente conferma ma alcuni campi valorizzati non superano la validazione, `eseguiFase` non esce: rimanda gli errori all'agente (parametro `erroriValidazione`) e la fase continua finché i dati non sono corretti. Codici di uscita: `0` successo/rifiuto/uscita volontaria, `1` errore di configurazione o di comunicazione, `2` raggiunto il numero massimo di iterazioni.
- `flightx-mcp.php` — server MCP su stdio (JSON-RPC 2.0 newline-delimited) che espone la ricerca FlightX come tool `cerca_voli`; risponde SOLO su STDOUT, diagnostica su STDERR. Credenziali lette dalle variabili d'ambiente `FLIGHTX_*` passate dal connettore. Il formatter restituisce al massimo 5 opzioni leggibili con prezzi in EUR e timestamp di recupero.
- `hotelbeds-mcp.php` — server MCP su stdio che espone il tool `cerca_hotel`: geocodifica la destinazione con Nominatim e cerca la disponibilità con Hotelbeds (raggio 20 km, max 5 opzioni nel formatter, prezzi in EUR e timestamp). Credenziali dalle variabili `HOTELBEDS_*` e `NOMINATIM_*` passate dal connettore.
- `src/Neuron/OpenRouterAgent.php` — classe base astratta con il provider OpenRouter (`OpenAILike` su `https://openrouter.ai/api/v1`) condiviso dagli agenti.
- `src/Neuron/ReceptionistAgent.php` — unico agente di raccolta: system prompt in italiano costruito con `SystemPrompt` (background, steps, output).
- `src/Neuron/TurnoReceptionist.php` — DTO dello structured output della fase receptionist con attributi `#[SchemaProperty]`: `risposta` (obbligatoria), `nome`, `cognome`, `email`, `destinazione`, `aeroportoPartenza`, `aeroportoDestinazione`, `dataPartenza`, `dataRitorno`, `adulti`, `bambini` (nullable), `confermato` (bool, obbligatorio).
- `src/Neuron/VoliAgent.php` — agente della fase voli: riceve i parametri di ricerca nel costruttore (li inserisce nel system prompt) e dichiara in `tools()` i tool MCP FlightX tramite `McpConnector` con `LineStdioTransport`.
- `src/Neuron/TurnoVolo.php` — DTO dello structured output della fase voli: `risposta`, `aeroportoPartenza`, `aeroportoDestinazione`, `dataPartenza`, `dataRitorno`, `adulti`, `bambini`, `ricercaCompletata`, `voloSelezionato`, `confermato`.
- `src/Neuron/HotelAgent.php` — agente della fase hotel: riceve i dati del viaggio nel costruttore e dichiara in `tools()` il tool MCP di `hotelbeds-mcp.php`; propone come default le date del volo e chiede le età dei bambini quando presenti.
- `src/Neuron/TurnoHotel.php` — DTO dello structured output della fase hotel: `risposta`, `hotelRichiesto`, `dataCheckIn`, `dataCheckOut`, `camere`, `etaBambini` (stringa CSV, es. `"5, 8"`), `hotelSelezionato`, `confermato`.
- `src/MCP/LineStdioTransport.php` — transport MCP stdio che accumula la risposta finché non è JSON completo (lo `StdioTransport` di Neuron fallisce con payload oltre 4 KB).
- `src/Services/FlightX/` — wrapper delle API FlightX (`FlightXClient`, `FlightXConfig`, gerarchia `Exceptions`): stateful (token JWT + ultima ricerca), validazione locale IATA/date/passeggeri, layer HTTP Guzzle, password in chiaro oppure pre-hashata (`passwordMd5`).
- `src/Services/Hotelbeds/` — wrapper delle API Hotelbeds (`HotelbedsClient`, `HotelbedsConfig`, gerarchia `Exceptions`): stateless (firma `X-Signature` = sha256(apiKey + secret + timestamp) ricalcolata a ogni richiesta), 4 varianti di ricerca disponibilità + `checkRates()`, validazioni locali (date, coordinate, occupazioni, età bambini 0-17), layer HTTP Guzzle. Sola lettura: nessuna prenotazione.
- `src/Services/Geocoding/` — wrapper dell'API Nominatim/OpenStreetMap (`NominatimClient`, `NominatimConfig`, gerarchia `Exceptions`): `geocode()`/`search()` con coordinate normalizzate a float, throttle di processo (1 richiesta/secondo, `$sleeper` iniettabile nei test), `userAgent` obbligatorio per policy d'uso.
- `src/Support/Archivio.php` — persistenza minimale su file JSON: legge (`tutti()`) e accoda (`salva(array $record)`) record arricchiti con `raccolto_il`, creando la directory se mancante. Non più usato da `chat.php` (resta coperto dai test).
- `src/Support/Pratica.php` — persistenza della pratica di viaggio: un file per utente `data/pratica_YYYYmmdd_His.json` creato dopo la fase receptionist e aggiornato (`aggiorna()`) a ogni selezione (volo, hotel); i file esistenti non vengono mai eliminati.
- `tests/` — `ReceptionistAgentTest.php`, `FlightXClientTest.php`, `FlightXMcpServerTest.php`, `HotelbedsClientTest.php`, `NominatimClientTest.php`, `HotelbedsMcpServerTest.php`, `ArchivioTest.php`, `PraticaTest.php` e i doppioni di test `FakeProvider.php` / `AgenteFinto.php`.
- `data/pratica_*.json` — pratiche raccolte a runtime (git-ignored); `data/utenti.json` e `data/viaggi.json` restano come storico non più scritto.

## Build e test

Non esiste una fase di build; Composer gestisce tutto.

```bash
composer install        # installa le dipendenze
php chat.php            # avvia la chat interattiva (richiede .env)
vendor/bin/phpunit      # esegue la suite di test (42 test, configurazione in phpunit.xml)
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
HOTELBEDS_BASE_URL=...      # es. https://api.test.hotelbeds.com/hotel-api/1.0
HOTELBEDS_API_KEY=...
HOTELBEDS_SECRET=...
NOMINATIM_USER_AGENT=...    # es. neuron-travel-cli/1.0 (obbligatorio per policy Nominatim)
NOMINATIM_EMAIL=...         # opzionale, contatto consigliato dalla policy Nominatim
NOMINATIM_BASE_URL=...      # opzionale, default https://nominatim.openstreetmap.org
```

Senza `OPENROUTER_API_KEY` e `OPENROUTER_MODEL` lo script esce con errore; senza le variabili `FLIGHTX_*` la fase voli fallisce; senza `HOTELBEDS_*`/`NOMINATIM_USER_AGENT` la fase hotel fallisce. I colori ANSI dell'output si disattivano con `NO_COLOR=1`.

## Convenzioni di codice

- Tutti i file PHP iniziano con `declare(strict_types=1);`.
- Commenti, docblock, nomi di variabili/metodi e messaggi utente sono **in italiano**: mantenere questa lingua in tutto il codice del progetto.
- Stile moderno: tipi dichiarati ovunque, proprietà `readonly`, arrow function e closure per la logica procedurale dell'entry point, eccezioni `RuntimeException` per gli errori di I/O.
- Il codice applicativo usa il namespace `App\`; le classi sono piccole e a responsabilità singola.
- Nei system prompt: l'anno corrente non è mai cablato, va iniettato con `date('Y')`; le regole di comportamento si distribuiscono nelle sezioni `SystemPrompt` (`background` per identità/contesto, `steps` per il flusso, `output` per formato e vincoli di risposta, `toolsUsage` per l'uso dei tool).

## Strategia di testing

- Suite PHPUnit configurata in `phpunit.xml` (bootstrap `vendor/autoload.php`, cache in `.phpunit.cache`, tutta la directory `tests`).
- I test **non effettuano chiamate HTTP reali**: `tests/FakeProvider.php` implementa `AIProviderInterface` restituendo un `AssistantMessage` predefinito con usage fittizio, permettendo di testare il mapping dello structured output e il conteggio dei token in modo deterministico.
- I test FlightX/Hotelbeds/Geocoding sono offline: `FlightXClientTest`, `HotelbedsClientTest` e `NominatimClientTest` coprono solo le validazioni locali (eseguite prima di qualsiasi HTTP); `FlightXMcpServerTest` e `HotelbedsMcpServerTest` avviano i server MCP come processi e verificano handshake e `tools/list` senza mai invocare i tool.
- I test di persistenza (`ArchivioTest`, `PraticaTest`) usano file temporanei in `sys_get_temp_dir()` con pulizia in `tearDown()`: mai toccare i file in `data/` nei test.

## Considerazioni di sicurezza

- `.env` contiene la chiave API OpenRouter e le credenziali FlightX/Hotelbeds ed è escluso da git (`.gitignore` copre `/vendor/`, `.env`, `/data/`): non committarlo mai e non leggerne il contenuto per esporlo.
- Le credenziali FlightX e Hotelbeds passano ai server MCP solo come variabili d'ambiente del processo figlio; i wrapper le oscurano nei log (`redact()`). Il `secret` Hotelbeds non è mai inviato in rete: serve solo a calcolare la firma `X-Signature`.
- I wrapper FlightX e Hotelbeds sono di sola consultazione: non espongono prenotazione (`bookItem`/`POST /bookings`) né emissione biglietti (`issueTickets`); la selezione di volo e hotel è solo registrata nella pratica, senza operazioni verso i fornitori.
- I dati raccolti (anagrafiche, viaggi e selezioni degli utenti) sono dati personali salvati in chiaro in `data/pratica_*.json`, git-ignored.
- La validazione in `chat.php` accetta solo lettere, spazi, apostrofi e trattini (regex Unicode `/^[\p{L}][\p{L}\s\'-]*$/u`) per nome, cognome e destinazione; l'email è validata con `filter_var(..., FILTER_VALIDATE_EMAIL)`; gli aeroporti devono essere codici IATA di 3 lettere; le date devono essere `YYYY-MM-DD` valide (il ritorno non può precedere la partenza; il check-out hotel deve essere successivo al check-in); i passeggeri devono includere almeno 1 adulto e non superare 9 in totale; le età dei bambini per l'hotel devono essere tante quanti sono i bambini (0-17). Il salvataggio avviene solo a validazione superata.

# AGENTS.md

## Panoramica del progetto

Applicazione CLI PHP didattica basata sul framework **Neuron AI** (`neuron-core/neuron-ai`, v3). La conversazione è orchestrata da un **Workflow event-driven nativo di Neuron** (`App\Workflow\TravelWorkflow`): i nodi scambiano eventi tipizzati e la conversazione con l'utente avviene tramite **interruzioni human-in-the-loop** (`WorkflowInterrupt` con `RichiestaInput`, poi `resume()`). Le fasi del grafo:

1. **Consulente** (`ConsulenteNode` + `ConsulenteAgent`/`TurnoConsulente`): risponde a domande aperte e consequenziali sui viaggi con la sola conoscenza del modello (NESSUN tool MCP). Quando l'utente è pronto a prenotare, passa alla raccolta dati proponendo come default i suggerimenti emersi (destinazione, aeroporti IATA, date).
2. **Receptionist** (`ReceptionistNode` + `ReceptionistAgent`/`TurnoReceptionist`): raccoglie i dati del viaggio (destinazione, aeroporti IATA, date, adulti e bambini). L'**anagrafica (nome, cognome, email) è opzionale**: se l'utente si presenta viene salvata, altrimenti la pratica ha `utente = null`.
3. **Validazione** (`ValidazioneNode` + `App\Support\Validazione`): valida SOLO i dati del viaggio; campi mancanti o non validi tornano al receptionist come messaggio di correzione. A validazione superata crea subito la pratica in `data/pratica_YYYYmmdd_His.json`; il rifiuto (nessun dato di viaggio) porta direttamente alla chiusura senza salvare.
4. **Voli** (`VoliNode` + `VoliAgent`/`TurnoVolo`): ricerca tramite il server MCP FlightX (`flightx-mcp.php`, tool `cerca_voli` via `McpConnector`); l'utente sceglie un'opzione, registrata nella pratica. Nessuna operazione verso il fornitore.
5. **Hotel** (`HotelNode` + `HotelAgent`/`TurnoHotel`): ricerca tramite il server MCP Hotelbeds (`hotelbeds-mcp.php`, tool `cerca_hotel` con geocoding Nominatim); date ed età dei bambini non valide tornano all'agente per la correzione. La scelta è registrata nella pratica.
6. **Persistenza** (`PersistenzaNode`): compone la pratica finale nello stato e chiude con `StopEvent`.

In qualsiasi prompt l'utente può digitare **`riepilogo`** (o `servizi`) per vedere anagrafica, viaggio e servizi selezionati: il comando è intercettato dal loop CLI prima del `resume()` (nessun consumo di token né di iterazioni). `esci`/`exit`/`quit` chiudono subito.

Gli agenti usano il provider OpenRouter (compatibile OpenAI) tramite `NeuronAI\Providers\OpenAILike` e lo structured output di Neuron AI: ogni turno restituisce un oggetto tipizzato. Il contatore delle iterazioni riparte da `#1` a ogni fase (chiave: classe del nodo interrotto).

### Vincoli dell'API Workflow di Neuron 3.16 (rispettati dal progetto)

- Un nodo gestisce **un solo tipo di evento** (primo parametro di `__invoke`, niente union); il tipo di ritorno può essere una union di eventi.
- A ogni `WorkflowInterrupt` nodo e stato vengono **serializzati** (`FilePersistence` in `data/workflow/`): gli agenti NON sono serializzabili (provider HTTP con closure), quindi i nodi non tengono agenti in proprietà: li ricreano a ogni invocazione e persistono la chat history come JSON nello `WorkflowState` (`App\Support\StoriaChat`). La factory iniettata nei test (Closure) è esclusa via `__serialize`/`__unserialize`.
- Al `resume()` il nodo interrotto viene **rieseguito dall'inizio** con la stessa evento: i nodi conversazionali consumano subito la richiesta di ripresa (`inputRipresa()`) e rientrano nel loop SENZA rieseguire il turno LLM (né le chiamate ai tool MCP).

## Stack tecnologico

- **PHP >= 8.1** (ambiente verificato: PHP 8.4)
- **Composer** per dipendenze e autoloading PSR-4 (`App\` → `src/`, `App\Tests\` → `tests/`)
- **Neuron AI ^3.16** — framework per agenti LLM (agent, system prompt, structured output, chat history, workflow, MCP)
- **PHPUnit ^13.3** per i test
- Nessun database, nessun server web: esecuzione interamente da riga di comando.

## Struttura del codice

- `chat.php` — entry point CLI: carica `.env` (le variabili d'ambiente reali hanno precedenza), poi esegue il ciclo `TravelWorkflow::make($persistence, $workflowId)->init($ripresa)->run()` catturando `WorkflowInterrupt`: stampa messaggio e token, legge l'input (con i comandi locali `riepilogo`/`servizi` ed `esci`) e riprende con una nuova `RichiestaInput`. Codici di uscita: `0` successo/rifiuto/uscita volontaria, `1` errore di configurazione o di comunicazione, `2` raggiunto il numero massimo di iterazioni.
- `src/Workflow/TravelWorkflow.php` — il grafo: nodi + evento di avvio (`EventoConsulenza`). I nodi sono iniettabili nel costruttore (solo per i test).
- `src/Workflow/RichiestaInput.php` — `InterruptRequest` che porta il messaggio dell'agente all'utente e, al resume, l'input dell'utente al workflow.
- `src/Workflow/Events/` — eventi tipizzati: `EventoConsulenza` (avvio + loop consulente), `EventoRaccoltaDati` (kickoff con suggerimenti, loop utente, errori di validazione), `EventoDaValidare`, `EventoDatiValidati` (avvio + loop voli, con saluto del receptionist), `EventoHotel` (avvio + loop hotel, con errori), `EventoFine`.
- `src/Workflow/Nodes/NodoConversazionale.php` — base dei nodi conversazionali: ripristino/salvataggio della chat history JSON nello stato, turno LLM con retry, salvataggio dell'usage (`ultimo_usage`), consumo della richiesta di resume, esclusione della factory Closure dalla serializzazione.
- `src/Workflow/Nodes/` — `ConsulenteNode`, `ReceptionistNode`, `ValidazioneNode`, `VoliNode`, `HotelNode`, `PersistenzaNode`.
- `src/Neuron/OpenRouterAgent.php` — classe base astratta con il provider OpenRouter (`OpenAILike` su `https://openrouter.ai/api/v1`) condiviso dagli agenti.
- `src/Neuron/ConsulenteAgent.php` + `TurnoConsulente.php` — agente consulente di viaggi (nessun tool) e suo DTO: `risposta`, `prontoAPrenotare` (bool), suggerimenti nullable (`destinazioneSuggerita`, `aeroportoPartenzaSuggerito`, `aeroportoDestinazioneSuggerito`, `dataPartenzaSuggerita`, `dataRitornoSuggerita`, `note`).
- `src/Neuron/ReceptionistAgent.php` + `TurnoReceptionist.php` — agente di raccolta (anagrafica opzionale, suggerimenti del consulente come default) e suo DTO: `risposta`, `nome`, `cognome`, `email`, `destinazione`, `aeroportoPartenza`, `aeroportoDestinazione`, `dataPartenza`, `dataRitorno`, `adulti`, `bambini` (nullable), `confermato` (bool, obbligatorio).
- `src/Neuron/VoliAgent.php` + `TurnoVolo.php` — agente voli con tool MCP FlightX e suo DTO (`risposta`, parametri ricerca, `ricercaCompletata`, `voloSelezionato`, `confermato`).
- `src/Neuron/HotelAgent.php` + `TurnoHotel.php` — agente hotel con tool MCP Hotelbeds e suo DTO (`risposta`, `hotelRichiesto`, `dataCheckIn`, `dataCheckOut`, `camere`, `etaBambini` CSV, `hotelSelezionato`, `confermato`).
- `flightx-mcp.php` — server MCP su stdio (JSON-RPC 2.0 newline-delimited) con il tool `cerca_voli`; risponde SOLO su STDOUT, diagnostica su STDERR. Credenziali `FLIGHTX_*` passate dal connettore. Max 5 opzioni, prezzi in EUR, timestamp di recupero.
- `hotelbeds-mcp.php` — server MCP su stdio con il tool `cerca_hotel` (geocoding Nominatim + disponibilità Hotelbeds, raggio 20 km, max 5 opzioni). Credenziali `HOTELBEDS_*` e `NOMINATIM_*` passate dal connettore.
- `src/MCP/LineStdioTransport.php` — transport MCP stdio che accumula la risposta finché non è JSON completo (lo `StdioTransport` di Neuron fallisce con payload oltre 4 KB).
- `src/Services/FlightX/` — wrapper delle API FlightX (`FlightXClient`, `FlightXConfig`, gerarchia `Exceptions`): stateful (token JWT + ultima ricerca), validazione locale IATA/date/passeggeri, layer HTTP Guzzle, password in chiaro oppure pre-hashata (`passwordMd5`).
- `src/Services/Hotelbeds/` — wrapper delle API Hotelbeds (`HotelbedsClient`, `HotelbedsConfig`, gerarchia `Exceptions`): stateless (firma `X-Signature` = sha256(apiKey + secret + timestamp) ricalcolata a ogni richiesta), 4 varianti di ricerca disponibilità + `checkRates()`, validazioni locali (date, coordinate, occupazioni, età bambini 0-17), layer HTTP Guzzle. Sola lettura: nessuna prenotazione.
- `src/Services/Geocoding/` — wrapper dell'API Nominatim/OpenStreetMap (`NominatimClient`, `NominatimConfig`, gerarchia `Exceptions`): `geocode()`/`search()` con coordinate normalizzate a float, throttle di processo (1 richiesta/secondo, `$sleeper` iniettabile nei test), `userAgent` obbligatorio per policy d'uso.
- `src/Support/Pratica.php` — persistenza della pratica: un file `data/pratica_YYYYmmdd_His.json` creato dal ValidazioneNode e aggiornato (`aggiorna()`) a ogni selezione (volo, hotel); `apri()` riapre un file esistente (dopo un resume). I file esistenti non vengono mai eliminati. Struttura: `utente` (nullable), `viaggio`, `volo_selezionato`, `hotel_selezionato`, `raccolto_il`.
- `src/Support/StoriaChat.php` — chat history serializzabile in JSON (`daJson`/`daChatHistory`) per attraversare le interruzioni del workflow.
- `src/Support/Validazione.php` — regole di validazione condivise: campi viaggio (obbligatori), anagrafica (opzionale, solo sanificata), dati hotel, `parseEtaBambini`.
- `src/Support/LlmRetry.php` — retry con backoff esponenziale su HTTP 429 (4 tentativi, poi exit 1), usato dai nodi conversazionali.
- `src/Support/Archivio.php` — persistenza minimale su file JSON (storico, non più usata; resta coperta dai test).
- `tests/` — oltre ai test preesistenti: `NodoTestCase.php` (base con factory di agenti finti a risposte sequenziate), `ConsulenteNodeTest`, `ReceptionistNodeTest`, `ValidazioneNodeTest`, `VoliNodeTest`, `HotelNodeTest`, `TravelWorkflowTest` (end-to-end con interrupt/resume), `ValidazioneTest`, `StoriaChatTest`, `TurnoConsulenteTest`, `PraticaTest` (con `apri`).
- `data/pratica_*.json` — pratiche raccolte a runtime (git-ignored); `data/workflow/` — stato dei workflow interrotti (git-ignored); `data/utenti.json` e `data/viaggi.json` restano come storico non più scritto.

## Build e test

Non esiste una fase di build; Composer gestisce tutto.

```bash
composer install        # installa le dipendenze
php chat.php            # avvia la chat interattiva (richiede .env)
vendor/bin/phpunit      # esegue la suite di test (82 test, configurazione in phpunit.xml)
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
- Nei nodi del workflow: niente agenti o Closure in proprietà serializzate; la chat history vive nello `WorkflowState` come JSON; al resume si consuma la richiesta (`inputRipresa()`) e si rientra nel loop senza rieseguire il turno LLM.

## Strategia di testing

- Suite PHPUnit configurata in `phpunit.xml` (bootstrap `vendor/autoload.php`, cache in `.phpunit.cache`, tutta la directory `tests`).
- I test **non effettuano chiamate HTTP reali**: `tests/FakeProvider.php` implementa `AIProviderInterface` restituendo un `AssistantMessage` predefinito con usage fittizio; `tests/AgenteFinto.php` lo incapsula in un agente.
- I nodi conversazionali accettano una factory di agenti nel costruttore: `tests/NodoTestCase.php` fornisce `agenteConRisposte(...)` (risposte JSON in sequenza) e `invoca()` (esegue il nodo catturando il `WorkflowInterrupt`, o simula il resume passando una `RichiestaInput`).
- `tests/TravelWorkflowTest.php` guida l'intero workflow attraverso interrupt/resume con `InMemoryPersistence` e nodi finti: copre il flusso completo (consulenza → prenotazione → volo → rinuncia hotel) e il rifiuto senza salvataggio.
- I test FlightX/Hotelbeds/Geocoding sono offline: `FlightXClientTest`, `HotelbedsClientTest` e `NominatimClientTest` coprono solo le validazioni locali; `FlightXMcpServerTest` e `HotelbedsMcpServerTest` avviano i server MCP come processi e verificano handshake e `tools/list` senza mai invocare i tool.
- I test di persistenza (`ArchivioTest`, `PraticaTest`) e dei nodi che scrivono pratiche usano file temporanei in `sys_get_temp_dir()` con pulizia in `tearDown()`: mai toccare i file in `data/` nei test.

## Considerazioni di sicurezza

- `.env` contiene la chiave API OpenRouter e le credenziali FlightX/Hotelbeds ed è escluso da git (`.gitignore` copre `/vendor/`, `.env`, `/data/`): non committarlo mai e non leggerne il contenuto per esporlo.
- Le credenziali FlightX e Hotelbeds passano ai server MCP solo come variabili d'ambiente del processo figlio; i wrapper le oscurano nei log (`redact()`). Il `secret` Hotelbeds non è mai inviato in rete: serve solo a calcolare la firma `X-Signature`.
- I wrapper FlightX e Hotelbeds sono di sola consultazione: non espongono prenotazione (`bookItem`/`POST /bookings`) né emissione biglietti (`issueTickets`); la selezione di volo e hotel è solo registrata nella pratica, senza operazioni verso i fornitori.
- I dati raccolti (anagrafiche opzionali, viaggi e selezioni degli utenti) sono dati personali salvati in chiaro in `data/pratica_*.json`, git-ignored. Lo stato dei workflow in `data/workflow/` può contenere la chat history serializzata: anch'esso git-ignored.
- La validazione accetta solo lettere, spazi, apostrofi e trattini (regex Unicode `/^[\p{L}][\p{L}\s\'-]*$/u`) per nome, cognome e destinazione; l'email è validata con `filter_var(..., FILTER_VALIDATE_EMAIL)`; gli aeroporti devono essere codici IATA di 3 lettere; le date devono essere `YYYY-MM-DD` valide (il ritorno non può precedere la partenza; il check-out hotel deve essere successivo al check-in); i passeggeri devono includere almeno 1 adulto e non superare 9 in totale; le età dei bambini per l'hotel devono essere tante quanti sono i bambini (0-17). Il salvataggio avviene solo a validazione superata.

# Neuron CLI Test

Applicazione CLI PHP didattica basata sul framework [Neuron AI](https://github.com/neuron-core/neuron-ai). La conversazione è orchestrata da un **Workflow event-driven nativo di Neuron**: quattro agenti in italiano lavorano in sequenza come nodi del grafo, e lo scambio con l'utente avviene tramite interruzioni human-in-the-loop (`WorkflowInterrupt` + resume):

1. **Consulente** — risponde a domande aperte sui viaggi ("Ho 10 giorni di ferie e voglio andare al mare, dove mi consigli di andare?") usando solo la conoscenza del modello, senza tool; quando l'utente è pronto a prenotare, passa i suggerimenti emersi (destinazione, aeroporti, date) alla raccolta dati
2. **Receptionist** — raccoglie **destinazione, aeroporti (IATA) di partenza e destinazione, data di partenza (ed eventuale ritorno), adulti e bambini**, proponendo come default i suggerimenti del consulente; l'anagrafica (nome, cognome, email) è **opzionale**; chiede conferma esplicita del ricapitolo
3. **Voli** — riceve tutti i parametri già raccolti, chiede una sola conferma e cerca i voli tramite il **server MCP FlightX**; l'utente sceglie una delle opzioni e la scelta è registrata nella pratica (nessun dossier né prenotazione)
4. **Hotel** — chiede se serve un hotel e, in caso affermativo, cerca tramite il **server MCP Hotelbeds** (geocoding Nominatim + disponibilità Hotelbeds) e fa scegliere una delle opzioni, registrata nella pratica

I dati validati e le selezioni sono salvati in `data/pratica_YYYYmmdd_His.json` (un file per pratica, con `utente` eventualmente `null`). In qualsiasi prompt si può digitare `riepilogo` (o `servizi`) per vedere i dati raccolti e i servizi selezionati finora, senza consumare token.

## Caratteristiche

- **Workflow Neuron nativo**: nodi ed eventi tipizzati (`src/Workflow/`), interruzioni human-in-the-loop, stato del workflow persistito su disco (`FilePersistence` in `data/workflow/`) tra un turno e l'altro
- Conversazione in italiano con system prompt strutturato (`SystemPrompt`)
- Structured output ad ogni turno: ogni agente risponde con un oggetto tipizzato (`TurnoConsulente`, `TurnoReceptionist`, `TurnoVolo`, `TurnoHotel`), non con testo libero
- Anagrafica opzionale: la validazione blocca solo sui dati del viaggio
- Conferma esplicita dei dati prima del salvataggio
- Validazione nei nodi: destinazione (solo lettere, spazi, apostrofi e trattini), aeroporti (IATA di 3 lettere), date (`YYYY-MM-DD`, ritorno non precedente alla partenza, check-out successivo al check-in), passeggeri (almeno 1 adulto, massimo 9 totali), età dei bambini (0-17) per la ricerca hotel
- Chat history degli agenti serializzata in JSON nello stato del workflow e ripristinata a ogni turno (`src/Support/StoriaChat.php`)
- Persistenza su un file JSON per pratica (`data/pratica_*.json`), creato alla validazione e aggiornato a ogni selezione
- Conteggio dei token (input / output / totale) sotto ogni risposta dell'agente
- Comando `riepilogo` per visualizzare i servizi selezionati + riepilogo finale prima dell'uscita
- Limite configurabile di iterazioni con chiusura automatica; il contatore riparte da `#1` a ogni fase
- Ricerca voli e hotel via **MCP**: server stdio (`flightx-mcp.php`, `hotelbeds-mcp.php`) che espongono i wrapper FlightX e Hotelbeds/Nominatim come tool, collegati agli agenti con `McpConnector`
- Retry con backoff esponenziale in caso di rate limit (HTTP 429)
- Colori ANSI (disattivabili con `NO_COLOR=1`)
- Suite di test PHPUnit senza chiamate HTTP reali (provider finto, workflow end-to-end con interrupt/resume)

## Requisiti

- PHP >= 8.1
- Composer
- Una chiave API [OpenRouter](https://openrouter.ai/)

## Installazione

```bash
git clone https://github.com/miziomon/neuron-cli-test.git
cd neuron-cli-test
composer install
```

## Configurazione

Creare un file `.env` nella radice del progetto:

```dotenv
OPENROUTER_API_KEY=sk-or-...
OPENROUTER_MODEL=openai/gpt-5.6-luna   # un qualsiasi modello disponibile su OpenRouter
MAX_ITERAZIONI=6                        # opzionale, default 6

# Credenziali FlightX (fase voli, ricerca via MCP)
FLIGHTX_BASE_URL=https://api.stage.flightx.app
FLIGHTX_API_KEY=...
FLIGHTX_USERNAME=...
FLIGHTX_PASSWORD_MD5=...                # password già hashata con md5(strtolower(...))

# Credenziali Hotelbeds + Nominatim (fase hotel, ricerca via MCP)
HOTELBEDS_BASE_URL=https://api.test.hotelbeds.com/hotel-api/1.0
HOTELBEDS_API_KEY=...
HOTELBEDS_SECRET=...
NOMINATIM_USER_AGENT=neuron-travel-cli/1.0   # obbligatorio per policy Nominatim
NOMINATIM_EMAIL=...                          # opzionale
```

Le variabili d'ambiente reali hanno precedenza sui valori del file `.env`:

```bash
OPENROUTER_MODEL='altro/modello' MAX_ITERAZIONI=3 php chat.php
```

Il modello deve supportare lo structured output (`response_format` JSON).

## Utilizzo

```bash
php chat.php
```

Il consulente si presenta e risponde alle domande sui viaggi; quando l'utente è pronto a prenotare, il receptionist raccoglie i dati del viaggio (proponendo come default i suggerimenti della consulenza, anagrafica opzionale) con ricapitolo e conferma; poi l'agente voli cerca le opzioni disponibili e fa scegliere un volo; infine l'agente hotel propone la ricerca del soggiorno. I dati e le selezioni vengono salvati in `data/pratica_YYYYmmdd_His.json`. Per vedere i servizi selezionati in qualsiasi momento: `riepilogo`. Per uscire manualmente: `esci`, `exit` o `quit`.

Codici di uscita:

| Codice | Significato |
|--------|-------------|
| `0` | Dati raccolti e salvati, oppure uscita volontaria |
| `1` | Errore di configurazione o di comunicazione con il modello |
| `2` | Raggiunto il numero massimo di iterazioni senza raccogliere i dati |

## Test

```bash
vendor/bin/phpunit
```

I test non effettuano chiamate HTTP: usano un provider finto (`tests/FakeProvider.php`), factory di agenti finti a risposte sequenziate per i nodi, un test end-to-end del workflow guidato da interrupt/resume (`tests/TravelWorkflowTest.php`) e file temporanei per la persistenza.

## Struttura del progetto

```
chat.php                        # entry point: ciclo run → WorkflowInterrupt → input → resume
flightx-mcp.php                 # server MCP stdio che espone il tool FlightX (cerca_voli)
hotelbeds-mcp.php               # server MCP stdio che espone il tool hotel (cerca_hotel)
src/Workflow/TravelWorkflow.php # il grafo: consulente → receptionist → validazione → voli → hotel → persistenza
src/Workflow/RichiestaInput.php # InterruptRequest: messaggio all'utente + input al resume
src/Workflow/Events/            # eventi tipizzati (EventoConsulenza, EventoRaccoltaDati, EventoDaValidare, ...)
src/Workflow/Nodes/             # nodi del workflow (ConsulenteNode, ReceptionistNode, ValidazioneNode, VoliNode, HotelNode, PersistenzaNode)
src/Neuron/OpenRouterAgent.php  # classe base astratta con il provider OpenRouter condiviso
src/Neuron/ConsulenteAgent.php  # agente consulente di viaggi (nessun tool) + TurnoConsulente
src/Neuron/ReceptionistAgent.php  # agente di raccolta (viaggio; anagrafica opzionale) + TurnoReceptionist
src/Neuron/VoliAgent.php        # agente voli: tool MCP FlightX + system prompt (+ TurnoVolo)
src/Neuron/HotelAgent.php       # agente hotel: tool MCP Hotelbeds + system prompt (+ TurnoHotel)
src/MCP/LineStdioTransport.php  # transport MCP stdio con buffer (gestisce risposte > 4 KB)
src/Services/FlightX/           # wrapper delle API FlightX (client, config, eccezioni)
src/Services/Hotelbeds/         # wrapper delle API Hotelbeds (client, config, eccezioni)
src/Services/Geocoding/         # wrapper dell'API Nominatim (client, config, eccezioni)
src/Support/Pratica.php         # persistenza della pratica: un file JSON per pratica (crea/apri/aggiorna)
src/Support/StoriaChat.php      # chat history serializzabile in JSON per lo stato del workflow
src/Support/Validazione.php     # regole di validazione condivise dai nodi
src/Support/LlmRetry.php        # retry con backoff su HTTP 429
src/Support/Archivio.php        # persistenza su file JSON (storico, non più usato)
tests/                          # suite PHPUnit
data/pratica_*.json             # pratiche raccolte a runtime (git-ignored)
data/workflow/                  # stato dei workflow interrotti (git-ignored)
```

## Sicurezza

- Il file `.env` contiene le chiavi API ed è escluso da git: non committarlo mai.
- `data/pratica_*.json` contiene dati personali in chiaro ed è anch'esso escluso da git.

## Changelog

Vedi [CHANGELOG.md](CHANGELOG.md).

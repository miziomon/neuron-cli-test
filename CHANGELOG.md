# Changelog

Tutte le modifiche rilevanti a questo progetto sono documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/it/1.1.0/)
e questo progetto aderisce al [Semantic Versioning](https://semver.org/lang/it/).

## [0.9.0] - 2026-08-21

### Aggiunto

- **Migrazione a Workflow event-driven nativo di Neuron** (`src/Workflow/`): le fasi sono nodi (`ConsulenteNode`, `ReceptionistNode`, `ValidazioneNode`, `VoliNode`, `HotelNode`, `PersistenzaNode`) collegati da eventi tipizzati in `src/Workflow/Events/`; la conversazione avviene tramite `WorkflowInterrupt` con la richiesta `RichiestaInput` (human-in-the-loop) e persistenza dello stato su disco (`FilePersistence` in `data/workflow/`)
- **Fase iniziale di consulenza viaggi** (`ConsulenteAgent` + DTO `TurnoConsulente`): risponde a domande aperte e consequenziali con la sola conoscenza del modello (nessun tool MCP); quando l'utente è pronto a prenotare, i suggerimenti emersi (destinazione, aeroporti IATA, date) vengono proposti come default al receptionist
- `src/Support/StoriaChat.php`: chat history degli agenti serializzata in JSON nello `WorkflowState` e ripristinata a ogni turno (gli agenti non sono serializzabili: il provider HTTP contiene closure)
- `src/Support/Validazione.php`: regole di validazione condivise dai nodi (viaggio, hotel, età bambini, anagrafica sanificata)
- `src/Support/LlmRetry.php`: retry con backoff esponenziale su HTTP 429 condiviso dai nodi
- `Pratica::apri()`: riapre una pratica esistente per aggiornarla dopo un resume del workflow
- Test: nodi isolati con factory di agenti finti a risposte sequenziate (`tests/NodoTestCase.php`), validazione, round-trip della history, workflow end-to-end guidato da interrupt/resume con `InMemoryPersistence` (82 test, 261 asserzioni)

### Cambiato

- **Anagrafica non più obbligatoria**: nome, cognome ed email non bloccano la prenotazione; se forniti vengono salvati, altrimenti la pratica ha `utente = null`; la validazione riguarda solo i dati del viaggio
- `chat.php` riscritto come runner del workflow (`run()` → `WorkflowInterrupt` → input utente → `resume()`); i comandi `riepilogo`/`servizi` ed `esci` restano locali al CLI e non consumano turni LLM
- La pratica JSON è creata alla validazione dei dati del viaggio (invece che subito dopo il receptionist) e aggiornata a ogni selezione

## [0.8.0] - 2026-08-21

### Aggiunto

- **Terza fase di conversazione (`HotelAgent`)**: chiede se serve un hotel, propone come default le date del volo, chiede le età dei bambini (0-17) quando presenti e fa scegliere una delle opzioni trovate; DTO `TurnoHotel`
- Server MCP stdio (`hotelbeds-mcp.php`) con il tool `cerca_hotel`: geocodifica la destinazione con Nominatim e interroga la disponibilità Hotelbeds (raggio 20 km, max 5 opzioni, prezzi in EUR, timestamp di recupero)
- Wrapper Hotelbeds (`src/Services/Hotelbeds/`): ricerca disponibilità in 4 varianti + `checkRates()`, firma `X-Signature` ricalcolata a ogni richiesta, validazioni locali; portato da Illuminate/Carbon a Guzzle puro come FlightX. Sola lettura: nessuna prenotazione
- Wrapper Geocoding Nominatim (`src/Services/Geocoding/`): `geocode()`/`search()` con throttle di processo (1 richiesta/secondo) e `userAgent` obbligatorio per policy d'uso
- Persistenza per utente (`src/Support/Pratica.php`): un file `data/pratica_YYYYmmdd_His.json` con anagrafica, viaggio e selezioni (volo, hotel), creato dopo la fase receptionist e aggiornato a ogni selezione; i file esistenti non vengono mai eliminati
- Comando `riepilogo` (o `servizi`) disponibile a ogni prompt: mostra anagrafica, viaggio e servizi selezionati senza chiamare il modello né consumare iterazioni
- Test: validazioni locali di `HotelbedsClient` e `NominatimClient`, handshake del server MCP hotel, persistenza `Pratica` (42 test, 103 asserzioni)

### Cambiato

- **Selezione del volo senza dossier**: `VoliAgent` fa scegliere una delle opzioni trovate e la registra nella pratica (`voloSelezionato` in `TurnoVolo`); rimosso il tool `seleziona_volo` dal server MCP e ogni riferimento al dossier temporaneo
- `data/utenti.json` e `data/viaggi.json` non sono più scritti: sostituiti dalla pratica per utente (restano come storico)

## [0.7.0] - 2026-08-21

### Cambiato

- System prompt dei due agenti potenziati: anno corrente iniettato via `date('Y')` (giorno+mese senza anno → anno corrente senza chiedere), risposta esatta per richieste fuori ambito ("Posso aiutarti solo con domande relative a viaggi e spostamenti.")
- Receptionist: risolve autonomamente città → codici IATA senza domanda dedicata (conferma nel ricapitolo), propone il default passeggeri (2 adulti, 0 bambini), assume sola andata se il ritorno non è menzionato
- VoliAgent: elenco di max 5 opzioni con etichette "Più economico"/"Più veloce", raccomandazione in max 2 frasi, sezione "Punti di forza della destinazione" (3-5 punti, distinta dai dati verificati), avviso che i prezzi possono variare fino alla prenotazione, regole anti prompt-injection sull'output dei tool, divieto di prenotazioni e di raccolta dati di pagamento/documenti, disclaimer su visti e frontiere
- Il formatter del server MCP restituisce 5 opzioni (invece di 10) con timestamp di recupero dati

## [0.6.0] - 2026-08-20

### Corretto

- Conferma con dati non validi non più trattata come rifiuto: i campi valorizzati ma malformati (email, IATA, date, passeggeri) vengono elencati e rimandati all'agente, che chiede la correzione e una nuova conferma invece di uscire senza salvare
- I campi opzionali restituiti dal modello come stringa vuota (es. `dataRitorno: ""` per la sola andata) sono normalizzati a `null` prima della validazione

### Cambiato

- **Ristrutturazione degli agenti**: `NeuronAgent` e `ViaggioAgent` unificati in `ReceptionistAgent`, che raccoglie in un'unica conversazione anagrafica, destinazione, aeroporti IATA di partenza/destinazione, data di partenza (ed eventuale ritorno), adulti e bambini
- `VoliAgent` riceve ora tutti i parametri di ricerca già raccolti dal receptionist: chiede una sola conferma e cerca i voli
- Il record viaggio in `data/viaggi.json` contiene i parametri di ricerca completi (`aeroporto_partenza`, `aeroporto_destinazione`, `data_partenza`, `data_ritorno`, `adulti`, `bambini`) al posto di `numero_persone` e `periodo`
- Validazioni CLI estese: codici IATA, date `YYYY-MM-DD` (ritorno non precedente alla partenza), regole passeggeri (almeno 1 adulto, massimo 9 totali)
- `TurnoVolo` include il numero dei bambini

### Rimosso

- `NeuronAgent`, `ViaggioAgent` e i DTO `TurnoAgente`/`TurnoViaggio` (sostituiti da `ReceptionistAgent`/`TurnoReceptionist`)
- Il `periodo` a testo libero: sostituito dalle date precise di partenza e ritorno

## [0.5.0] - 2026-08-20

### Cambiato

- Rimosso il saluto statico iniziale della CLI: la presentazione è affidata al primo agente
- Rimossi (commentati) gli echo di transizione tra una fase e la successiva
- Il secondo agente non si presenta più: chiede subito i dati del viaggio
- Il messaggio di avvio delle fasi successive alla prima non è più un saluto ("Ciao!") ma un avvio di contesto

## [0.4.0] - 2026-08-20

### Aggiunto

- Terza fase di conversazione (`VoliAgent`): ricerca dei voli sulla base del viaggio raccolto, con conferma esplicita di aeroporti (IATA), date e passeggeri
- Server MCP stdio (`flightx-mcp.php`) che espone i servizi FlightX come tool `cerca_voli` (elenco voli in formato leggibile) e `seleziona_volo` (verifica disponibilità + dossier temporaneo, nessuna prenotazione)
- Wrapper FlightX (`src/Services/FlightX/`) adattato per l'uso standalone: layer HTTP portato da Illuminate a Guzzle, supporto a password pre-hashata (`passwordMd5`), logging su STDERR con credenziali oscurate
- `App\MCP\LineStdioTransport`: transport MCP con accumulo della risposta fino a JSON completo (risolve il limite di `StdioTransport` di Neuron con payload oltre 4 KB)
- Test: validazioni locali di `FlightXClient` e handshake del server MCP (18 test, 59 asserzioni)

## [0.3.0] - 2026-08-20

### Aggiunto

- Riepilogo finale dei dati raccolti (anagrafica + viaggio) stampato prima dell'uscita

### Cambiato

- Estratta la classe base astratta `OpenRouterAgent` con il provider OpenRouter condiviso: `NeuronAgent` e `ViaggioAgent` ora definiscono solo il proprio system prompt

## [0.2.0] - 2026-08-20

### Aggiunto

- Raccolta dell'**email** nella fase anagrafica, con validazione lato CLI tramite `filter_var` (`FILTER_VALIDATE_EMAIL`)
- Secondo agente `ViaggioAgent` che parte solo dopo il completamento della prima fase e raccoglie **destinazione, numero di persone e periodo** del viaggio
- Persistenza dei viaggi in `data/viaggi.json`, collegati all'utente tramite l'email
- Il contatore delle iterazioni riparte da `#1` a ogni fase
- Gestione del rifiuto esplicito dell'utente: chiusura gentile senza salvataggio
- `Archivio::salva()` ora accetta un record generico (`array`) invece di nome/cognome fissi
- Test per il DTO `TurnoViaggio` e per i record di viaggio; helper condiviso `AgenteFinto`

## [0.1.0] - 2026-08-20

### Aggiunto

- Chat CLI interattiva basata su Neuron AI con provider OpenRouter (`OpenAILike`)
- Agente conversazionale in italiano con l'obiettivo di raccogliere nome e cognome
- Structured output ad ogni turno tramite il DTO `TurnoAgente` (`risposta`, `nome`, `cognome`, `confermato`)
- Conferma esplicita dei dati prima del salvataggio
- Validazione lato CLI di nome e cognome (regex Unicode: lettere, spazi, apostrofi, trattini)
- Persistenza dei dati confermati in `data/utenti.json` (`Archivio`)
- Conteggio dei token (input / output / totale) sotto ogni risposta dell'agente
- Limite di iterazioni configurabile (`MAX_ITERAZIONI`, default 6) con chiusura automatica della chat (exit code 2)
- Numerazione progressiva delle risposte dell'agente (`#1 Neuron: ...`)
- Retry con backoff esponenziale in caso di rate limit (HTTP 429)
- Colori ANSI con supporto Windows VT100 e disattivazione tramite `NO_COLOR=1`
- Caricamento del file `.env` con precedenza delle variabili d'ambiente reali
- Suite di test PHPUnit con provider finto senza chiamate HTTP reali

[0.9.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/miziomon/neuron-cli-test/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/miziomon/neuron-cli-test/releases/tag/v0.1.0

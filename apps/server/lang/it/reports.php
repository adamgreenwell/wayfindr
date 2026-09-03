<?php

return [
    'document_title' => 'Report',
    'title' => 'Report',
    'subtitle' => 'Quanto supporto è arrivato, quanto rapidamente ha ricevuto risposta e chi se ne è occupato.',

    'tabs' => [
        'region' => 'Sezioni del report',
        'volume' => 'Volume',
        'speed' => 'Velocità',
        'tickets' => 'Ticket',
        'agents' => 'Agenti',
        'satisfaction' => 'Soddisfazione',
    ],

    'range' => [
        'heading' => 'Intervallo',
        'one_site' => 'Un sito',
        'all_sites' => 'Tutti i siti visibili',
        'period' => 'Periodo',
        'last_days' => '{1} Ultimo :count giorno|[0,*] Ultimi :count giorni',
        'site' => 'Sito',
        'archived_sites' => 'Siti archiviati',
        'report' => 'Report',
        'apply' => 'Applica',
        'reset' => 'Reimposta',
    ],

    'history' => [
        'heading' => 'Fin dove arrivano questi numeri',
        'lede' => 'Non tutti i dati risalgono allo stesso momento',
        'opened' => 'Conversazioni aperte',
        'opened_detail' => 'e',
        'first_response' => 'tempi di prima risposta',
        'first_response_detail' => 'sono recuperabili dall’intera cronologia di questa installazione.',
        'lifecycle' => 'Chiusure, tempi di risoluzione e riaperture',
        'lifecycle_with_date' => 'provengono dai record del ciclo di vita, che questa installazione conserva dal :date. Tutto ciò che precede quella data non è registrato, non necessariamente assente: le conversazioni venivano chiuse, ma nulla ne conservava la sequenza, che non può essere ricostruita in seguito.',
        'lifecycle_without_date' => 'provengono dai record del ciclo di vita, ma questa installazione non ha registrato quando ha iniziato a conservarli. Esegua le migrazioni in sospeso; fino ad allora questi valori coprono solo ciò che risulta registrato.',
        'purge' => 'L’eliminazione definitiva di un sito rimuove anche la sua cronologia, quindi un totale può legittimamente diminuire.',
    ],

    'counts' => [
        'opened' => '{1} :count aperta|[0,*] :count aperte',
        'closed' => '{1} :count chiusa|[0,*] :count chiuse',
        'created' => '{1} :count creato|[0,*] :count creati',
        'open_now' => '{1} :count aperto ora|[0,*] :count aperti ora',
        'tickets_created' => '{1} :count ticket creato|[0,*] :count ticket creati',
        'tickets_closed' => '{1} :count ticket chiuso|[0,*] :count ticket chiusi',
        'tickets_open_now' => '{1} :count ticket aperto ora|[0,*] :count ticket aperti ora',
        'opened_label' => 'Aperte',
        'closed_label' => 'Chiuse',
        'created_label' => 'Creati',
        'tickets_closed_label' => 'Chiusi',
        'measured' => '{1} :count misurata|[0,*] :count misurate',
        'closes_measured' => '{1} :count chiusura misurata|[0,*] :count chiusure misurate',
        'agents' => '{1} :count agente|[0,*] :count agenti',
        'comments' => '{1} :count commento|[0,*] :count commenti',
    ],

    'charts' => [
        'tallest_day' => 'Picco giornaliero: :count',
    ],

    'metrics' => [
        'median' => 'Mediana',
        'p90' => '90º percentile',
        'slowest_tenth' => 'Il decimo più lento ha impiegato almeno questo tempo.',
        'unmeasured' => 'Conteggio senza misurazione',
        'reopened' => 'Riaperture',
        'reopened_detail' => 'Una risoluzione che non ha retto.',
    ],

    'duration' => [
        'seconds' => '{1} :count secondo|[0,*] :count secondi',
        'minutes' => '{1} :count minuto|[0,*] :count minuti',
        'hours' => '{1} :count ora|[0,*] :count ore',
        'days' => '{1} :count giorno|[0,*] :count giorni',
    ],

    'conversations' => [
        'volume' => [
            'heading' => 'Volume delle conversazioni',
            'empty' => 'In questo periodo non sono state aperte o chiuse conversazioni.',
            'chart_aria' => 'Conversazioni al giorno. :opened aperte e :closed chiuse nei :days giorni fino al :date. Il giorno più intenso ne ha avute :busiest.',
            'day_title' => ':date: :opened aperte, :closed chiuse',
            'export' => 'Esporta la serie giornaliera in CSV',
        ],
        'queue' => [
            'heading' => 'In attesa ora',
            'lede' => 'Un conteggio in tempo reale, non una tendenza',
            'empty' => 'Nessuna conversazione attende una risposta.',
            'waiting' => '{1} :count conversazione è in attesa sul desk, da :duration nel caso più vecchio.|[0,*] :count conversazioni sono in attesa sul desk, da :duration nel caso più vecchio.',
            'threshold' => '{1} Come riferimento, gli avvisi di mancata assistenza scattano quando una conversazione resta in attesa senza essere vista per :count minuto. Questo conteggio include ogni conversazione in attesa, qualunque sia la sua età.|[0,*] Come riferimento, gli avvisi di mancata assistenza scattano quando una conversazione resta in attesa senza essere vista per :count minuti. Questo conteggio include ogni conversazione in attesa, qualunque sia la sua età.',
        ],
        'response' => [
            'heading' => 'Prima risposta',
            'empty' => 'Nessuna conversazione aperta in questo periodo ha ancora ricevuto una prima risposta.',
            'median_detail' => 'Metà dei visitatori ha atteso meno.',
            'p90_detail' => 'Il decimo meno fortunato ha atteso almeno questo tempo.',
            'awaiting' => '{1} :count conversazione aperta in questo periodo non ha ricevuto alcuna risposta, quindi viene conteggiata qui invece di confluire nei valori sopra.|[0,*] :count conversazioni aperte in questo periodo non hanno ricevuto alcuna risposta, quindi vengono conteggiate qui invece di confluire nei valori sopra.',
        ],
        'resolution' => [
            'heading' => 'Risoluzione',
            'unmeasurable_empty' => '{1} :count conversazione è stata chiusa in questo periodo, ma era stata aperta prima che questa installazione iniziasse a registrare le riaperture, quindi non è possibile stabilire la durata del lavoro. I tempi di risoluzione appariranno quando verranno chiuse conversazioni aperte da allora.|[0,*] :count conversazioni sono state chiuse in questo periodo, ma erano state aperte prima che questa installazione iniziasse a registrare le riaperture, quindi non è possibile stabilire la durata del lavoro. I tempi di risoluzione appariranno quando verranno chiuse conversazioni aperte da allora.',
            'empty' => 'In questo periodo non è stata chiusa alcuna conversazione.',
            'median_detail' => 'Dall’apertura o dalla riapertura che ha avviato il tratto di lavoro.',
            'unmeasured_detail' => 'Chiuse prima che questa installazione iniziasse a registrare le riaperture, quindi non è possibile stabilire la durata del lavoro. Conteggiate come chiusure sopra; escluse dai due valori qui invece di gonfiarli.',
            'reopened_by_visitor' => 'Riaperta da un visitatore',
            'reopened_by_visitor_detail' => 'Il visitatore è tornato invece di una riapertura da parte di un agente: il segnale più chiaro che la risposta non è arrivata a destinazione.',
        ],
    ],

    'tickets' => [
        'volume' => [
            'heading' => 'Volume dei ticket',
            'empty_before_history' => 'In questo periodo non è stato creato alcun ticket e non risulta alcuna chiusura. Questa installazione registra le chiusure dei ticket dal :date, ma l’intervallo selezionato risale a prima: i ticket chiusi in precedenza non hanno lasciato tracce da conteggiare.',
            'empty' => 'In questo periodo non è stato creato o chiuso alcun ticket.',
            'chart_aria' => 'Ticket al giorno. :created creati e :closed chiusi nei :days giorni fino al :date. Il giorno più intenso ne ha avuti :busiest.',
            'day_title' => ':date: :created creati, :closed chiusi',
        ],
        'resolution' => [
            'heading' => 'Risoluzione dei ticket',
            'unmeasurable_empty' => '{1} :count ticket è stato chiuso in questo periodo, ma era stato aperto prima che questa installazione iniziasse a registrare le riaperture dei ticket, quindi non è possibile stabilire la durata del lavoro. I tempi di risoluzione appariranno quando verranno chiusi ticket aperti da allora.|[0,*] :count ticket sono stati chiusi in questo periodo, ma erano stati aperti prima che questa installazione iniziasse a registrare le riaperture dei ticket, quindi non è possibile stabilire la durata del lavoro. I tempi di risoluzione appariranno quando verranno chiusi ticket aperti da allora.',
            'reopened_unmeasurable' => '{1} :count ticket è stato riaperto in questo periodo: una risoluzione che non ha retto. È conteggiabile anche quando le durate non lo sono.|[0,*] :count ticket sono stati riaperti in questo periodo: risoluzioni che non hanno retto. Sono conteggiabili anche quando le durate non lo sono.',
            'reopened_without_close' => 'Una risoluzione che non ha retto. In questo periodo non è stato chiuso nulla, quindi non esiste un tempo di risoluzione da mostrare accanto.',
            'empty_before_history' => 'In questo periodo non risulta alcuna chiusura di ticket. Questa installazione registra le chiusure dei ticket dal :date, ma l’intervallo selezionato risale a prima: i ticket chiusi in precedenza non hanno lasciato tracce da conteggiare. Non equivale a dire che non sia accaduto nulla.',
            'empty' => 'In questo periodo non è stato chiuso alcun ticket.',
            'median_detail' => 'Metà dei ticket è stata risolta più rapidamente.',
            'unmeasured_detail' => 'Aperti prima che questa installazione iniziasse a registrare le riaperture dei ticket, quindi non è possibile stabilire la durata del lavoro. Esclusi dai due valori sopra invece di gonfiarli.',
            'reopened_detail' => 'Una risoluzione che non ha retto. Ogni riapertura avvia un nuovo episodio, quindi un ticket chiuso tre volte contribuisce con tre risoluzioni invece di una sola molto lunga.',
            'history' => 'Questa installazione registra chiusure e riaperture dei ticket dal :date. Un ticket aperto prima potrebbe essere stato chiuso e riaperto mentre nulla lo registrava, quindi viene conteggiato come chiusura ed escluso dai tempi qui.',
        ],
        'agents' => [
            'heading' => 'Chi ha svolto il lavoro sui ticket',
            'empty' => 'Nessuna risposta o chiusura di ticket in questo periodo.',
        ],
    ],

    'tables' => [
        'agent' => 'Agente',
        'replies' => 'Risposte inviate',
        'tickets_closed' => 'Ticket chiusi',
        'conversations_closed' => 'Conversazioni chiuse',
    ],

    'agents' => [
        'heading' => 'Chi ha svolto il lavoro',
        'empty' => 'In questo periodo nessun agente ha risposto a una conversazione o l’ha chiusa.',
        'removed' => 'Agente rimosso',
        'deactivated' => 'Disattivato',
        'deactivated_detail' => 'Gli agenti disattivati restano elencati: hanno svolto il lavoro, e un totale che cambia quando qualcuno lascia il team non è un totale affidabile.',
        'export' => 'Esporta in CSV',
    ],

    'satisfaction' => [
        'heading' => 'Se è stato utile',
        'summary' => '{1} Risposte: :answered su :closed chiusura|[0,*] Risposte: :answered su :closed chiusure',
        'no_closes' => 'In questo periodo non è stata chiusa alcuna conversazione, quindi non è stato chiesto nulla a nessuno.',
        'no_answers_before' => 'In questo periodo nessuno ha risposto. Non è un punteggio negativo: è l’assenza di un punteggio, e le due cose non vanno confuse. Se i suoi siti non pongono la domanda, attivi il prompt sotto',
        'setting' => 'Chiedere com’è andata',
        'no_answers_after' => 'nelle impostazioni di un sito.',
        'good' => 'Buono',
        'good_detail' => ':percentage delle persone che hanno risposto.',
        'ok' => 'Discreto',
        'ok_detail' => 'È stato utile, ma non è una storia che qualcuno racconterà.',
        'bad' => 'Negativo',
        'bad_detail' => 'La risposta che questa intera sezione esiste per far emergere.',
        'answered' => 'Risposte',
        'answered_detail' => '{1} Su :count chiusura. Ogni valore sopra rappresenta una quota di questo numero, mai delle chiusure: chi non ha detto nulla non viene considerato soddisfatto.|[0,*] Su :count chiusure. Ogni valore sopra rappresenta una quota di questo numero, mai delle chiusure: chi non ha detto nulla non viene considerato soddisfatto.',
        'small_sample' => 'Le risposte sono così poche che una sola in più cambierebbe sensibilmente la percentuale. Lo legga come una direzione, non come una misurazione.',
    ],

    'comments' => [
        'heading' => 'Cosa hanno detto le persone',
        'empty' => 'In questo periodo nessuno ha lasciato un commento. Il campo è facoltativo e la maggior parte delle persone lo salta: un punteggio senza parole resta comunque una risposta.',
        'score' => 'Punteggio',
        'said' => 'Cosa hanno detto',
        'conversation' => 'Conversazione',
        'when' => 'Quando',
        'latest' => '{1} Il :count commento più recente. Un punteggio dice che qualcosa è andato storto; questo è l’unico posto che dice cosa.|[0,*] I :count commenti più recenti. Un punteggio dice che qualcosa è andato storto; questo è l’unico posto che dice cosa.',
    ],
];

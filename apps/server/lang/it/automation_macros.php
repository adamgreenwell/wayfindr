<?php

return [
    'flash' => [
        'created' => 'Bozza della macro creata.',
        'updated' => 'Macro salvata.',
        'deleted' => 'Macro eliminata. La cronologia delle esecuzioni resta disponibile.',
        'applied' => 'Macro applicata.',
        'failed' => 'Impossibile applicare la macro. Nessuna modifica parziale è stata conservata.',
    ],
    'list' => [
        'heading' => 'Macro',
        'count' => '{0} Nessuna macro|{1} :count macro|[2,*] :count macro',
        'macro' => 'Macro',
        'work_type' => 'Tipo di attività',
        'actions' => 'Azioni',
        'order' => 'Ordine di visualizzazione',
        'status' => 'Stato',
        'manage' => 'Gestisci',
        'edit' => 'Modifica',
    ],
    'empty' => [
        'heading' => 'Ancora nessuna macro.',
        'body' => 'Crei una sequenza di azioni riutilizzabile da applicare a un ticket o a una conversazione.',
        'action' => 'Crea la prima macro',
    ],
    'create' => [
        'title' => 'Crea macro di automazione',
        'subtitle' => 'Raggruppi una breve sequenza di azioni interne che gli agenti possono eseguire con un clic.',
        'action' => 'Crea macro',
        'submit' => 'Crea bozza',
    ],
    'edit' => [
        'title' => 'Modifica macro di automazione',
        'title_named' => 'Modifica :name',
        'subtitle' => 'Mantenga la sequenza esplicita, ordinata e sicura per il tipo di attività selezionato.',
        'back' => 'Torna alle automazioni',
        'save' => 'Salva macro',
    ],
    'fields' => [
        'name' => 'Nome della macro',
        'name_help' => 'Usi un nome breve e orientato al risultato, riconoscibile durante il lavoro.',
        'subject_type' => 'Esegui su',
        'subject_type_help' => 'Le azioni riservate ai ticket, come etichette e note private, non sono disponibili per le conversazioni.',
        'position' => 'Ordine di visualizzazione',
        'position_help' => 'I numeri più bassi compaiono per primi nella pagina dell’attività.',
        'enabled' => 'Abilita questa macro',
        'enabled_help' => 'Le macro abilitate compaiono sulle attività compatibili per gli operatori autorizzati a eseguire ogni azione.',
    ],
    'builder' => [
        'heading' => 'Definizione della macro',
        'lede' => 'Un clic, poi ogni azione elencata dall’alto verso il basso.',
        'actions_help' => 'Le macro usano lo stesso insieme limitato di azioni delle regole e possono includere fino a dieci azioni.',
    ],
    'subject_types' => [
        'ticket' => 'Ticket',
        'conversation' => 'Conversazione',
    ],
    'apply' => [
        'heading' => 'Macro',
        'lede' => 'Applichi a questa attività una sequenza interna di azioni già approvata.',
        'run' => 'Applica',
        'action_count' => '{1} :count azione|[2,*] :count azioni',
    ],
    'execution' => [
        'kind' => 'Macro manuale per :type',
        'trigger' => 'Avvio manuale',
        'triggered_by' => 'Applicata da',
    ],
    'delete' => [
        'heading' => 'Elimina macro',
        'lede' => 'La macro scompare dalle attività di supporto, ma le esecuzioni precedenti restano nel registro.',
        'action' => 'Elimina macro',
    ],
    'validation' => [
        'heading' => 'Controlla la definizione della macro',
        'definition' => 'Questa definizione della macro non è valida: :detail',
        'duplicate' => 'Esiste già una macro con questo nome.',
    ],
];

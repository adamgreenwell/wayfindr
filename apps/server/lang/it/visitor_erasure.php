<?php

/* Bozza da lang/en/visitor_erasure.php. Revisionata secondo la sezione 9 di docs/product/translation-policy.md. */
return [
    'section' => [
        'heading' => 'Cancella questo contatto',
        'lede' => 'Per una richiesta di cancellare tutti i dati conservati su questa persona',
        'body' => 'La cancellazione elimina identità, conversazioni, messaggi, file caricati, note sul contatto e valutazioni di questo contatto. I ticket restano come attività di lavoro, senza riferimenti alla persona. Non è reversibile.',
        'link' => 'Verifica cosa verrà cancellato',
    ],
    'page' => [
        'title' => 'Cancella contatto',
        'heading' => 'Cancella questo contatto',
        'lede' => 'Verifichi cosa verrà eliminato, cosa resta e cosa Wayfindr non può raggiungere, poi confermi.',
        'back' => 'Torna al contatto',
    ],
    'deleted' => [
        'heading' => 'Eliminato',
        'identity' => 'Nome, email, ID visitatore host, ID browser e attributi personalizzati',
        'conversations' => 'Conversazioni',
        'messages' => 'Messaggi',
        'attachments' => 'File caricati',
        'notes' => 'Note sul contatto',
        'ratings' => 'Valutazioni',
        'cobrowse_sessions' => 'Sessioni di cobrowse',
        'also' => 'Vengono rimossi anche: gli avvisi che citano queste conversazioni, le consegne webhook in attesa che le riguardano e il testo di ogni voce di audit su di esse. Le voci di audit conservano chi ha fatto cosa e quando.',
    ],
    'tickets' => [
        'heading' => 'Ticket conservati senza riferimenti alla persona',
        'body' => 'Ciascuno conserva stato, priorità, etichette, assegnatario e collegamento all’issue esterna. L’oggetto diventa “Ticket #N (richiedente cancellato)”, e descrizione, dati del visitatore, codice di supporto e testo delle note vengono rimossi.',
        'empty' => 'Questo contatto non ha ticket.',
    ],
    'unreachable' => [
        'heading' => 'Cosa la cancellazione non può raggiungere',
        'backups' => 'I backup creati prima di adesso contengono ancora i dati di questa persona, e ripristinarne uno li riporta indietro.',
        'external' => 'Le issue esterne collegate conservano ciò che vi è stato copiato:',
        'mail' => 'Le risposte già inviate per email.',
        'ai' => 'Quanto il fornitore di IA ha conservato delle richieste al copilot.',
        'api' => 'Quanto un’integrazione API ha già recuperato.',
        'future' => 'Se la persona torna è un nuovo contatto, e una pagina host che invia il suo ID visitatore ricrea il record.',
    ],
    'form' => [
        'confirm' => 'Digiti :word per confermare',
        'password' => 'La sua password attuale',
        'submit' => 'Cancella definitivamente questo contatto',
    ],
    'errors' => [
        'confirm' => 'Digiti esattamente :word per confermare.',
        'alert_mail_sending' => 'Un\'email di avviso sulle conversazioni o sui ticket di questo contatto è in fase di invio a un agente. Non è stato cancellato nulla. Riprovi tra un paio di minuti.',
        'copilot_running' => 'L\'assistente IA sta lavorando su una conversazione di questo contatto, quindi la trascrizione potrebbe essere in invio al fornitore IA. Non è stato cancellato nulla. Riprovi tra qualche minuto.',
        'note_posting' => 'Una nota su un ticket di questo contatto è in fase di pubblicazione sulla issue collegata. Non è stato cancellato nulla. Riprovi tra un paio di minuti.',
    ],
    'ticket_subject' => 'Ticket #:number (richiedente cancellato)',
    'flash' => [
        'erased' => 'Contatto cancellato.',
        'receipt' => 'Riferimento della ricevuta: :receipt',
    ],
];

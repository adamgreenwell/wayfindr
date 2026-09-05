<?php

/* Bozza da lang/en/visitor_notes.php. NON ANCORA REVISIONATA. */
return [
    'heading' => 'Note sul contatto',
    'lede' => 'Contesto privato collegato a questa persona, non a un singolo ticket',
    'count' => '{0} Nessuna nota|{1} :count nota|[2,*] :count note',
    'boundary' => [
        'heading' => 'Contesto privato del team',
        'body' => 'Queste note sono visibili ai membri del team che possono aprire questo contatto. Non vengono mai inviate al visitatore o a un ticket esterno.',
        'care' => 'Registri solo ciò che serve al team per garantire continuità. Eviti password, dati di pagamento, informazioni sanitarie e altri dati sensibili non necessari.',
        'delete' => 'L’eliminazione di una nota ne rimuove definitivamente il contenuto. Il registro dell’account conserva solo una ricevuta di eliminazione priva del contenuto.',
    ],
    'form' => [
        'label' => 'Aggiungi una nota privata sul contatto',
        'placeholder' => 'Contesto utile al prossimo membro del team',
        'help' => 'Fino a 4.000 caratteri. Le note non possono essere modificate; aggiunga una correzione o elimini la nota.',
        'submit' => 'Aggiungi nota sul contatto',
    ],
    'empty' => [
        'heading' => 'Nessuna nota sul contatto',
        'body' => 'Qui apparirà il contesto duraturo del team relativo a questa persona.',
    ],
    'stale_page' => [
        'heading' => 'Questa pagina delle note non è più disponibile.',
        'action' => 'Vai all’ultima pagina delle note',
    ],
    'author_unknown' => 'Ex membro del team',
    'delete' => 'Elimina nota',
    'flash' => [
        'added' => 'Nota sul contatto aggiunta.',
        'deleted' => 'Nota sul contatto eliminata. Il contenuto è stato rimosso definitivamente.',
    ],
    'errors' => [
        'required' => 'Inserisca una nota sul contatto prima di salvare.',
    ],
];

<?php

/*
 * Bozza tratta da lang/en/operator_access.php. NON ANCORA REVISIONATA.
 *
 * Scritta a mano seguendo il glossario in resources/translation/glossary.php
 * e le regole in docs/product/translation-policy.md. I dati dell’account
 * (riferimenti dell’ambito, nomi e motivi) non appartengono al catalogo; la
 * view li contrassegna con una lingua sconosciuta.
 *
 * I controlli usano l’imperativo diretto; la prosa esplicativa usa il registro
 * formale. «Gestore» e «agente» restano ruoli distinti come richiede il glossario.
 */

return [
    'document_title' => 'Accesso del gestore',
    'title' => 'Accesso del gestore',
    'subtitle' => 'Quando un gestore della piattaforma deve vedere i dati di supporto di questo account, deve farne richiesta. Approvi, rifiuti o termini qui l’accesso.',
    'back' => 'Torna all’account',

    'counts' => [
        'active' => '{1} :count concessione attiva|[2,*] :count concessioni attive',
        'pending' => '{1} :count richiesta in attesa|[2,*] :count richieste in attesa',
        'open' => '{1} :count concessione aperta|[2,*] :count concessioni aperte',
        'shown' => '{1} :count concessione visualizzata|[2,*] :count concessioni visualizzate',
    ],

    'pending' => [
        'heading' => 'In attesa della sua approvazione',
        'empty' => 'Nessuna richiesta in attesa. Un gestore della piattaforma può accedere ai contenuti di supporto di questo account solo tramite una richiesta su questa pagina.',
        'approve' => 'Approva',
        'deny' => 'Rifiuta',
    ],

    'active' => [
        'heading' => 'Concessioni attive',
        'empty' => 'Nessun gestore può vedere i contenuti di supporto di questo account in questo momento.',
        'revoke' => 'Revoca ora',
    ],

    'history' => [
        'heading' => 'Concessioni precedenti',
        'empty' => 'Nessuna concessione precedente.',
    ],

    'grant' => [
        'pending_summary' => ':scope · :duration · sola lettura',
        'minutes' => '{1} :count minuto|[2,*] :count minuti',
        'requester_reason' => ':requester — :reason',
        'requested' => 'Richiesta :elapsed',
        'active_summary' => ':scope — scade :elapsed',
        'self_approved_at' => 'Autoapprovata (nessun altro amministratore presente) :elapsed',
        'self_approved' => 'Autoapprovata (nessun altro amministratore presente)',
        'approved_by_at' => 'Approvata da :approver :elapsed',
        'approved_by' => 'Approvata da :approver',
        'past_summary' => ':scope — Stato: :status',
        'requested_self_approved' => 'Richiesta :elapsed · autoapprovata',
    ],

    'people' => [
        'former_operator' => 'Gestore precedente',
        'former_admin' => 'un amministratore precedente',
    ],

    'scopes' => [
        'conversation' => 'Conversazione',
        'conversation_deleted' => 'Conversazione eliminata',
        'conversation_out_of_scope' => 'Conversazione fuori ambito',
        'site' => 'Sito',
        'site_deleted' => 'Sito eliminato',
        'site_out_of_scope' => 'Sito fuori ambito',
        'account' => 'Intero account',
        'other' => 'Ambito',
    ],

    'statuses' => [
        'awaiting_approval' => 'In attesa di approvazione',
        'active' => 'Attivo',
        'denied' => 'Negato',
        'closed_early' => 'Terminato in anticipo',
        'expired' => 'Scaduto',
    ],

    'flash' => [
        'approved' => 'Accesso approvato fino alle :until: :scope.',
        'approved_generic' => 'Accesso approvato.',
        'denied' => 'Richiesta rifiutata. Non è stato concesso alcun accesso.',
        'already_expired' => 'Questa concessione era già scaduta; viene registrata come scaduta.',
        'closed' => 'Concessione terminata. L’accesso è stato revocato.',
    ],
];

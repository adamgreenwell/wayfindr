<?php

/*
 * Italian validation messages.
 *
 * Mirrors `lang/de/validation.php` key for key. Laravel's own English messages
 * live in the framework and the translator falls back to them per key, so this
 * covers the rules the dashboard actually validates with rather than restating
 * all hundred of them. A rule added later without a line here still produces a
 * correct English message.
 *
 * **Every message is scaffolded on `Il campo`, and that is grammar rather than
 * verbosity.** Italian adjectives agree with their noun, so a bare
 * `:attribute è obbligatorio` is wrong half the time -- `Nome` is masculine and
 * `Risposta` is feminine, and one template cannot agree with both. Anchoring
 * the sentence on `campo` puts a masculine noun in the subject position and
 * lets `:attribute` sit where its own gender does not matter. German needed no
 * such scaffold, which is why this file does not read like a translation of the
 * one beside it.
 *
 * The register question does not arise: these sentences describe a field, they
 * do not address the reader, so there is no `Lei` in the file at all.
 */
return [
    'accepted' => 'Il campo :attribute deve essere accettato.',
    'after' => 'Il campo :attribute deve contenere una data successiva al :date.',
    'array' => 'Il campo :attribute deve essere un elenco.',
    'before' => 'Il campo :attribute deve contenere una data precedente al :date.',
    'between' => [
        'array' => 'Il campo :attribute deve contenere tra :min e :max elementi.',
        'file' => 'Il campo :attribute deve essere tra :min e :max kilobyte.',
        'numeric' => 'Il campo :attribute deve essere compreso tra :min e :max.',
        'string' => 'Il campo :attribute deve contenere tra :min e :max caratteri.',
    ],
    'boolean' => 'Il campo :attribute deve essere sì o no.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo :attribute deve contenere una data valida.',
    'email' => 'Il campo :attribute deve contenere un indirizzo email valido.',
    'exists' => 'Il valore selezionato per :attribute non esiste.',
    'file' => 'Il campo :attribute deve contenere un file.',
    'in' => 'Il valore selezionato per :attribute non è valido.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'json' => 'Il campo :attribute deve contenere JSON valido.',
    'max' => [
        'array' => 'Il campo :attribute non può contenere più di :max elementi.',
        'file' => 'Il campo :attribute non può superare :max kilobyte.',
        'numeric' => 'Il campo :attribute non può essere superiore a :max.',
        'string' => 'Il campo :attribute non può contenere più di :max caratteri.',
    ],
    'mimes' => 'Il campo :attribute deve contenere un file di tipo :values.',
    'min' => [
        'array' => 'Il campo :attribute deve contenere almeno :min elementi.',
        'file' => 'Il campo :attribute deve essere di almeno :min kilobyte.',
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
    ],
    'numeric' => 'Il campo :attribute deve essere un numero.',
    'prohibited' => 'Il campo :attribute non è consentito.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_if' => 'Il campo :attribute è obbligatorio quando :other vale :value.',
    'string' => 'Il campo :attribute deve contenere del testo.',
    'timezone' => 'Il campo :attribute deve contenere un fuso orario valido.',
    'unique' => 'Il valore del campo :attribute è già stato utilizzato.',
    'uploaded' => 'Il caricamento del campo :attribute non è riuscito.',
    'url' => 'Il campo :attribute deve contenere un URL valido.',

    'password' => [
        'letters' => 'Il campo :attribute deve contenere almeno una lettera.',
        'mixed' => 'Il campo :attribute deve contenere almeno una lettera maiuscola e una minuscola.',
        'numbers' => 'Il campo :attribute deve contenere almeno un numero.',
        'symbols' => 'Il campo :attribute deve contenere almeno un simbolo.',
        'uncompromised' => 'Il campo :attribute è comparso in una violazione di dati. Ne scelga un altro.',
    ],

    'custom' => [],

    /*
     * Field names as an agent sees them on the form, not as the database
     * spells them.
     */
    'attributes' => [
        // Every field an Italian page can submit. Without a name here the rule
        // interpolates the column: "Il campo body non può contenere più di 4000
        // caratteri." House terms come from the glossary -- a reply helper is
        // an `assistente di risposta`, and `responsabile` is the assignee sense
        // of owner rather than `titolare`.
        'assignee_id' => 'Assegnazione',
        'attachment_ids' => 'Allegati',
        'body' => 'Risposta',
        'label_name' => 'Etichetta',
        'message' => 'Messaggio',
        'note_template' => 'Assistente di nota',
        'pending_note' => 'Nota di attesa',
        'post_to_external' => 'Pubblicazione nel problema collegato',
        'reason' => 'Motivo',
        'reopen_note' => 'Nota di riapertura',
        'reply_template' => 'Assistente di risposta',
        'target_agent_id' => 'Destinatario dell\'escalation',
        'resolution_note' => 'Nota di chiusura',
        'file' => 'File',
        'alert_cadence' => 'Frequenza',
        'alert_mode' => 'Avvisi',
        'current_password' => 'Password attuale',
        'email' => 'Indirizzo email',
        'locale' => 'Lingua',
        'name' => 'Nome',
        'password' => 'Password',
        'password_confirmation' => 'Conferma della password',
    ],
];

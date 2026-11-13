<?php

return [
    'document_title' => 'Modello di valutazione',
    'title' => 'Modello di valutazione per :site',
    'subtitle' => 'Scriva le domande poste ai visitatori quando una conversazione viene chiusa. Le modifiche alla bozza non hanno effetto finché non la pubblica.',
    'restricted' => 'Solo un amministratore dell’account può modificare il modello di valutazione di questo sito.',

    'flash' => [
        'draft_saved' => 'Bozza salvata. I visitatori continuano a vedere la versione pubblicata.',
        'published' => 'Pubblicata come versione :version. La scelga nelle impostazioni di valutazione del sito per iniziare a usarla.',
    ],

    'draft' => [
        'heading' => 'Bozza',
        'lede' => 'Il Suo lavoro in corso. Parte dalle domande predefinite, così modifica qualcosa che funziona già.',
        'save' => 'Salva bozza',
    ],

    'scale' => [
        'legend' => 'Scala delle risposte',
        'three' => 'Tre risposte: buono, discreto, negativo',
        'five' => 'Cinque stelle',
        'help' => 'Le risposte che contano come positive sono fissate dalla scala — buono con tre risposte, quattro e cinque stelle con cinque — così il dato ha lo stesso significato su ogni sito e in ogni versione. Le risposte su scale diverse vengono sempre riportate separatamente.',
    ],

    'locales' => [
        'en' => 'Inglese',
        'de' => 'Tedesco',
        'it' => 'Italiano',
    ],

    'questions' => [
        'caption' => 'Domande',
        'question' => 'Domanda',
        'asked' => 'Posta',
        'always' => 'Sempre',
        'ask' => 'Porre: :question',
        'wording' => ':question (:language)',
        'overall' => 'Esperienza complessiva',
        'agent' => 'Chi ha aiutato',
        'comment' => 'Commento',
        'help' => 'Scriva ogni domanda in inglese; chi legge un’altra lingua vede l’inglese dove quella lingua è vuota. La domanda su chi ha aiutato richiede {name} al posto del nome e viene posta solo quando esattamente una persona ha risposto al visitatore.',
    ],

    'labels' => [
        'caption' => 'Etichette delle risposte',
        'answer' => 'Risposta',
        'wording' => ':answer (:language)',
        'help' => 'I visitatori vedono solo le etichette della scala scelta. Ognuna in uso richiede l’inglese.',
        'choices' => [
            'good' => 'Buono',
            'ok' => 'Discreto',
            'bad' => 'Negativo',
            '1' => 'Una stella',
            '2' => 'Due stelle',
            '3' => 'Tre stelle',
            '4' => 'Quattro stelle',
            '5' => 'Cinque stelle',
            'not_applicable' => 'Non pertinente (domanda sull’aiuto)',
        ],
    ],

    'preview' => [
        'heading' => 'Anteprima',
        'lede' => 'La bozza salvata come la vede un visitatore che legge l’inglese.',
        'fallback' => 'I visitatori la cui lingua non ha un testo qui vedono l’inglese.',
    ],

    'publish' => [
        'heading' => 'Pubblica',
        'lede' => 'La pubblicazione trasforma la bozza salvata in una nuova versione numerata. Una versione pubblicata non viene più modificata, così ogni risposta conserva esattamente la domanda a cui è stata data.',
        'button' => 'Pubblica la bozza salvata come versione :version',
        'nothing' => 'Salvi prima una bozza; non c’è ancora nulla da pubblicare.',
        'select' => 'La pubblicazione non avvia le domande. Scelga il Suo modello in :settings nelle impostazioni del sito; può tornare a una domanda predefinita in qualsiasi momento senza perdere versioni o risposte.',
    ],

    'versions' => [
        'caption' => 'Versioni pubblicate',
        'empty' => 'Non è ancora stato pubblicato nulla.',
        'version' => 'Versione',
        'number' => 'Versione :version',
        'question' => 'Domanda complessiva (inglese)',
        'scale' => 'Scala',
        'published' => 'Pubblicata',
    ],
];

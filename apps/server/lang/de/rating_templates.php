<?php

return [
    'document_title' => 'Bewertungsvorlage',
    'title' => 'Bewertungsvorlage für :site',
    'subtitle' => 'Schreiben Sie die Fragen, die Besuchenden beim Schließen einer Unterhaltung gestellt werden. Änderungen am Entwurf wirken erst nach dem Veröffentlichen.',
    'restricted' => 'Nur eine Admin-Person des Kontos kann die Bewertungsvorlage dieser Website bearbeiten.',

    'flash' => [
        'draft_saved' => 'Entwurf gespeichert. Besuchende sehen weiterhin die veröffentlichte Version.',
        'published' => 'Als Version :version veröffentlicht. Wählen Sie sie in den Bewertungseinstellungen der Website aus, um sie zu verwenden.',
    ],

    'draft' => [
        'heading' => 'Entwurf',
        'lede' => 'Ihre Arbeitsfassung. Sie beginnt mit den eingebauten Fragen, damit Sie von etwas Funktionierendem ausgehen.',
        'save' => 'Entwurf speichern',
    ],

    'scale' => [
        'legend' => 'Antwortskala',
        'three' => 'Drei Antworten: gut, okay, schlecht',
        'five' => 'Fünf Sterne',
        'help' => 'Welche Antworten als positiv zählen, legt die Skala fest — gut bei drei Antworten, vier und fünf Sterne bei fünf —, damit der Wert auf jeder Website und in jeder Version dasselbe bedeutet. Antworten auf unterschiedlichen Skalen werden immer getrennt ausgewertet.',
    ],

    'locales' => [
        'en' => 'Englisch',
        'de' => 'Deutsch',
        'it' => 'Italienisch',
    ],

    'questions' => [
        'caption' => 'Fragen',
        'question' => 'Frage',
        'asked' => 'Gestellt',
        'always' => 'Immer',
        'ask' => 'Stellen: :question',
        'wording' => ':question auf :language',
        'overall' => 'Gesamterfahrung',
        'agent' => 'Wer geholfen hat',
        'comment' => 'Kommentar',
        'help' => 'Schreiben Sie jede Frage auf Englisch; wer eine andere Sprache liest, sieht Englisch, wo diese Sprache leer ist. Die Frage nach der helfenden Person braucht {name} an der Stelle des Namens und wird nur gestellt, wenn genau eine Person geantwortet hat.',
    ],

    'labels' => [
        'caption' => 'Antwortbeschriftungen',
        'answer' => 'Antwort',
        'wording' => ':answer auf :language',
        'help' => 'Besuchende sehen nur die Beschriftungen der gewählten Skala. Jede verwendete braucht Englisch.',
        'choices' => [
            'good' => 'Gut',
            'ok' => 'Okay',
            'bad' => 'Schlecht',
            '1' => 'Ein Stern',
            '2' => 'Zwei Sterne',
            '3' => 'Drei Sterne',
            '4' => 'Vier Sterne',
            '5' => 'Fünf Sterne',
            'not_applicable' => 'Trifft nicht zu (Frage zur Hilfe)',
        ],
    ],

    'preview' => [
        'heading' => 'Vorschau',
        'lede' => 'Der gespeicherte Entwurf aus Sicht englischsprachiger Besuchender.',
        'fallback' => 'Besuchende, für deren Sprache hier nichts steht, sehen Englisch.',
    ],

    'publish' => [
        'heading' => 'Veröffentlichen',
        'lede' => 'Beim Veröffentlichen wird der gespeicherte Entwurf zu einer neuen, nummerierten Version. Eine veröffentlichte Version wird danach nie geändert, damit jede Antwort genau die Frage behält, auf die sie gegeben wurde.',
        'button' => 'Gespeicherten Entwurf als Version :version veröffentlichen',
        'nothing' => 'Speichern Sie zuerst einen Entwurf; es gibt noch nichts zu veröffentlichen.',
        'select' => 'Veröffentlichen allein stellt die Fragen noch nicht. Wählen Sie Ihre eigene Vorlage unter :settings in den Website-Einstellungen; Sie können jederzeit zu einer eingebauten Frage zurückwechseln, ohne eine Version oder Antwort zu verlieren.',
    ],

    'versions' => [
        'caption' => 'Veröffentlichte Versionen',
        'empty' => 'Bisher wurde nichts veröffentlicht.',
        'version' => 'Version',
        'number' => 'Version :version',
        'question' => 'Gesamtfrage (Englisch)',
        'scale' => 'Skala',
        'published' => 'Veröffentlicht',
    ],
];

<?php

/*
 * Drafted from lang/en/articles.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue de --catalogue=articles --score`. Every value
 * here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * The markup inside `write.body_placeholder` is SYNTAX, not prose: `##`, `**`
 * and the link brackets are what the writer types, so they stay while the words
 * around them translate.
 *
 * Plural strings: list.count.
 */

return [
    'title' => 'Artikel',
    'subtitle' => 'Antworten, die Besuchende finden, ohne zu fragen.',
    'back_to_account' => 'Zurück zum Konto',
    'back_to_articles' => 'Zurück zu den Artikeln',

    'flash' => [
        'created' => 'Artikel als Entwurf erstellt.',
        'saved' => 'Artikel gespeichert.',
        'published' => 'Artikel veröffentlicht und ab sofort für Besuchende auffindbar.',
        'unpublished' => 'Veröffentlichung zurückgezogen. Besuchende finden den Artikel nicht mehr.',
        'deleted' => 'Artikel gelöscht.',
    ],

    'validation' => [
        'title' => 'Geben Sie dem Artikel einen Titel.',
        'body' => 'Schreiben Sie etwas, das Besuchende lesen können.',
    ],

    'state' => [
        'published' => 'Veröffentlicht',
        'draft' => 'Entwurf',
    ],

    'write' => [
        'heading' => 'Artikel schreiben',
        'lede' => 'Wird als Entwurf gespeichert. Nichts erreicht Besuchende, bevor Sie es veröffentlichen.',
        'title_label' => 'Titel',
        'title_placeholder' => 'So funktionieren Rückerstattungen',
        'body_label' => 'Text',
        'body_placeholder' => "## Rückerstattungen\n\nWir erstatten innerhalb von **14 Tagen**. Schreiben Sie an [Support](mailto:help@example.com).",
        'markup_hint' => 'Überschriften mit :headings, Aufzählungen mit :bullets, Links als :links, Hervorhebung mit :emphasis. Alles andere wird als gewöhnlicher Text gelesen.',
        'markup_links' => '[Wörter](https://…)',
        'markup_emphasis' => '**fett**',
        'submit' => 'Entwurf erstellen',
    ],

    'list' => [
        'heading' => 'Alles bisher Geschriebene',
        'lede' => 'Entwürfe zuerst, weil sie noch Arbeit brauchen.',
        'count' => '{1} :count Artikel|[2,*] :count Artikel',
        'search_label' => 'Suche',
        'search_placeholder' => 'Nach Titel',
        'search_submit' => 'Artikel suchen',
        'column_article' => 'Artikel',
        'column_state' => 'Zustand',
        'column_edited' => 'Zuletzt bearbeitet',
        'no_match' => 'Kein Artikeltitel passt zu „:search“.',
        'empty' => 'Noch nichts geschrieben. Der erste Artikel ist meist die Frage, die Ihr Team am häufigsten beantwortet.',
    ],

    'detail' => [
        'subtitle' => 'Bearbeiten Sie die Antwort und entscheiden Sie dann, wer sie sehen darf.',
        'visibility_heading' => 'Wer das sehen kann',
        'visible' => 'Besuchende finden das im Widget, wenn sie suchen.',
        'hidden' => 'Ein Entwurf, sichtbar nur für dieses Konto.',
        'slug' => 'Wird als :slug referenziert und bleibt gleich, wenn Sie den Titel ändern — ein bereits versendeter Link funktioniert also weiter.',
        'publish' => 'Veröffentlichen',
        'unpublish' => 'Veröffentlichung zurückziehen',
        'edit_heading' => 'Die Antwort',
        'save' => 'Artikel speichern',
        'preview_heading' => 'Was Besuchende sehen',
        'preview_lede' => 'Aus denselben Blöcken gebaut, die das Widget baut — das ist also der Artikel und kein Eindruck davon.',
        'delete_heading' => 'Löschen',
        'delete_lede' => 'Entfernt den Artikel vollständig. Die Veröffentlichung zurückzuziehen ist die umkehrbare Möglichkeit.',
        'delete' => 'Diesen Artikel löschen',
    ],
];

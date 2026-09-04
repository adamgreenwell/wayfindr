<?php

return [
    'flash' => [
        'created' => 'Makroentwurf erstellt.',
        'updated' => 'Makro gespeichert.',
        'deleted' => 'Makro gelöscht. Der Ausführungsverlauf bleibt verfügbar.',
        'applied' => 'Makro angewendet.',
        'failed' => 'Das Makro konnte nicht angewendet werden. Teiländerungen wurden nicht gespeichert.',
    ],
    'list' => [
        'heading' => 'Makros',
        'count' => '{0} Keine Makros|{1} :count Makro|[2,*] :count Makros',
        'macro' => 'Makro',
        'work_type' => 'Vorgangstyp',
        'actions' => 'Aktionen',
        'order' => 'Anzeigereihenfolge',
        'status' => 'Status',
        'manage' => 'Verwalten',
        'edit' => 'Bearbeiten',
    ],
    'empty' => [
        'heading' => 'Noch keine Makros.',
        'body' => 'Erstellen Sie eine wiederverwendbare Aktionsfolge für ein Ticket oder eine Unterhaltung.',
        'action' => 'Erstes Makro erstellen',
    ],
    'create' => [
        'title' => 'Automatisierungsmakro erstellen',
        'subtitle' => 'Bündeln Sie eine kurze Folge interner Supportaktionen, die Agenten mit einem Klick ausführen.',
        'action' => 'Makro erstellen',
        'submit' => 'Entwurf erstellen',
    ],
    'edit' => [
        'title' => 'Automatisierungsmakro bearbeiten',
        'title_named' => ':name bearbeiten',
        'subtitle' => 'Halten Sie die Folge eindeutig, geordnet und für den ausgewählten Vorgangstyp sicher.',
        'back' => 'Zurück zu Automatisierungen',
        'save' => 'Makro speichern',
    ],
    'fields' => [
        'name' => 'Makroname',
        'name_help' => 'Verwenden Sie einen kurzen, ergebnisorientierten Namen, den Agenten bei der Arbeit erkennen.',
        'subject_type' => 'Ausführen für',
        'subject_type_help' => 'Ticketspezifische Aktionen wie Labels und private Notizen sind für Unterhaltungen nicht verfügbar.',
        'position' => 'Anzeigereihenfolge',
        'position_help' => 'Niedrigere Zahlen erscheinen auf der Vorgangsseite zuerst.',
        'enabled' => 'Dieses Makro aktivieren',
        'enabled_help' => 'Aktivierte Makros erscheinen bei passenden Vorgängen für Agenten, die alle Aktionen ausführen dürfen.',
    ],
    'builder' => [
        'heading' => 'Makrodefinition',
        'lede' => 'Ein Klick, danach jede aufgeführte Aktion von oben nach unten.',
        'actions_help' => 'Makros verwenden dieselben begrenzten Aktionen wie Automatisierungsregeln und können bis zu zehn Aktionen enthalten.',
    ],
    'subject_types' => [
        'ticket' => 'Ticket',
        'conversation' => 'Unterhaltung',
    ],
    'apply' => [
        'heading' => 'Makros',
        'lede' => 'Wenden Sie eine vorab genehmigte interne Aktionsfolge auf diesen Vorgang an.',
        'run' => 'Anwenden',
        'action_count' => '{1} :count Aktion|[2,*] :count Aktionen',
    ],
    'execution' => [
        'kind' => 'Manuelles :type-Makro',
        'trigger' => 'Manueller Auslöser',
        'triggered_by' => 'Angewendet von',
    ],
    'delete' => [
        'heading' => 'Makro löschen',
        'lede' => 'Das Makro verschwindet aus den Supportvorgängen, frühere Ausführungen bleiben jedoch im Protokoll.',
        'action' => 'Makro löschen',
    ],
    'validation' => [
        'heading' => 'Makrodefinition prüfen',
        'definition' => 'Diese Makrodefinition ist ungültig: :detail',
        'duplicate' => 'Ein Makro mit diesem Namen ist bereits vorhanden.',
    ],
];

<?php

/*
 * Drafted from lang/en/api_tokens.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue de --catalogue=api_tokens --score`. Every value
 * here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * Terms this catalogue introduced, both now in the glossary: `API-Token`
 * (neuter, hyphenated) and `Berechtigung` for an ability -- NOT `Fähigkeit`,
 * which is a personal aptitude rather than a permission a credential holds.
 *
 * `site` is `Website` throughout and never `Seite`, which is a page. On this
 * page the two would sit in the same sentence.
 *
 * DAS Token, so every pronoun for it is `es`. The first draft of this file said
 * `ihn` and `er` throughout, and the policy scorer caught all ten as "generic
 * masculine pronoun" -- a check written to find people being defaulted to male,
 * which from the outside is the same shape as a plain grammatical-gender
 * mistake. It was reading the sentences correctly and the error was real; only
 * the reason in its report was wrong.
 *
 * Action labels are infinitive and address nobody ("Token ausstellen"), per the
 * register rule. The prose uses `Sie`.
 */

return [
    'title' => 'API und Webhooks',
    'subtitle' => 'Begrenzter API-Zugriff und signierte Ereigniszustellung für die Integrationen dieses Kontos.',
    'back' => 'Zurück zum Konto',

    // The token noun matters now that this page also counts webhook endpoints.
    'active' => '{1} :count aktives Token|[2,*] :count aktive Token',

    'flash' => [
        'created' => 'API-Token erstellt. Kopieren Sie es jetzt — es kann nicht erneut angezeigt werden.',
        'created_limited' => 'API-Token erstellt, begrenzt auf die Websites, die Sie heute betreuen. Kopieren Sie es jetzt — es kann nicht erneut angezeigt werden.',
        'revoked' => 'API-Token widerrufen.',
        'already_revoked' => 'Dieses API-Token wurde bereits widerrufen.',
    ],

    'issued' => [
        'heading' => 'Jetzt kopieren',
        'once' => 'Einmalig sichtbar',
        'hashed' => 'Dies ist das einzige Mal, dass dieses Token angezeigt wird. Wayfindr speichert einen Hash davon, nicht das Token selbst, sodass es nicht wiederhergestellt werden kann — wenn Sie es verlieren, widerrufen Sie es und stellen Sie ein neues aus.',
        'send_as' => 'Senden Sie es als :header. Behandeln Sie es wie ein Passwort: Wer es besitzt, kann jede unten gewährte Berechtigung nutzen.',
    ],

    'list' => [
        'heading' => 'Token',
        'total' => '{1} :count Token|[2,*] :count Token',
        'empty' => 'Noch keine Token. Nichts außerhalb dieses Dashboards kann die Supportdaten dieses Kontos lesen.',
        'column_name' => 'Name',
        'column_token' => 'Token',
        'column_reaches' => 'Reichweite',
        'column_last_used' => 'Zuletzt verwendet',
        'column_state' => 'Zustand',
        'column_action' => 'Aktion',
        'created' => 'Erstellt :when',
        'created_by' => 'Erstellt :when von :name',
        'revoke' => 'Widerrufen',
        'revoking_keeps' => 'Ein Widerruf behält die Zeile. Wozu das Token da war und wann es zuletzt verwendet wurde, ist der Teil, der es wert ist, aufgehoben zu werden, nachdem es jemand abgeschaltet hat.',
    ],

    'reaches' => [
        'purged' => 'Keine Websites — jede Website, auf die es begrenzt war, wurde endgültig gelöscht',
        'every_site' => 'Jede Website dieses Kontos',
        'unsupported' => 'Websites, die Sie nicht betreuen',
        'no_abilities' => 'Keine Berechtigungen',
    ],

    'abilities' => [
        'read' => 'Lesen',
        'write' => 'Schreiben',
    ],

    'last_used' => [
        'never' => 'Nie verwendet',
    ],

    'state' => [
        'revoked' => 'Widerrufen :when',
        'expired' => 'Abgelaufen :when',
        'expires' => 'Läuft ab :when',
        'active' => 'Aktiv',
    ],

    'create' => [
        'heading' => 'Token ausstellen',
        'read_only' => 'Lesen und Schreiben sind getrennt',
        'name_label' => 'Wofür ist es da',
        'name_placeholder' => 'Reporting-Abgleich',
        'name_help' => 'Geschrieben für die Person, die diese Zeile in einem Jahr findet und entscheiden muss, ob sie noch gebraucht wird.',
        'abilities_label' => 'Was es darf',
        'ability_read' => 'Unterhaltungen, Nachrichten, Tickets und Besuchende lesen',
        'ability_write' => 'Unterhaltungen eröffnen, Nachrichten senden sowie Tickets erstellen oder ihren Status ändern',
        'abilities_help' => 'Jede Berechtigung steht für sich. Schreiben gewährt kein Lesen, und Lesen gewährt niemals Schreiben.',
        'expires_label' => 'Läuft ab nach',
        'expires_help' => 'Tage. Bleibt das Feld leer, läuft das Token nie ab — dann ist es niemandes Aufgabe mehr, es zu bemerken.',
        'sites_label' => 'Auf Websites begrenzen',
        'sites_help' => 'Wenn Sie nichts ankreuzen, erreicht das Token jede Website, :today. Eine später angelegte Website wird nicht hinzugefügt — stellen Sie ein neues Token aus, wenn eines mehr abdecken soll. Eine Integration, die eine Website beobachtet, sollte kein Zugang zu allen sein.',
        'sites_help_today' => 'die Sie heute betreuen',
        'submit' => 'Token ausstellen',
    ],

    'accountability' => 'Hinter einem Token steht keine Person, deshalb kann ein damit ausgeführter Lesezugriff nicht beantworten, :who gelesen hat, wie es ein Zugriff über das Dashboard kann. Schreibvorgänge werden dem Token zugeschrieben, niemals der Person, die es ausgestellt hat. Darum wird ein Token darüber begrenzt, was es erreichen kann — und darum erweitert eine Betreiber-Zugriffsfreigabe es nie.',
    'accountability_who' => 'wer',
];

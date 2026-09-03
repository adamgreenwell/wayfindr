<?php

/*
 * Entwurf auf Grundlage von lang/en/operator_access.php. NOCH NICHT GEPRÜFT.
 *
 * Von Hand anhand des Glossars in resources/translation/glossary.php und der
 * Regeln in docs/product/translation-policy.md erstellt. Werte aus dem Konto
 * (Bereichskennungen, Namen und Gründe) stehen nicht in diesem Katalog; die
 * View kennzeichnet sie mit einer unbekannten Sprache.
 *
 * Aktionen verwenden den Infinitiv, die erklärende Prosa die formelle Anrede
 * „Sie“. „Betreiber“ und „Agent“ bleiben gemäß Glossar getrennte Rollen.
 */

return [
    'document_title' => 'Betreiberzugriff',
    'title' => 'Betreiberzugriff',
    'subtitle' => 'Wenn ein Plattformbetreiber die Supportdaten dieses Kontos einsehen muss, ist dafür eine Anfrage erforderlich. Genehmigen, verweigern oder beenden Sie den Zugriff hier.',
    'back' => 'Zurück zum Konto',

    'banner' => [
        'title' => 'Plattformbetreiberzugriff ist aktiv',
        'review' => 'Prüfen oder widerrufen',
        'former_operator' => 'Ein ehemaliger Betreiber',
        'grant' => ':requester hat bis :until (:elapsed) nur lesenden Zugriff auf :scope:self_approved.',
        'self_approved' => ' — selbst genehmigt',
    ],

    'counts' => [
        'active' => '{1} :count aktive Zugriffsfreigabe|[2,*] :count aktive Zugriffsfreigaben',
        'pending' => '{1} :count ausstehende Anfrage|[2,*] :count ausstehende Anfragen',
        'open' => '{1} :count offene Zugriffsfreigabe|[2,*] :count offene Zugriffsfreigaben',
        'shown' => '{1} :count Eintrag angezeigt|[2,*] :count Einträge angezeigt',
    ],

    'pending' => [
        'heading' => 'Ihre Genehmigung steht aus',
        'empty' => 'Keine ausstehenden Anfragen. Ein Plattformbetreiber kann die Supportinhalte dieses Kontos nur über eine Anfrage auf dieser Seite erreichen.',
        'approve' => 'Genehmigen',
        'deny' => 'Ablehnen',
    ],

    'active' => [
        'heading' => 'Aktive Zugriffsfreigaben',
        'empty' => 'Kein Betreiber kann derzeit die Supportinhalte dieses Kontos einsehen.',
        'revoke' => 'Jetzt widerrufen',
    ],

    'history' => [
        'heading' => 'Frühere Zugriffsfreigaben',
        'empty' => 'Keine früheren Zugriffsfreigaben.',
    ],

    'grant' => [
        'pending_summary' => ':scope · :duration · nur lesend',
        'minutes' => '{1} :count Minute|[2,*] :count Minuten',
        'requester_reason' => ':requester — :reason',
        'requested' => 'Angefordert :elapsed',
        'active_summary' => ':scope — läuft :elapsed ab',
        'self_approved_at' => 'Selbst genehmigt (keine andere Admin-Person vorhanden) :elapsed',
        'self_approved' => 'Selbst genehmigt (keine andere Admin-Person vorhanden)',
        'approved_by_at' => 'Genehmigt von :approver :elapsed',
        'approved_by' => 'Genehmigt von :approver',
        'past_summary' => ':scope — Status: :status',
        'requested_self_approved' => 'Angefordert :elapsed · selbst genehmigt',
    ],

    'people' => [
        'former_operator' => 'Ehemaliger Betreiber',
        'former_admin' => 'einer früheren Admin-Person',
    ],

    'scopes' => [
        'conversation' => 'Unterhaltung',
        'conversation_deleted' => 'Gelöschte Unterhaltung',
        'conversation_out_of_scope' => 'Unterhaltung außerhalb des Bereichs',
        'site' => 'Website',
        'site_deleted' => 'Gelöschte Website',
        'site_out_of_scope' => 'Website außerhalb des Bereichs',
        'account' => 'Gesamtes Konto',
        'other' => 'Bereich',
    ],

    'statuses' => [
        'awaiting_approval' => 'Genehmigung ausstehend',
        'active' => 'Aktiv',
        'denied' => 'Abgelehnt',
        'closed_early' => 'Vorzeitig beendet',
        'expired' => 'Abgelaufen',
    ],

    'flash' => [
        'approved' => 'Zugriff bis :until genehmigt: :scope.',
        'approved_generic' => 'Zugriff genehmigt.',
        'denied' => 'Anfrage abgelehnt. Es wurde kein Zugriff gewährt.',
        'already_expired' => 'Diese Zugriffsfreigabe war bereits abgelaufen; sie wird als abgelaufen erfasst.',
        'closed' => 'Zugriffsfreigabe beendet. Der Zugriff wurde widerrufen.',
    ],
];

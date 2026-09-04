<?php

/*
 * Tradotto da lang/en/oidc.php. NON ANCORA REVISIONATO DA UN MADRELINGUA.
 */

return [
    'settings' => [
        'heading' => 'Single Sign-on con OpenID Connect',
        'enabled' => 'Attivato',
        'disabled' => 'Non attivato',
        'help' => 'Colleghi un provider di identità per l’accesso degli agenti. Gli agenti esistenti possono collegarsi tramite un’email verificata; i titolari possono abilitare separatamente il provisioning mappato.',
        'callback_help' => 'Registri questo URL di callback presso il provider di identità:',
        'name' => 'Nome del provider',
        'issuer_url' => 'URL dell’emittente',
        'client_id' => 'ID client',
        'client_secret' => 'Segreto client',
        'secret_help' => 'Wayfindr cifra questo segreto e non lo mostra più.',
        'secret_keep_help' => 'Lasci vuoto per conservare il segreto salvato.',
        'enable_checkbox' => 'Consenti agli agenti di accedere con questo provider',
        'save' => 'Salva Single Sign-on',
        'secret_required' => 'Inserisca il segreto client quando collega un provider.',
        'public_https_required' => 'L’emittente deve essere un URL HTTPS risolvibile pubblicamente.',
        'owner_connect_help' => 'Un titolare dell’account deve collegare il primo provider di identità.',
        'owner_authority_help' => 'Solo un titolare dell’account può sostituire l’emittente o l’ID client.',
    ],
    'provisioning' => [
        'heading' => 'Provisioning just-in-time',
        'enabled' => 'Creazione degli agenti mappati',
        'disabled' => 'Nessuna creazione di agenti',
        'help' => 'Mappi un valore esatto del claim del provider al ruolo Agente, Admin o a un ruolo personalizzato. Il ruolo Titolare non è mai disponibile tramite federazione.',
        'boundary_help' => 'Gli agenti esistenti creati localmente mantengono il proprio ruolo locale. Gli agenti mappati vengono ricontrollati a ogni accesso SSO; l’accesso ai siti e la disattivazione restano in Wayfindr.',
        'role_claim' => 'Nome del claim del ruolo',
        'role_claim_help' => 'Usi un claim di primo livello, ad esempio groups. Sono supportati valori singoli e array.',
        'enable_checkbox' => 'Crea un nuovo agente quando corrispondono un’email verificata e una sola mappatura di ruolo',
        'save' => 'Salva impostazioni di provisioning',
        'mappings_heading' => 'Mappature dei ruoli',
        'mappings_empty' => 'Nessun valore di claim è ancora mappato. Ne aggiunga uno prima di abilitare il provisioning.',
        'claim_value' => 'Valore del claim del provider',
        'wayfindr_role' => 'Ruolo Wayfindr',
        'actions' => 'Azioni',
        'roles' => ['admin' => 'Admin', 'agent' => 'Agente'],
        'add_mapping' => 'Aggiungi mappatura del ruolo',
        'delete_mapping' => 'Rimuovi',
        'mapping_required' => 'Aggiunga almeno una mappatura del ruolo prima di abilitare il provisioning.',
        'duplicate_mapping' => 'Questo valore del claim è già mappato.',
        'invalid_role' => 'Scelga un ruolo Wayfindr disponibile.',
        'invalid_claim' => 'Inserisca un nome o valore di claim non vuoto e senza caratteri di controllo.',
    ],
    'sign_in' => [
        'failed' => 'Impossibile completare il Single Sign-on. Controlli l’identificatore dell’account o contatti l’amministrazione.',
    ],
    'flash' => [
        'connection_updated' => 'Impostazioni Single Sign-on aggiornate.',
        'provisioning_updated' => 'Impostazioni di provisioning aggiornate.',
        'mapping_created' => 'Mappatura del ruolo aggiunta.',
        'mapping_deleted' => 'Mappatura del ruolo rimossa.',
    ],
];

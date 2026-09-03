<?php

/*
 * Tradotto da lang/en/oidc.php. NON ANCORA REVISIONATO DA UN MADRELINGUA.
 */

return [
    'settings' => [
        'heading' => 'Single Sign-on con OpenID Connect',
        'enabled' => 'Attivato',
        'disabled' => 'Non attivato',
        'help' => 'Colleghi un provider di identità per gli agenti esistenti. SSO non crea utenti e non modifica i loro ruoli.',
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
    ],
    'sign_in' => [
        'failed' => 'Impossibile completare il Single Sign-on. Controlli l’identificatore dell’account o contatti l’amministrazione.',
    ],
    'flash' => [
        'connection_updated' => 'Impostazioni Single Sign-on aggiornate.',
    ],
];

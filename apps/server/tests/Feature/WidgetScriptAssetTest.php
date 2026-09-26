<?php

test('public widget script is served from the Laravel app', function (): void {
    $response = $this->get('/widget.js');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
        ->assertSee('createClient', false);

    // The served file is the minified build, which renames the source's
    // `root.Wayfindr = api` to whatever terser chooses; only the property name
    // `Wayfindr` is stable.
    expect($response->getContent())->toMatch('/\.Wayfindr\s*=/');
});

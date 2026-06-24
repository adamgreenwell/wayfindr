<?php

test('homepage points agents to the login screen', function (): void {
    $this->get('/')
        ->assertRedirect('/login');
});

test('health endpoint reports ok status', function (): void {
    $this->get('/up')
        ->assertOk()
        ->assertSee('Application up');
});

test('documented legacy health endpoint is not exposed', function (): void {
    $this->get('/health')
        ->assertNotFound();
});

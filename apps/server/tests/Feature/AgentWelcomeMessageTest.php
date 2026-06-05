<?php

use App\Mail\AgentWelcomeMessage;

test('agent welcome messages render the sign-in credentials', function (): void {
    $message = new AgentWelcomeMessage(
        accountName: 'Acme Support',
        agentName: 'Bea Builder',
        agentEmail: 'bea@example.test',
        temporaryPassword: 'temporary-secret',
        loginUrl: 'https://wayfindr.example.test/login',
    );

    $message->assertHasSubject('Welcome to Wayfindr');
    $message->assertSeeInHtml('Bea Builder');
    $message->assertSeeInHtml('Acme Support');
    $message->assertSeeInHtml('bea@example.test');
    $message->assertSeeInHtml('temporary-secret');
    $message->assertSeeInHtml('https://wayfindr.example.test/login');
    $message->assertSeeInText('Please change this temporary password after you sign in.');
});

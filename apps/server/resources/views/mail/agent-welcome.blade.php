<p>Hello {{ $agentName }},</p>

<p>You have been added to {{ $accountName }} in Wayfindr.</p>

<p>
    Sign in at <a href="{{ $loginUrl }}">{{ $loginUrl }}</a> with:
</p>

<ul>
    <li>Email: {{ $agentEmail }}</li>
    <li>Temporary password: {{ $temporaryPassword }}</li>
</ul>

<p>Please change this temporary password after you sign in.</p>

<p>Wayfindr</p>

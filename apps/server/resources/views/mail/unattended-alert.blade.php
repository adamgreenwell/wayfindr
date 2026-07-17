<p>Hello {{ $agentName }},</p>

<p>
    {{ $candidateCount === 1 ? 'A visitor message has' : $candidateCount.' visitor messages have' }}
    waited unseen for more than {{ \App\Support\UnattendedConversationAlertCollector::THRESHOLD_MINUTES }} minutes.
</p>

<ul>
    @foreach ($candidates as $candidate)
        <li>
            <strong>{{ $candidate['reference'] }}</strong>
            on {{ $candidate['site_name'] }}:
            {{ $candidate['subject'] }}
            <br>
            Waiting {{ \App\Mail\UnattendedConversationAlertMessage::waitingLabel($candidate['waiting_since'], $generatedAt) }}
            <br>
            <a href="{{ url($candidate['url']) }}">Open in Wayfindr</a>
        </li>
    @endforeach
</ul>

<p>This alert only includes support metadata. Visitor message bodies stay out of email.</p>

<p>You receive this because your alert cadence is "unattended only" — you are not emailed while someone is answering.</p>

<p>Wayfindr</p>

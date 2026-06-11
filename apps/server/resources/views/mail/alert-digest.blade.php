<p>Hello {{ $agentName }},</p>

<p>Wayfindr found {{ $candidateCount }} support {{ Str::plural('item', $candidateCount) }} waiting for your attention.</p>

<ul>
    @foreach ($candidates as $candidate)
        <li>
            <strong>{{ $candidate['reference'] }}</strong>
            on {{ $candidate['site_name'] }}:
            {{ $candidate['subject'] }}
            <br>
            Status: {{ $candidate['status'] ?? 'n/a' }}
            @if ($candidate['priority'])
                - Priority: {{ ucfirst($candidate['priority']) }}
            @endif
            @if ($candidate['last_activity_at'])
                - Last activity: {{ $candidate['last_activity_at'] }}
            @endif
            <br>
            <a href="{{ url($candidate['url']) }}">Open in Wayfindr</a>
        </li>
    @endforeach
</ul>

<p>This digest only includes support metadata. Visitor message bodies, transcript excerpts, and cobrowse data stay out of digest email.</p>

<p>Wayfindr</p>

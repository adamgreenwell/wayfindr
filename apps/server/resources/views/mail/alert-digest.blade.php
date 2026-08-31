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
            {{-- `last_activity_at` is the pre-0.7 shape. A digest queued by the
                 release before this one is still in the queue when this template
                 deploys, and its serialized candidates have no label -- so reading
                 the new key directly would throw, the retry would throw again, and
                 the notifications are already marked queued, so those alerts would
                 never reach anyone. --}}
            @php($lastActivity = $candidate['last_activity_label'] ?? $candidate['last_activity_at'] ?? null)
            @if ($lastActivity)
                - Last activity: {{ $lastActivity }}
            @endif
            <br>
            <a href="{{ url($candidate['url']) }}">Open in Wayfindr</a>
        </li>
    @endforeach
</ul>

<p>This digest only includes support metadata. Visitor message bodies, transcript excerpts, and cobrowse data stay out of digest email.</p>

<p>Wayfindr</p>

Hello {{ $agentName }},

Wayfindr found {{ $candidateCount }} support {{ Str::plural('item', $candidateCount) }} waiting for your attention.

@foreach ($candidates as $candidate)
- {{ $candidate['reference'] }} on {{ $candidate['site_name'] }}: {{ $candidate['subject'] }}
  Status: {{ $candidate['status'] ?? 'n/a' }}
@if ($candidate['priority'])
  Priority: {{ ucfirst($candidate['priority']) }}
@endif
@if ($candidate['last_activity_at'])
  Last activity: {{ $candidate['last_activity_at'] }}
@endif
  Link: {{ url($candidate['url']) }}
@endforeach

This digest only includes support metadata. Visitor message bodies, transcript excerpts, and cobrowse data stay out of digest email.

Wayfindr

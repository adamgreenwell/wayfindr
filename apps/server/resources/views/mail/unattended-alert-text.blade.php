Hello {{ $agentName }},

{{ $candidateCount === 1 ? 'A visitor message has' : $candidateCount.' visitor messages have' }} waited unseen for more than {{ \App\Support\UnattendedConversationAlertCollector::THRESHOLD_MINUTES }} minutes.

@foreach ($candidates as $candidate)
- {{ $candidate['reference'] }} on {{ $candidate['site_name'] }}: {{ $candidate['subject'] }}
  Waiting {{ \App\Mail\UnattendedConversationAlertMessage::waitingLabel($candidate['waiting_since'], $generatedAt) }}
  Link: {{ url($candidate['url']) }}
@endforeach

This alert only includes support metadata. Visitor message bodies stay out of email.

You receive this because your alert cadence is "unattended only" — you are not emailed while someone is answering.

Wayfindr

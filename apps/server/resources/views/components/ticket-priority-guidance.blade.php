@props(['priorities'])

{{-- See the note on the category guide: shared with an unextracted route, and
     correct there because that route's locale is the install default. --}}
<div class="notice-list" aria-label="{{ __('tickets.guidance.priority_aria') }}">
    @foreach ($priorities as $value => $priority)
        <p>
            {{ __('tickets.priorities.'.$value) }} - {{ __('tickets.priority_help.'.$value.'.description') }}
            @if (isset($priority['agent_action']))
                <br>
                <span>{{ __('tickets.guidance.agent_move', ['action' => __('tickets.priority_help.'.$value.'.agent_action')]) }}</span>
            @endif
        </p>
    @endforeach
</div>

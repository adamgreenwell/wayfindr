@props(['priorities'])

<div class="notice-list" aria-label="Priority guide">
    @foreach ($priorities as $priority)
        <p>
            {{ $priority['label'] }} - {{ $priority['description'] }}
            @if (isset($priority['agent_action']))
                <br>
                <span>Agent move: {{ $priority['agent_action'] }}</span>
            @endif
        </p>
    @endforeach
</div>

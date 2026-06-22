@props([
    'commands' => [],
])

@if ($commands !== [])
    <div class="readiness-commands" aria-label="Recommended commands">
        @foreach ($commands as $command)
            <div class="readiness-command">
                <code>{{ $command }}</code>
                <x-copy-value-button
                    :aria-label="'Copy command '.$command"
                    label="Copy command"
                    success-label="Copied"
                    :value="$command"
                />
            </div>
        @endforeach
    </div>
@endif

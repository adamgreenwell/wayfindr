@props([
    'commands' => [],
])

@if ($commands !== [])
    <div class="readiness-commands" aria-label="{{ __('operator.readiness.commands.group') }}">
        @foreach ($commands as $command)
            <div class="readiness-command">
                <code lang="">{{ $command }}</code>
                <x-copy-value-button
                    :aria-label="__('operator.readiness.commands.copy_named', ['command' => $command])"
                    :label="__('operator.readiness.commands.copy')"
                    :success-label="__('operator.readiness.commands.copied')"
                    :value="$command"
                />
            </div>
        @endforeach
    </div>
@endif

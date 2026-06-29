@props([
    'title',
    'subtitle' => null,
    'backHref' => null,
    'backLabel' => 'Back',
])

<header class="page-header">
    @if ($backHref)
        <a class="page-header__back" href="{{ $backHref }}">{{ $backLabel }}</a>
    @endif

    <div class="page-header__bar">
        <div class="page-header__heading">
            <h1>{{ $title }}</h1>
            @if (filled($subtitle))
                <p class="lede">{{ $subtitle }}</p>
            @endif
            {{ $slot }}
        </div>

        @isset($actions)
            <div class="page-header__actions">{{ $actions }}</div>
        @endisset
    </div>
</header>

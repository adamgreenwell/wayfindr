@props([
    'title',
    // A heading that is the user's own words -- a conversation subject, an
    // article title -- is not the dashboard's language. Pass '' for HTML's
    // "unknown"; leave null when the title is our own copy.
    'titleLang' => null,
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
            {{-- Built inline so a heading with no language declaration renders
                 exactly `<h1>`, not `<h1 >`. Only the attribute is unescaped;
                 its value is escaped on the way in. --}}
            <h1{!! $titleLang !== null ? ' lang="'.e(str_replace('_', '-', $titleLang)).'"' : '' !!}>{{ $title }}</h1>
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

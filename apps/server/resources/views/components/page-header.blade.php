@props([
    // Optional because `titleContent` can supply the heading instead. One of
    // the two is required; a page passing neither renders an empty h1, which
    // `every page header names its page` catches.
    'title' => null,
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
            {{-- A heading that MIXES our words with the user's -- "Live
                 visitors: Acme Docs" -- is neither `title` nor `titleLang`:
                 only part of it is the user's, so only part of it can be
                 marked. That part is an ELEMENT, and an element cannot go in an
                 attribute value, which is why this is a slot and not a prop.
                 `no language marker is rendered inside an attribute` has the
                 whole story, including the iframe it once blanked. --}}
            <h1{!! $titleLang !== null ? ' lang="'.e(str_replace('_', '-', $titleLang)).'"' : '' !!}>@isset($titleContent){!! $titleContent !!}@else{{ $title }}@endisset</h1>
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

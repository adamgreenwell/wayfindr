@props(['categories'])

{{-- Shared by the translated conversation and ticket surfaces. A shared VIEW
     may read the catalogue because it only renders inside a request; a shared
     MODEL may not. See docs/product/dashboard-language.md. --}}
<div class="notice-list" aria-label="{{ __('tickets.guidance.category_aria') }}">
    @foreach ($categories as $value => $category)
        <p>
            {{ __('tickets.categories.'.$value) }} - {{ __('tickets.category_help.'.$value.'.description') }}
            @if (isset($category['guidance']))
                <br>
                <span>{{ __('tickets.category_help.'.$value.'.guidance') }}</span>
            @endif
        </p>
    @endforeach
</div>

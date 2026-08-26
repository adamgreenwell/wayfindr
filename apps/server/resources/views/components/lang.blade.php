@props(['is' => null])

{{--
    States the language of the value inside it.

    Two jobs, both needed on a surface that is half-extracted. Inside a region
    marked as another language it says "this part is translated"; on a value
    whose language depends on the data -- a timestamp that is `diffForHumans()`
    when it exists and a static English phrase when it does not -- it carries
    the language the data reported.

    Defaults to the document's language. See docs/product/dashboard-language.md.
--}}
<span lang="{{ str_replace('_', '-', $is ?: app()->getLocale()) }}">{{ $slot }}</span>

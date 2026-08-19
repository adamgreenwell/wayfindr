@props(['name', 'size' => 16, 'label' => null])

{{--
    One icon, inlined. The stroke conventions live here rather than in the
    sixteen source files, so the set cannot drift into sixteen slightly
    different weights.

    Decorative by default: nearly every icon here sits beside its own visible
    label, and announcing both makes a screen reader read the navigation twice.
    Pass `label` only when the icon is the sole meaning, as in an icon-only
    button.
--}}
<svg {{ $attributes->merge(['class' => 'wf-icon']) }}
    width="{{ (int) $size }}"
    height="{{ (int) $size }}"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.5"
    stroke-linecap="butt"
    stroke-linejoin="miter"
    @if ($label)
        role="img" aria-label="{{ $label }}"
    @else
        aria-hidden="true" focusable="false"
    @endif
>{!! \App\Support\Design\IconSet::body($name) !!}</svg>

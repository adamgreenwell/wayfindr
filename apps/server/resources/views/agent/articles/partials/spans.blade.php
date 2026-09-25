{{-- Spans render as elements, never as a string of markup: the same rule the
     widget follows, so the preview cannot be safe while the widget is not.

     Spans are inline, so any whitespace between them is a visible space:
     `[14 days](...).` previewed as "14 days ." PHP swallows the newline after
     a directive but not after an element or an echo, so each of those line
     breaks is inside a Blade comment, which is stripped before anything else
     compiles. --}}
@foreach ($spans as $span)
@if (isset($span['href']))<a href="{{ $span['href'] }}" rel="noopener noreferrer nofollow">{{ $span['text'] }}</a>{{--
--}}@elseif (! empty($span['strong']))<strong>{{ $span['text'] }}</strong>{{--
--}}@elseif (! empty($span['code']))<code>{{ $span['text'] }}</code>{{--
--}}@else{{ $span['text'] }}{{--
--}}@endif
@endforeach

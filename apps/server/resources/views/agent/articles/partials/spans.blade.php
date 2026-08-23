{{-- Spans render as elements, never as a string of markup: the same rule the
     widget follows, so the preview cannot be safe while the widget is not. --}}
@foreach ($spans as $span)
@if (isset($span['href'])){{-- --}}<a href="{{ $span['href'] }}" rel="noopener noreferrer nofollow">{{ $span['text'] }}</a>{{-- --}}
@elseif (! empty($span['strong'])){{-- --}}<strong>{{ $span['text'] }}</strong>{{-- --}}
@elseif (! empty($span['code'])){{-- --}}<code>{{ $span['text'] }}</code>{{-- --}}
@else{{-- --}}{{ $span['text'] }}{{-- --}}
@endif
@endforeach

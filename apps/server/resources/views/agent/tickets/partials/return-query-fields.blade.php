@foreach ($ticketReturnQuery as $queryName => $queryValue)
    <input type="hidden" name="{{ $queryName }}" value="{{ $queryValue }}">
@endforeach

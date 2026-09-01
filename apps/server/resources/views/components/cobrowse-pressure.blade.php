@props(['counts'])

{{--
    The pressure sentence, composed rather than translated.

    The composition itself lives in `App\Support\CobrowsePressureSentence`,
    because the queue row needs the same sentence as a STRING to interpolate
    into `conversations.row.pressure` and cannot render a component into a
    translation argument. While the logic lived only here, the queue kept using
    the English value and marking it English -- honest, and still English on a
    German page.
--}}
{{ \App\Support\CobrowsePressureSentence::for($counts) }}

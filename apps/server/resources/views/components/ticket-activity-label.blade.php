@props([
    'label',
    'subjectChange' => null,
])

@if ($subjectChange)
    @php
        // The sentence belongs to the dashboard catalogue; its old and new
        // subjects belong to whoever authored them. Escape those values before
        // placing their explicit unknown-language spans into the translation.
        $oldSubject = '<span lang="">'.e($subjectChange['old']).'</span>';
        $newSubject = '<span lang="">'.e($subjectChange['new']).'</span>';
    @endphp
    {!! __('ticket_detail.activity.subject_changed', ['old' => $oldSubject, 'new' => $newSubject]) !!}@if (filled($label)) {{ ' '.$label }}@endif
@else
    {{ $label }}
@endif

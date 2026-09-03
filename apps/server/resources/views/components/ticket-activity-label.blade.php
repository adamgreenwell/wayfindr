@props([
    'label',
    'subjectChange' => null,
    'labelChange' => null,
])

@if ($labelChange)
    @php
        // Label names belong to the account, not the dashboard catalogue.
        // Escape the name before giving it an explicit unknown-language span
        // inside the translated activity sentence.
        $labelName = '<span lang="">'.e($labelChange['name']).'</span>';
    @endphp
    {!! __('ticket_detail.activity.label_'.$labelChange['action'], ['label' => $labelName]) !!}@if (filled($label)) {{ ' '.$label }}@endif
@elseif ($subjectChange)
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

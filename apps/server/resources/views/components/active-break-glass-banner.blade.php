@php
    $unknownLanguage = static fn (mixed $value): string => '<span lang="">'.e((string) $value).'</span>';
    $scopeHtml = static function (array $scope) use ($unknownLanguage): string {
        $html = e($scope['label']);

        if ($scope['value'] !== null && $scope['value'] !== '') {
            $html .= ' '.$unknownLanguage($scope['value']);
        }

        return $html;
    };
@endphp

<section class="section break-glass-banner" aria-labelledby="operator-access-banner-heading">
    <div class="section-header">
        <h2 id="operator-access-banner-heading">{{ __('operator_access.banner.title') }}</h2>
        @if ($agent->hasAccountPermission(\App\Enums\AccountPermission::ManageOperatorAccess))
            <a class="button secondary" href="{{ route('dashboard.account.break-glass.index') }}">{{ __('operator_access.banner.review') }}</a>
        @endif
    </div>
    <div class="notice-copy">
        @foreach ($grants as $grant)
            <p>{!! __('operator_access.banner.grant', [
                'requester' => $grant['requester'] !== null
                    ? $unknownLanguage($grant['requester'])
                    : e(__('operator_access.banner.former_operator')),
                'scope' => $scopeHtml($grant['scope']),
                'until' => e($grant['until']),
                'elapsed' => e($grant['elapsed']),
                'self_approved' => $grant['self_approved']
                    ? e(__('operator_access.banner.self_approved'))
                    : '',
            ]) !!}</p>
        @endforeach
    </div>
</section>

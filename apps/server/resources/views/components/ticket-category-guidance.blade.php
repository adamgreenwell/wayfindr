@props(['categories'])

<div class="notice-list" aria-label="Category guide">
    @foreach ($categories as $category)
        <p>
            {{ $category['label'] }} - {{ $category['description'] }}
            @if (isset($category['guidance']))
                <br>
                <span>{{ $category['guidance'] }}</span>
            @endif
        </p>
    @endforeach
</div>

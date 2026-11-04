@extends( 'layout.dashboard' )

@section( 'layout.dashboard.body' )
<div id="dashboard-content" class="h-full overflow-y-auto">
    <div class="px-4 py-6">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-fontcolor">{{ $title }}</h2>
            <p class="text-sm text-fontcolor-soft">{{ $description }}</p>
        </div>
        <zash-dine-floor></zash-dine-floor>
    </div>
</div>
@endsection

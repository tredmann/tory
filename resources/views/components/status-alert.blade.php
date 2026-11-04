@if (session('status'))
    <div role="alert" class="alert alert-success alert-soft text-sm">
        <span>{{ session('status') }}</span>
    </div>
@endif

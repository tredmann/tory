<x-layouts::guest :title="__('Verify email')">
    <h1 class="card-title">{{ __('Verify your email address') }}</h1>
    <p class="text-sm text-base-content/70">
        {{ __('Thanks for signing up! Before getting started, please verify your email address by clicking the link we just emailed to you.') }}
    </p>

    @if (session('status') === 'verification-link-sent')
        <div role="alert" class="alert alert-success alert-soft text-sm">
            <span>{{ __('A new verification link has been sent to your email address.') }}</span>
        </div>
    @endif

    <div class="card-actions items-center justify-between">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn btn-ghost btn-sm">{{ __('Log out') }}</button>
        </form>

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('Resend verification email') }}</button>
        </form>
    </div>
</x-layouts::guest>

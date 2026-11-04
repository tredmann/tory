<x-layouts::guest :title="__('Forgot password')">
    <h1 class="card-title">{{ __('Forgot your password?') }}</h1>
    <p class="text-sm text-base-content/70">
        {{ __('Enter your email address and we will send you a link to reset your password.') }}
    </p>

    <x-status-alert />

    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
        @csrf

        <fieldset class="fieldset">
            <label class="label" for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="input w-full @error('email') input-error @enderror">
            <x-input-error for="email" />
        </fieldset>

        <div class="card-actions items-center justify-between">
            <a href="{{ route('login') }}" class="link link-hover text-sm">{{ __('Back to log in') }}</a>
            <button type="submit" class="btn btn-primary">{{ __('Email reset link') }}</button>
        </div>
    </form>
</x-layouts::guest>

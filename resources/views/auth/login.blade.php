<x-layouts::guest :title="__('Log in')">
    <h1 class="card-title">{{ __('Log in') }}</h1>

    <x-status-alert />

    <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
        @csrf

        <fieldset class="fieldset">
            <label class="label" for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="input w-full @error('email') input-error @enderror">
            <x-input-error for="email" />
        </fieldset>

        <fieldset class="fieldset">
            <label class="label" for="password">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input w-full @error('password') input-error @enderror">
            <x-input-error for="password" />
        </fieldset>

        <label class="label cursor-pointer justify-start gap-2">
            <input type="checkbox" name="remember" class="checkbox checkbox-sm">
            <span>{{ __('Remember me') }}</span>
        </label>

        <div class="card-actions items-center justify-between">
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="link link-hover text-sm">{{ __('Forgot your password?') }}</a>
            @endif
            <button type="submit" class="btn btn-primary">{{ __('Log in') }}</button>
        </div>
    </form>

    @if (Route::has('register'))
        <p class="mt-4 text-center text-sm text-base-content/70">
            {{ __("Don't have an account?") }}
            <a href="{{ route('register') }}" class="link link-primary">{{ __('Register') }}</a>
        </p>
    @endif
</x-layouts::guest>

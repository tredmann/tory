<x-layouts::guest :title="__('Register')">
    <h1 class="card-title">{{ __('Create an account') }}</h1>

    <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-4">
        @csrf

        <fieldset class="fieldset">
            <label class="label" for="name">{{ __('Name') }}</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name" class="input w-full @error('name') input-error @enderror">
            <x-input-error for="name" />
        </fieldset>

        <fieldset class="fieldset">
            <label class="label" for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" class="input w-full @error('email') input-error @enderror">
            <x-input-error for="email" />
        </fieldset>

        <fieldset class="fieldset">
            <label class="label" for="password">{{ __('Password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="input w-full @error('password') input-error @enderror">
            <x-input-error for="password" />
        </fieldset>

        <fieldset class="fieldset">
            <label class="label" for="password_confirmation">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input w-full">
        </fieldset>

        <div class="card-actions justify-end">
            <button type="submit" class="btn btn-primary">{{ __('Register') }}</button>
        </div>
    </form>

    <p class="mt-4 text-center text-sm text-base-content/70">
        {{ __('Already registered?') }}
        <a href="{{ route('login') }}" class="link link-primary">{{ __('Log in') }}</a>
    </p>
</x-layouts::guest>

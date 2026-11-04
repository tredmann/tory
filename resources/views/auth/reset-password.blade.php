<x-layouts::guest :title="__('Reset password')">
    <h1 class="card-title">{{ __('Reset your password') }}</h1>

    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <fieldset class="fieldset">
            <label class="label" for="email">{{ __('Email') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email', $request->email) }}" required autofocus autocomplete="email" class="input w-full @error('email') input-error @enderror">
            <x-input-error for="email" />
        </fieldset>

        <fieldset class="fieldset">
            <label class="label" for="password">{{ __('New password') }}</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="input w-full @error('password') input-error @enderror">
            <x-input-error for="password" />
        </fieldset>

        <fieldset class="fieldset">
            <label class="label" for="password_confirmation">{{ __('Confirm password') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="input w-full">
        </fieldset>

        <div class="card-actions justify-end">
            <button type="submit" class="btn btn-primary">{{ __('Reset password') }}</button>
        </div>
    </form>
</x-layouts::guest>

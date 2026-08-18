@extends('signup::layouts.app', ['title' => 'Create Your Store'])

@section('content')
    <div class="card">
        <h1>Create your store</h1>
        <form method="POST" action="{{ route('signup.store') }}">
            @csrf
            <label for="owner_name">Your name</label>
            <input id="owner_name" type="text" name="owner_name" value="{{ old('owner_name') }}" required autofocus>

            <label for="owner_email">Your email</label>
            <input id="owner_email" type="email" name="owner_email" value="{{ old('owner_email') }}" required>

            <label for="slug">Store address</label>
            <input id="slug" type="text" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9][a-z0-9-]*[a-z0-9]" minlength="3" maxlength="32">
            <p class="hint">Your store will be available at <strong>{yourstoreaddress}.{{ config('platform.base_domain') }}</strong>. Lowercase letters, numbers, and hyphens only.</p>

            <label for="password">Password</label>
            <input id="password" type="password" name="password" required minlength="8">

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8">

            @if (config('platform.signup.turnstile.enabled'))
                {{-- TASK-MVP-006. Site key only - never the secret. Cloudflare's
                     own implicit-render widget; the server-side check in
                     SignupController::store() is the real security boundary,
                     not this widget's presence/absence on its own. --}}
                <div class="cf-turnstile" data-sitekey="{{ config('platform.signup.turnstile.site_key') }}"></div>
            @endif

            <button type="submit">Create my store</button>
        </form>
    </div>

    @if (config('platform.signup.turnstile.enabled'))
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
@endsection

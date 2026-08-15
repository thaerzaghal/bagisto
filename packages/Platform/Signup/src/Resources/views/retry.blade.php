@extends('signup::layouts.app', ['title' => 'Finish Setting Up Your Store'])

@section('content')
    <div class="card">
        <h1>Finish setting up {{ $tenant->id }}</h1>
        <p>For security, please re-enter your password to continue.</p>
        <form method="POST" action="{{ $submitUrl }}">
            @csrf
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required minlength="8" autofocus>

            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required minlength="8">

            <button type="submit">Finish setup</button>
        </form>
    </div>
@endsection

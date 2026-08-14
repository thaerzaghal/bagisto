@extends('platform::layouts.app', ['title' => 'Platform Admin Login'])

@section('content')
    <div class="card" style="max-width: 360px; margin: 3rem auto;">
        <h1 style="font-size: 1.2rem;">Platform Admin Login</h1>
        <form method="POST" action="{{ route('platform.login.store') }}">
            @csrf
            <p>
                <label for="email">Email</label><br>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus style="width: 100%;">
            </p>
            <p>
                <label for="password">Password</label><br>
                <input id="password" type="password" name="password" required style="width: 100%;">
            </p>
            <button type="submit">Log in</button>
        </form>
    </div>
@endsection

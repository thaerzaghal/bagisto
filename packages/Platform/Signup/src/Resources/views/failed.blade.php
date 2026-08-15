@extends('signup::layouts.app', ['title' => 'Store Setup Failed'])

@section('content')
    <div class="card">
        <h1>We hit a problem setting up your store</h1>
        <p>Nothing was charged and your store address is still reserved for you. You can try again below.</p>
        <p><a href="{{ $retryUrl }}">Try again</a></p>
    </div>
@endsection

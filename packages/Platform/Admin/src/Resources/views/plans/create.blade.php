@extends('platform::layouts.app', ['title' => 'Create Plan'])

@section('content')
    <p><a href="{{ route('platform.plans.index') }}">&larr; Back to plans</a></p>

    <h1>Create Plan</h1>

    <div class="card">
        <form method="POST" action="{{ route('platform.plans.store') }}">
            @csrf

            <p>
                <label for="code">Code</label><br>
                <input type="text" id="code" name="code" value="{{ old('code') }}" placeholder="e.g. enterprise">
                <br><small>Lowercase letters, numbers, hyphens, underscores only. Cannot be changed after creation.</small>
            </p>

            <p>
                <label for="name">Name</label><br>
                <input type="text" id="name" name="name" value="{{ old('name') }}">
            </p>

            <p>
                <label for="description">Description</label><br>
                <textarea id="description" name="description">{{ old('description') }}</textarea>
            </p>

            <p>
                <label for="sort_order">Sort order</label><br>
                <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', 0) }}" min="0">
            </p>

            <p>
                <label>
                    <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                    Active (available for assignment)
                </label>
            </p>

            <button type="submit">Create Plan</button>
        </form>
    </div>
@endsection

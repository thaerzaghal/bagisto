@extends('platform::layouts.app', ['title' => 'Plans'])

@section('content')
    <p style="display: flex; justify-content: space-between; align-items: center;">
        <h1 style="margin: 0;">Plans</h1>
        <a href="{{ route('platform.plans.create') }}"><button type="button">Create Plan</button></a>
    </p>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Active</th>
                <th>Sort order</th>
                <th>Features configured</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($plans as $plan)
                <tr>
                    <td><a href="{{ route('platform.plans.show', $plan) }}">{{ $plan->code }}</a></td>
                    <td>{{ $plan->name }}</td>
                    <td>{{ $plan->is_active ? 'Yes' : 'No' }}</td>
                    <td>{{ $plan->sort_order }}</td>
                    <td>{{ $plan->features_count }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No plans configured.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection

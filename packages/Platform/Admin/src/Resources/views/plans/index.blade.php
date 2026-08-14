@extends('platform::layouts.app', ['title' => 'Plans'])

@section('content')
    <h1>Plans</h1>

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
                    <td>{{ $plan->code }}</td>
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

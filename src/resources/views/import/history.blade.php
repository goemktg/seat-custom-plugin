@extends('web::layouts.grids.12')

@section('title', 'Doctrine site changes')
@section('page_header', 'Doctrine site changes')
@section('page_description', $corporation->name)

@section('full')
  <div class="card">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title mr-auto">Changes from the doctrine site</h3>
      <a href="{{ route('custom-plugin.fits.index', $corporation->corporation_id) }}" class="btn btn-sm btn-default">Doctrine Fits</a>
    </div>
    @if($events->isEmpty())
      <div class="card-body">
        <p class="mb-0 text-muted">No changes in the last {{ $keepDays }} days.</p>
      </div>
    @else
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th scope="col">When (UTC)</th>
              <th scope="col">Doctrine</th>
              <th scope="col">Change</th>
              <th scope="col">By</th>
            </tr>
          </thead>
          <tbody>
            @foreach($events as $event)
              <tr>
                <td class="text-nowrap small">{{ \Illuminate\Support\Carbon::parse($event->created_at)->format('Y-m-d H:i') }}</td>
                <td>{{ $event->doctrine_label }}</td>
                <td>@include('custom-plugin::import._event', ['event' => $event])</td>
                <td class="small text-muted">{{ $event->created_by_name ?? 'hourly sync' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex flex-wrap align-items-center">
        <span class="small text-muted mr-auto">Kept for {{ $keepDays }} days.</span>
        {{ $events->links() }}
      </div>
    @endif
  </div>
@stop

@include('custom-plugin::partials.menu-active')

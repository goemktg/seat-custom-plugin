@extends('web::layouts.grids.12')

@section('title', $title)
@section('page_header', $title)

@section('full')
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Choose a corporation</h3>
    </div>
    @if($corporations->isEmpty())
      <div class="card-body">
        <p class="mb-1">You have no corporation you can open here.</p>
        <p class="text-muted mb-0">
          Access is given per corporation. Ask a SeAT administrator for the
          “{{ $route === 'custom-plugin.hangar.show' ? 'View Hangar Check' : 'Manage doctrine fits' }}”
          permission with a filter for your corporation. The corporation also needs its assets synced in SeAT.
        </p>
      </div>
    @else
      <div class="list-group list-group-flush">
        @foreach($corporations as $corp)
          <a href="{{ route($route, $corp->corporation_id) }}" class="list-group-item list-group-item-action d-flex align-items-center">
            <img src="https://images.evetech.net/corporations/{{ $corp->corporation_id }}/logo?size=32" class="mr-2 rounded" width="24" height="24" alt="" loading="lazy">
            <span>{{ $corp->name }}</span>
            <span class="text-muted ml-2">[{{ $corp->ticker }}]</span>
          </a>
        @endforeach
      </div>
    @endif
  </div>
@stop

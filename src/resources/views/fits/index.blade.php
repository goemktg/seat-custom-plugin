@extends('web::layouts.grids.12')

@section('title', 'Doctrine Fits')
@section('page_header', 'Doctrine Fits')
@section('page_description', $corporation->name)

@section('full')
  @if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
  @endif
  @if($errors->any())
    <div class="alert alert-danger mb-3">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
  @endif

  <div class="row">
    <div class="col-xl-8">
      <div class="card">
        <div class="card-header d-flex align-items-center">
          <h3 class="card-title mr-auto">Fits</h3>
          <a href="{{ route('custom-plugin.hangar.show', $corporation->corporation_id) }}" class="btn btn-sm btn-default mr-2">Hangar Check</a>
          <a href="{{ route('custom-plugin.fits.create', $corporation->corporation_id) }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add fit</a>
        </div>
        @if($fits->isEmpty())
          <div class="card-body">
            <p class="mb-1"><strong>No fits yet.</strong></p>
            <p class="text-muted mb-0">Add a fit by pasting its EFT. Hangar Check then compares every ship of that hull in the corp hangar against it.</p>
          </div>
        @else
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th scope="col">Doctrine</th>
                  <th scope="col">Fit</th>
                  <th scope="col">Hull</th>
                  <th scope="col">Targets</th>
                  <th scope="col">Last edit</th>
                  <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                @foreach($fits as $fit)
                  <tr>
                    <td>{{ $fit->doctrine }}</td>
                    <td>{{ $fit->fit_name }}</td>
                    <td>{{ $hulls[$fit->ship_type_id] ?? ('Type #' . $fit->ship_type_id) }}</td>
                    <td class="small">
                      @forelse($targets->get($fit->id, collect()) as $t)
                        <div>{{ $stagings->firstWhere('id', $t->staging_id)?->name }}: <strong>{{ $t->target }}</strong></div>
                      @empty
                        <span class="text-muted">none</span>
                      @endforelse
                    </td>
                    <td class="small text-muted">
                      {{ $fit->updated_at?->diffForHumans() }}
                      @if($fit->updated_by && isset($users[$fit->updated_by])) by {{ $users[$fit->updated_by] }}@endif
                    </td>
                    <td class="text-right">
                      <a href="{{ route('custom-plugin.fits.edit', [$corporation->corporation_id, $fit->id]) }}" class="btn btn-sm btn-default">Edit</a>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Stagings</h3>
        </div>
        <div class="card-body">
          <p class="text-muted small">Stations or structures whose corp hangar Hangar Check looks at. Targets are set per staging.</p>
          @if($stagings->isEmpty())
            <p class="mb-3">No stagings yet.</p>
          @else
            <ul class="list-unstyled mb-3">
              @foreach($stagings as $s)
                <li class="d-flex align-items-center py-1 border-bottom">
                  <span class="mr-auto">{{ $s->name }}</span>
                  <form method="post" action="{{ route('custom-plugin.stagings.destroy', [$corporation->corporation_id, $s->id]) }}"
                        data-confirm="Remove {{ $s->name }}? Its targets are deleted too." onsubmit="return confirm(this.dataset.confirm)">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-link text-danger">Remove</button>
                  </form>
                </li>
              @endforeach
            </ul>
          @endif

          @if($availableLocations === [])
            <p class="text-muted small mb-0">No other office locations found in this corporation's synced assets.</p>
          @else
            <form method="post" action="{{ route('custom-plugin.stagings.store', $corporation->corporation_id) }}">
              @csrf
              <label for="cp-location" class="small font-weight-bold">Add staging</label>
              <div class="input-group input-group-sm">
                <select id="cp-location" name="location_id" class="form-control">
                  @foreach($availableLocations as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                  @endforeach
                </select>
                <div class="input-group-append">
                  <button type="submit" class="btn btn-default">Add</button>
                </div>
              </div>
            </form>
          @endif
        </div>
      </div>
    </div>
  </div>
@stop

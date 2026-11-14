@extends('web::layouts.grids.12')

@php($title = $fit ? 'Edit fit · ' . $fit->fit_name : 'Add fit')
@php($bayLabels = ['fuel_bay' => 'Fuel bay', 'fleet_hangar' => 'Fleet hangar', 'fighter_bay' => 'Fighter bay', 'ship_maintenance_bay' => 'Ship maintenance bay'])

@section('title', $title)
@section('page_header', $title)
@section('page_description', $corporation->name)

@section('full')
  <form method="post" id="cp-fit-form"
        action="{{ $fit ? route('custom-plugin.fits.update', [$corporation->corporation_id, $fit->id]) : route('custom-plugin.fits.store', $corporation->corporation_id) }}">
    @csrf
    @if($fit)
      @method('PUT')
      <input type="hidden" name="version" value="{{ old('version', $fit->version()) }}">
    @endif

    <div class="row">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-body">
            <div class="form-row">
              <div class="form-group col-sm-6">
                <label for="cp-doctrine">Doctrine</label>
                <input type="text" id="cp-doctrine" name="doctrine" maxlength="100" required
                       class="form-control @error('doctrine') is-invalid @enderror"
                       value="{{ old('doctrine', $fit?->doctrine) }}">
                @error('doctrine')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
              <div class="form-group col-sm-6">
                <label for="cp-fit-name">Fit name</label>
                <input type="text" id="cp-fit-name" name="fit_name" maxlength="100"
                       class="form-control @error('fit_name') is-invalid @enderror"
                       value="{{ old('fit_name', $fit?->fit_name) }}" aria-describedby="cp-fit-name-help">
                <small id="cp-fit-name-help" class="form-text text-muted">
                  Hangar Check compares a ship with this fit only when the ship's in-game name is exactly this, case included.
                  Leave empty to use the name from the EFT's first line.
                </small>
                @error('fit_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
            </div>

            <div class="form-group mb-0">
              <label for="cp-eft">EFT</label>
              <textarea id="cp-eft" name="eft" rows="18" required spellcheck="false"
                        class="form-control text-monospace @error('eft') is-invalid @enderror" style="font-size: .8125rem"
                        placeholder="[Zirnitra, Zirni II]&#10;Damage Control II&#10;...">{{ old('eft', $fit?->eft) }}</textarea>
              @error('eft')<div class="invalid-feedback">{{ $message }}</div>@enderror
              <small class="form-text text-muted">
                Copy from the in-game fitting window (Copy to clipboard) and paste here. Modules are checked per slot;
                everything after the rigs (subsystems, drones, fighters, cargo) is checked as a minimum quantity
                anywhere in the cargo hold, drone bay, fighter bay or fitted subsystems.
              </small>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card">
          <div class="card-header"><h3 class="card-title">Holds</h3></div>
          <div class="card-body">
            <p class="text-muted small">One item per line, as <code class="text-body">Name 100</code> or <code class="text-body">Name x100</code>. Ships need at least this much.</p>
            @foreach($bayFields as $field => $bay)
              <div class="form-group">
                <label for="cp-{{ $field }}">{{ $bayLabels[$field] }}</label>
                <textarea id="cp-{{ $field }}" name="{{ $field }}" rows="{{ $field === 'fuel_bay' || $field === 'fleet_hangar' ? 3 : 2 }}" spellcheck="false"
                          class="form-control form-control-sm @error($field) is-invalid @enderror">{{ old($field, \Goemktg\Seat\SeatCustomPlugin\Services\FitInput::toLines($fit?->bays[$bay] ?? null)) }}</textarea>
                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
              </div>
            @endforeach
            <div class="form-group mb-0">
              <label for="cp-container">Container contents</label>
              <textarea id="cp-container" name="container" rows="4" spellcheck="false" aria-describedby="cp-container-help"
                        class="form-control form-control-sm @error('container') is-invalid @enderror">{{ old('container', \Goemktg\Seat\SeatCustomPlugin\Services\FitInput::toLines($fit?->container)) }}</textarea>
              <small id="cp-container-help" class="form-text text-muted">Items inside containers in the cargo hold or fleet hangar.</small>
              @error('container')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3 class="card-title">Targets</h3></div>
          <div class="card-body">
            @if($stagings->isEmpty())
              <p class="text-muted mb-0">Add a staging on the <a href="{{ route('custom-plugin.fits.index', $corporation->corporation_id) }}">Doctrine Fits</a> page to set targets.</p>
            @else
              @foreach($stagings as $s)
                <div class="form-group row mb-2">
                  <label for="cp-target-{{ $s->id }}" class="col-8 col-form-label col-form-label-sm">{{ $s->name }}</label>
                  <div class="col-4">
                    <input type="number" min="0" max="10000" id="cp-target-{{ $s->id }}" name="targets[{{ $s->id }}]"
                           class="form-control form-control-sm text-right @error('targets.' . $s->id) is-invalid @enderror"
                           value="{{ old('targets.' . $s->id, $targets[$s->id] ?? 0) }}">
                  </div>
                </div>
              @endforeach
            @endif
            <div class="form-group row mb-0 mt-3">
              <label for="cp-sort" class="col-8 col-form-label col-form-label-sm">Sort order</label>
              <div class="col-4">
                <input type="number" min="0" max="65535" id="cp-sort" name="sort_order" class="form-control form-control-sm text-right"
                       value="{{ old('sort_order', $fit?->sort_order ?? 0) }}">
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </form>

  <div class="d-flex flex-wrap align-items-center mb-4">
    <button type="submit" form="cp-fit-form" class="btn btn-primary mr-2">Save fit</button>
    <a href="{{ route('custom-plugin.fits.index', $corporation->corporation_id) }}" class="btn btn-default">Cancel</a>
    @if($fit)
      <form method="post" class="ml-auto" action="{{ route('custom-plugin.fits.destroy', [$corporation->corporation_id, $fit->id]) }}"
            data-confirm="Delete {{ $fit->fit_name }}? This cannot be undone." onsubmit="return confirm(this.dataset.confirm)">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-outline-danger">Delete fit</button>
      </form>
    @endif
  </div>
@stop

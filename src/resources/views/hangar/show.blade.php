@extends('web::layouts.grids.12')

@section('title', 'Hangar Check')
@section('page_header', 'Hangar Check')
@section('page_description', $corporation->name)

@section('full')
  <div class="d-flex flex-wrap align-items-center mb-3 cp-toolbar">
    @if($corporations->count() > 1)
      <label for="cp-corp" class="sr-only">Corporation</label>
      <select id="cp-corp" class="form-control form-control-sm w-auto mr-2 mb-2" onchange="window.location = this.value">
        @foreach($corporations as $corp)
          <option value="{{ route('custom-plugin.hangar.show', $corp->corporation_id) }}" @selected($corp->corporation_id === $corporation->corporation_id)>{{ $corp->name }}</option>
        @endforeach
      </select>
    @endif

    @if($stagings->isNotEmpty())
      <form method="get" class="form-inline mr-2 mb-2">
        <label for="cp-staging" class="sr-only">Staging</label>
        <select id="cp-staging" name="staging" class="form-control form-control-sm" onchange="this.form.submit()">
          @foreach($stagings as $s)
            <option value="{{ $s->id }}" @selected($s->id === $staging->id)>{{ $s->name }}</option>
          @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-default ml-1">Show</button></noscript>
      </form>
    @endif

    @if($result !== null && $result['syncedAt'] !== null)
      <span class="text-muted small mb-2" title="{{ $result['syncedAt'] }}">
        Assets synced {{ \Illuminate\Support\Carbon::parse($result['syncedAt'])->diffForHumans() }}
      </span>
    @endif

    <a href="{{ route('custom-plugin.fits.index', $corporation->corporation_id) }}" class="btn btn-sm btn-default ml-auto mb-2">
      <i class="fas fa-rocket"></i> Manage fits
    </a>
  </div>

  @if($staging === null)
    <div class="card">
      <div class="card-body">
        <p class="mb-1"><strong>No staging set up for {{ $corporation->name }}.</strong></p>
        <p class="text-muted mb-0">
          A staging is the station or structure whose corp hangar is checked.
          Add one on <a href="{{ route('custom-plugin.fits.index', $corporation->corporation_id) }}">Doctrine Fits</a>.
        </p>
      </div>
    </div>
  @else
    @php($report = $result['report'])
    @if(! $result['hasOffice'])
      <div class="alert alert-warning">
        SeAT has no corporation office at {{ $staging->name }} in the synced assets, so there is nothing to check.
        If the office is new, wait for the next asset update.
      </div>
    @endif

    <div class="row">
      <div class="col-xl-7">
        <div class="card">
          <div class="card-header p-2">
            <ul class="nav nav-pills" role="tablist">
              <li class="nav-item">
                <a class="nav-link active" data-toggle="tab" href="#cp-problems" role="tab" aria-controls="cp-problems" aria-selected="true">
                  Needs fixing <span class="badge {{ count($report->problems) ? 'badge-danger' : 'badge-secondary' }}">{{ count($report->problems) }}</span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#cp-unmatched" role="tab" aria-controls="cp-unmatched" aria-selected="false">
                  No matching fit <span class="badge badge-secondary">{{ count($report->unmatched) }}</span>
                </a>
              </li>
            </ul>
          </div>
          <div class="tab-content">
            <div class="tab-pane active" id="cp-problems" role="tabpanel">
              @forelse($report->problems as $problem)
                @php($ship = $problem['ship'])
                @php($match = $problem['match'])
                <div class="cp-ship">
                  <div class="d-flex flex-wrap align-items-baseline mb-1">
                    <strong class="mr-2">{{ $ship->name ?: ($result['names'][$ship->typeId] ?? 'Ship') }}</strong>
                    <span class="text-muted mr-2">{{ $result['names'][$ship->typeId] ?? '' }} · {{ $match->fit->name }}</span>
                    @if($match->itemsToAdd() > 0)
                      <span class="badge badge-danger mr-1">{{ $match->itemsToAdd() }} to add</span>
                    @endif
                    @if($match->itemsToRemove() > 0)
                      <span class="badge badge-warning mr-1">{{ $match->itemsToRemove() }} to remove</span>
                    @endif
                    @if($ship->division !== null)
                      <span class="text-muted small ml-auto">Div {{ $ship->division }}</span>
                    @endif
                  </div>
                  @include('custom-plugin::hangar._diff', ['match' => $match, 'names' => $result['names']])
                </div>
              @empty
                <div class="card-body">
                  @if($report->conformantCount > 0)
                    <p class="mb-0"><i class="fas fa-check text-success"></i> All {{ $report->conformantCount }} ships with a registered fit match it.</p>
                  @else
                    <p class="mb-0 text-muted">No ships here match a registered fit's hull.</p>
                  @endif
                </div>
              @endforelse
            </div>
            <div class="tab-pane" id="cp-unmatched" role="tabpanel">
              @forelse($report->unmatched as $ship)
                <div class="cp-ship d-flex flex-wrap align-items-baseline">
                  <strong class="mr-2">{{ $ship->name ?: ($result['names'][$ship->typeId] ?? 'Ship') }}</strong>
                  <span class="text-muted mr-2">{{ $result['names'][$ship->typeId] ?? ('Type #' . $ship->typeId) }}</span>
                  @if($ship->division !== null)
                    <span class="text-muted small ml-auto">Div {{ $ship->division }}</span>
                  @endif
                </div>
              @empty
                <div class="card-body"><p class="mb-0 text-muted">Every assembled ship here has a fit registered for its hull.</p></div>
              @endforelse
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-5">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Fits at {{ $staging->name }}</h3>
          </div>
          @if($report->fits === [])
            <div class="card-body">
              <p class="mb-0 text-muted">
                No doctrine fits yet.
                <a href="{{ route('custom-plugin.fits.create', $corporation->corporation_id) }}">Add the first fit</a>.
              </p>
            </div>
          @else
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0 cp-num">
                <thead>
                  <tr>
                    <th scope="col">Fit</th>
                    <th scope="col" class="text-right">Target</th>
                    <th scope="col" class="text-right">Match</th>
                    <th scope="col" class="text-right">Fixing</th>
                    <th scope="col" class="text-right">Short</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($report->fits as $row)
                    <tr>
                      <td>
                        {{ $row['name'] }}
                        @if($row['fit']->doctrine !== $row['name'])<span class="text-muted small">· {{ $row['fit']->doctrine }}</span>@endif
                      </td>
                      <td class="text-right">{{ $row['target'] }}</td>
                      <td class="text-right">{{ $row['match'] }}</td>
                      <td class="text-right">{{ $row['needFix'] ?: '' }}</td>
                      <td class="text-right">
                        @if($row['short'] > 0)
                          <strong class="text-danger">{{ $row['short'] }}</strong>
                        @elseif($row['target'] > 0)
                          <i class="fas fa-check text-success" aria-hidden="true"></i><span class="sr-only">Target met</span>
                        @endif
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="card-footer small text-muted">
              Short = target − matching ships. Ships that need fixing do not count toward the target.
            </div>
          @endif
        </div>
      </div>
    </div>
  @endif
@stop

@push('head')
  <style>
    .cp-ship { padding: .75rem 1.25rem; border-top: 1px solid rgba(0,0,0,.125); }
    .tab-pane > .cp-ship:first-child { border-top: 0; }
    .cp-strip { display: grid; gap: .25rem; }
    .cp-strip-row { display: grid; grid-template-columns: 6.5rem 1fr; gap: .5rem; align-items: start; }
    .cp-strip-row dt { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .7; padding-top: .2rem; margin: 0; }
    .cp-strip-row dd { margin: 0; display: flex; flex-wrap: wrap; gap: .25rem; }
    .cp-chip { font-weight: 500; font-size: .8125rem; white-space: normal; text-align: left; }
    .cp-num td, .cp-num th { font-variant-numeric: tabular-nums; }
    @media (max-width: 575.98px) { .cp-strip-row { grid-template-columns: 1fr; gap: .125rem; } }
  </style>
@endpush

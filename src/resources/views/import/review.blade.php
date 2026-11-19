{{-- Step 2 of the import window, loaded into #cp-import-body. Nothing is written until Import. --}}
@php
  $labels = [
      // neutral changes in dark-on-light (Bootstrap's blue and teal badges fail AA contrast); problems keep their colour
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::ADD => ['Add', 'badge-light border'],
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::UPDATE => ['Update', 'badge-light border'],
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::LINK => ['Link', 'badge-secondary'],
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::RETURNED => ['Back', 'badge-light border'],
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::MISSING => ['Gone', 'badge-warning'],
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::HELD => ['Held', 'badge-danger'],
      \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::FAILED => ['Not readable', 'badge-danger'],
  ];
  $importable = collect($reviews)->where('error', null)->count();
@endphp
<form method="post" action="{{ route('custom-plugin.fits.import.store', $corporation->corporation_id) }}" data-cp-import-step="review">
  @csrf
  <div class="modal-body">
    <p class="small text-muted mb-2">Step 2 of 2 · Review what importing does. Nothing changes until you press Import.</p>
    @foreach($reviews as $r)
      <section class="mb-3">
        @if($r['error'] !== null)
          <h4 class="h6 font-weight-bold mb-1">{{ $r['slug'] }}</h4>
          <div class="alert alert-danger py-2 mb-0">{{ $r['error'] }}</div>
        @else
          @php($actions = collect($r['actions'])->reject(fn ($a) => $a->kind === \Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction::UNCHANGED))
          @php($unchanged = count($r['actions']) - $actions->count())
          <input type="hidden" name="slugs[]" value="{{ $r['slug'] }}">
          <h4 class="h6 font-weight-bold mb-1">
            {{ $r['doctrine']->name }}
            <span class="small text-muted font-weight-normal">v{{ $r['doctrine']->version }}@if($r['doctrine']->status !== 'active') · {{ $r['doctrine']->status }}@endif</span>
          </h4>
          @if($actions->isEmpty())
            <p class="small text-muted mb-0">Already in step: {{ $unchanged }} {{ \Illuminate\Support\Str::plural('fit', $unchanged) }}, nothing to change.</p>
          @else
            <ul class="list-unstyled small mb-0">
              @foreach($actions as $a)
                <li class="border-bottom py-1">
                  @if($a->linkable())
                    <div class="custom-control custom-checkbox">
                      <input type="checkbox" class="custom-control-input" id="cp-link-{{ $r['slug'] }}-{{ $a->fitId }}" name="link[{{ $r['slug'] }}][]" value="{{ $a->fitId }}">
                      <label class="custom-control-label font-weight-normal" for="cp-link-{{ $r['slug'] }}-{{ $a->fitId }}">
                        <span class="badge badge-secondary">Link?</span> {{ $a->label }}: a fit added by hand has this name. Tick to let the doctrine site manage it; otherwise it stays held.
                      </label>
                    </div>
                  @else
                    <span class="badge {{ $labels[$a->kind][1] }}">{{ $labels[$a->kind][0] }}</span> {{ $a->label }}@if($a->detail !== null)<span class="text-muted">: {{ $a->detail }}</span>@endif
                  @endif
                </li>
              @endforeach
            </ul>
            @if($unchanged > 0)<p class="small text-muted mt-1 mb-0">{{ $unchanged }} more {{ \Illuminate\Support\Str::plural('fit', $unchanged) }} already in step.</p>@endif
          @endif
        @endif
      </section>
    @endforeach
    <p class="small text-muted mb-0">Required ship counts are not touched. New fits start with none; set them on each fit's Edit page.</p>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-default mr-auto" data-cp-import-back>Back</button>
    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
    @if($importable > 0)
      <button type="submit" class="btn btn-success">Import {{ $importable }} {{ \Illuminate\Support\Str::plural('doctrine', $importable) }}</button>
    @endif
  </div>
</form>

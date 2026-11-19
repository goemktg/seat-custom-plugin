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

  @if($corporations->count() > 1)
    <div class="d-flex flex-wrap align-items-center mb-3">
      <label for="cp-corp" class="sr-only">Corporation</label>
      <select id="cp-corp" class="form-control form-control-sm w-auto mw-100 mb-2" onchange="window.location = this.value">
        @foreach($corporations as $corp)
          <option value="{{ route('custom-plugin.fits.index', $corp->corporation_id) }}" @selected($corp->corporation_id === $corporation->corporation_id)>{{ $corp->name }}</option>
        @endforeach
      </select>
    </div>
  @endif

  <div class="row">
    <div class="col-xl-8">
      <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center">
          <h3 class="card-title mr-auto">Fits</h3>
          <a href="{{ route('custom-plugin.hangar.show', $corporation->corporation_id) }}" class="btn btn-sm btn-default mr-2">Hangar Check</a>
          @if($doctrineSite !== null)
            <button type="button" class="btn btn-sm btn-default mr-2" data-toggle="modal" data-target="#cp-import">Import from doctrine site</button>
          @endif
          <a href="{{ route('custom-plugin.fits.create', $corporation->corporation_id) }}" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> Add fit</a>
        </div>
        @if($fits->isEmpty())
          <div class="card-body">
            <p class="mb-1"><strong>No fits yet.</strong></p>
            <p class="text-muted mb-0">Add a fit by pasting its EFT. Hangar Check then compares every corp hangar ship named exactly like the fit against it.</p>
          </div>
        @else
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead>
                <tr>
                  <th scope="col">Doctrine</th>
                  <th scope="col">Fit</th>
                  <th scope="col">Hull</th>
                  <th scope="col">Required</th>
                  <th scope="col">Last edit</th>
                  <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
              </thead>
              <tbody>
                @foreach($fits as $fit)
                  <tr>
                    <td>
                      {{ $fit->doctrine }}
                      @isset($shared[$fit->id])
                        <span class="badge badge-light border ml-1" title="Shared fit, also in {{ implode(', ', array_slice($shared[$fit->id], 1)) }}. Hangar Check lists it once, in the Shared tab.">Shared</span>
                      @endisset
                    </td>
                    <td>
                      {{ $fit->fit_name }}
                      @if($doctrineSite === null || $fit->source_id === null)
                        {{-- sync off for this corporation: synced fits are edited like hand-made ones --}}
                      @elseif($fit->sync_state === 'missing')
                        <span class="badge badge-warning ml-1" title="Removed from the doctrine site {{ $fit->source_missing_at?->diffForHumans() }}. You can now edit or delete it.">Source gone</span>
                      @else
                        <span class="badge badge-light border ml-1" title="Doctrine, name and EFT follow the doctrine site."><i class="fas fa-lock fa-xs mr-1" aria-hidden="true"></i>Synced</span>
                      @endif
                    </td>
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
                    <td class="text-right text-nowrap">
                      @isset($move['from'][$fit->id])
                        <button type="button" class="btn btn-sm btn-default" data-toggle="modal" data-target="#cp-move"
                                data-cp-move="{{ $fit->id }}" data-action="{{ route('custom-plugin.fits.move', [$corporation->corporation_id, $fit->id]) }}"
                                aria-label="Move required counts and stock of {{ $fit->fit_name }}">Move…</button>
                      @endisset
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
      @if($doctrineSite !== null)
        @php($dot = ['ok' => 'text-success', 'attention' => 'text-warning', 'error' => 'text-danger', 'waiting' => 'text-muted'])
        @php($stateText = ['ok' => 'In step', 'attention' => 'Needs attention', 'error' => 'Last sync failed', 'waiting' => 'Waiting for first sync'])
        <div class="card">
          <div class="card-header d-flex align-items-center">
            <h3 class="card-title mr-auto">Doctrine site</h3>
            @if($doctrineSite['sources'] !== [])
              <form method="post" action="{{ route('custom-plugin.fits.import.sync', $corporation->corporation_id) }}" data-cp-busy="Syncing…">
                @csrf
                <button type="submit" class="btn btn-sm btn-default">Sync now</button>
              </form>
            @endif
          </div>
          <div class="card-body">
            @if($doctrineSite['sources'] === [])
              <p class="mb-1"><strong>No doctrines followed yet.</strong></p>
              <p class="text-muted small mb-0">Use Import from doctrine site to choose doctrines. Their fits then update every hour.</p>
            @else
              <ul class="list-unstyled mb-0">
                @foreach($doctrineSite['sources'] as $s)
                  <li class="d-flex flex-wrap align-items-baseline py-1 border-bottom">
                    <span class="mr-auto">
                      <i class="fas fa-circle fa-xs {{ $dot[$s['state']] }} mr-1" aria-hidden="true"></i><span class="sr-only">{{ $stateText[$s['state']] }}:</span>
                      {{ $s['name'] }}
                      @if($s['status'] !== null && $s['status'] !== 'active')<span class="badge badge-light border ml-1">{{ $s['status'] }}</span>@endif
                    </span>
                    <span class="small {{ $s['state'] === 'error' ? 'text-danger' : 'text-muted' }}">
                      @if($s['state'] === 'error')
                        {{ $s['error'] }}
                      @elseif($s['state'] === 'waiting')
                        not checked yet
                      @else
                        v{{ $s['version'] }} · {{ $s['checkedAt']?->diffForHumans() }}@if($s['problems']['held'] > 0) · {{ $s['problems']['held'] }} held @endif @if($s['problems']['failed'] > 0) · {{ $s['problems']['failed'] }} not readable @endif @if($s['shared'] > 0) · {{ $s['shared'] }} shared with other doctrines @endif
                      @endif
                    </span>
                  </li>
                @endforeach
              </ul>
              @if($doctrineSite['recent']->isNotEmpty())
                <h4 class="h6 text-muted text-uppercase small font-weight-bold mt-3 mb-1">Recent changes</h4>
                <ul class="list-unstyled small mb-0">
                  @foreach($doctrineSite['recent'] as $event)
                    <li class="py-1 border-bottom">
                      <span class="text-muted mr-1">{{ \Illuminate\Support\Carbon::parse($event->created_at)->diffForHumans(null, true, true) }}</span>
                      @include('custom-plugin::import._event', ['event' => $event])
                    </li>
                  @endforeach
                </ul>
              @endif
              <p class="small text-muted mt-2 mb-0">
                Checked every hour.
                <a href="{{ route('custom-plugin.fits.import.history', $corporation->corporation_id) }}">All changes</a>
              </p>
            @endif
          </div>
        </div>
      @endif

      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Stagings</h3>
        </div>
        <div class="card-body">
          <p class="text-muted small">Stations or structures whose corp hangar Hangar Check looks at. Each fit sets how many ships a staging requires.</p>
          @if($stagings->isEmpty())
            <p class="mb-3">No stagings yet.</p>
          @else
            <ul class="list-unstyled mb-3">
              @foreach($stagings as $s)
                <li class="d-flex align-items-center py-1 border-bottom">
                  <span class="mr-auto">{{ $s->name }}</span>
                  <form method="post" action="{{ route('custom-plugin.stagings.destroy', [$corporation->corporation_id, $s->id]) }}"
                        data-confirm="Remove {{ $s->name }}? The required ship counts for it are deleted too." onsubmit="return confirm(this.dataset.confirm)">
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

  @if($move['from'] !== [])
    <div class="modal fade" id="cp-move" tabindex="-1" role="dialog" aria-labelledby="cp-move-title" aria-hidden="true">
      <div class="modal-dialog" role="document">
        <form method="post" class="modal-content" id="cp-move-form" action="">
          @csrf
          <div class="modal-header">
            <h2 class="modal-title h5" id="cp-move-title">Move counts and stock</h2>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label for="cp-move-to">Move to</label>
              <select class="form-control" id="cp-move-to" name="to" aria-describedby="cp-move-hint"></select>
              <small class="form-text text-muted" id="cp-move-hint">Fits on the same hull are listed first.</small>
            </div>
            <div>
              {{-- only the refusal is announced; the table is read when the user moves into it --}}
              <div class="alert alert-danger py-2" id="cp-move-clash" role="alert" hidden></div>
              <div class="table-responsive">
                <table class="table table-sm mb-2">
                  <thead><tr><th scope="col">Staging</th><th scope="col">Required</th><th scope="col">Stock entries</th></tr></thead>
                  <tbody id="cp-move-rows"></tbody>
                </table>
              </div>
            </div>
            <p class="small text-muted mb-0" id="cp-move-note"></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="cp-move-submit">Move</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if($doctrineSite !== null)
    <div class="modal fade" id="cp-import" tabindex="-1" role="dialog" aria-labelledby="cp-import-title" aria-hidden="true"
         data-choose="{{ route('custom-plugin.fits.import.choose', $corporation->corporation_id) }}">
      <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h5" id="cp-import-title">Import from doctrine site</h2>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div id="cp-import-body" aria-live="polite"></div>
        </div>
      </div>
    </div>
  @endif
@stop

@if($move['from'] !== [])
  @push('javascript')
    <script type="application/json" id="cp-move-data">@json($move)</script>
    <script>
      (function () {
        var data = JSON.parse(document.getElementById('cp-move-data').textContent);
        var modal = document.getElementById('cp-move');
        var form = document.getElementById('cp-move-form');
        var select = document.getElementById('cp-move-to');
        var rows = document.getElementById('cp-move-rows');
        var clash = document.getElementById('cp-move-clash');
        var submit = document.getElementById('cp-move-submit');
        var from = null;
        var fromId = null;

        function cell(text, cls) {
          var td = document.createElement('td');
          td.textContent = text;
          if (cls) { td.className = cls; }
          return td;
        }

        function render() {
          var into = data.fits.find(function (f) { return String(f.id) === select.value; });
          var intoTargets = into ? into.targets : {};
          var stagings = Object.keys(Object.assign({}, from.targets, from.entries));
          stagings.sort(function (a, b) { return (data.stagings[a] || '').localeCompare(data.stagings[b] || ''); });
          var blocked = [];
          rows.replaceChildren();
          stagings.forEach(function (id) {
            var here = from.targets[id] || 0;
            var there = intoTargets[id] || 0;
            var tr = document.createElement('tr');
            var bad = here > 0 && there > 0;
            if (bad) { blocked.push(data.stagings[id] || ('Staging #' + id)); tr.className = 'text-danger font-weight-bold'; }
            tr.appendChild(cell(data.stagings[id] || ('Staging #' + id)));
            tr.appendChild(cell(here === 0 ? 'none' : (bad ? here + ' (already ' + there + ' there)' : here + ' → ' + here), here === 0 ? 'text-muted' : ''));
            var n = from.entries[id] || 0;
            tr.appendChild(cell(n === 0 ? 'none' : n + ' moved', n === 0 ? 'text-muted' : ''));
            rows.appendChild(tr);
          });
          clash.hidden = blocked.length === 0;
          clash.textContent = blocked.length === 0 ? '' : '"' + (into ? into.name : '') + '" already has a required count at ' + blocked.join(', ') + '. Clear it on that fit\'s Edit page first, then move.';
          submit.disabled = blocked.length > 0 || !into;
        }

        $(modal).on('show.bs.modal', function (e) {
          var button = e.relatedTarget;
          fromId = button.dataset.cpMove;
          from = data.from[fromId];
          form.action = button.dataset.action;
          document.getElementById('cp-move-title').textContent = 'Move counts and stock of "' + from.name + '"';
          document.getElementById('cp-move-note').textContent = '"' + from.name + '" stays in the list with no counts; delete it from its Edit page when you no longer need it.';
          var choices = data.fits.filter(function (f) { return String(f.id) !== String(fromId); });
          choices.sort(function (a, b) { return ((b.ship === from.ship) - (a.ship === from.ship)) || a.label.localeCompare(b.label); });
          select.replaceChildren();
          choices.forEach(function (f) {
            var o = document.createElement('option');
            o.value = f.id;
            o.textContent = f.label;
            select.appendChild(o);
          });
          submit.disabled = false;
          submit.textContent = 'Move';
          render();
        });
        $(modal).on('shown.bs.modal', function () { select.focus(); });
        select.addEventListener('change', render);
        form.addEventListener('submit', function () { submit.disabled = true; submit.textContent = 'Moving…'; });
      })();
    </script>
  @endpush
@endif

@if($doctrineSite !== null)
  @push('javascript')
    <script>
      (function () {
        var modal = document.getElementById('cp-import');
        var body = document.getElementById('cp-import-body');
        var token = 0;

        function show(html) {
          body.innerHTML = html;
          var first = body.querySelector('input:not([type=hidden]):not([disabled]), button[type=submit]');
          if (first) { first.focus(); }
        }

        function message(text) {
          var wrap = document.createElement('div');
          wrap.className = 'modal-body';
          var alert = document.createElement('div');
          alert.className = 'alert alert-danger mb-0';
          alert.setAttribute('role', 'alert');
          alert.textContent = text;
          wrap.appendChild(alert);
          body.replaceChildren(wrap);
        }

        function load(url, loadingText) {
          var mine = ++token;
          var wrap = document.createElement('div');
          wrap.className = 'modal-body text-muted';
          wrap.innerHTML = '<i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>';
          wrap.appendChild(document.createTextNode(loadingText));
          body.replaceChildren(wrap);
          fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) {
              if (r.status === 429) { throw new Error('Too many requests to the doctrine site. Wait a minute and try again.'); }
              if (!r.ok) { throw new Error('Could not load this step (HTTP ' + r.status + '). Reload the page and try again.'); }
              return r.text();
            })
            .then(function (html) { if (mine === token) { show(html); } })
            .catch(function (e) { if (mine === token) { message(e.message); } });
        }

        function chooseUrl(slugs) {
          var url = new URL(modal.dataset.choose, window.location.href);
          (slugs || []).forEach(function (s) { url.searchParams.append('slugs[]', s); });
          return url.toString();
        }

        $(modal).on('show.bs.modal', function () { load(chooseUrl(), 'Asking the doctrine site for its doctrines…'); });
        // Bootstrap focuses the dialog once it has opened; move on to the first choice if it is already there
        $(modal).on('shown.bs.modal', function () {
          var first = body.querySelector('input:not([type=hidden]):not([disabled]), button[type=submit]');
          if (first) { first.focus(); }
        });

        body.addEventListener('submit', function (e) {
          var form = e.target;
          if (form.dataset.cpImportStep === 'choose') {
            e.preventDefault();
            var params = new URLSearchParams(new FormData(form));
            if (!params.has('slugs[]')) { return; }
            load(form.action + '?' + params.toString(), 'Comparing with this corporation\'s fits…');
          } else if (form.dataset.cpImportStep === 'review') {
            var submit = form.querySelector('button[type=submit]');
            if (submit) { submit.disabled = true; submit.textContent = 'Importing…'; }
          }
        });

        body.addEventListener('click', function (e) {
          if (!e.target.closest('[data-cp-import-back]')) { return; }
          var slugs = Array.prototype.map.call(body.querySelectorAll('input[name="slugs[]"]'), function (i) { return i.value; });
          load(chooseUrl(slugs), 'Asking the doctrine site for its doctrines…');
        });

        document.querySelectorAll('form[data-cp-busy]').forEach(function (form) {
          form.addEventListener('submit', function () {
            var b = form.querySelector('button[type=submit]');
            if (b) { b.disabled = true; b.textContent = form.dataset.cpBusy; }
          });
        });
      })();
    </script>
  @endpush
@endif

@include('custom-plugin::partials.menu-active')

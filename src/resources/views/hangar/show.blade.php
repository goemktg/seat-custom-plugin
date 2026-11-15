@extends('web::layouts.grids.12')

@section('title', 'Hangar Check')
@section('page_header', 'Hangar Check')
@section('page_description', $corporation->name)

@section('full')
  <div class="d-flex flex-wrap align-items-center mb-3 cp-toolbar">
    @if($corporations->count() > 1)
      <label for="cp-corp" class="sr-only">Corporation</label>
      <select id="cp-corp" class="form-control form-control-sm w-auto mw-100 mr-2 mb-2" onchange="window.location = this.value">
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
      <span class="small mb-2" title="{{ $result['syncedAt'] }}">
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
          <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
              <li class="nav-item" role="presentation">
                <a class="nav-link active" id="cp-problems-tab" data-toggle="tab" href="#cp-problems" role="tab" aria-controls="cp-problems" aria-selected="true">
                  Needs fixing <span class="badge {{ count($report->problems) ? 'badge-danger' : 'badge-secondary' }}">{{ count($report->problems) }}</span>
                </a>
              </li>
              <li class="nav-item" role="presentation">
                <a class="nav-link" id="cp-unmatched-tab" data-toggle="tab" href="#cp-unmatched" role="tab" aria-controls="cp-unmatched" aria-selected="false">
                  Not named after a fit <span class="badge {{ $report->suggestions !== [] ? 'badge-warning' : 'badge-secondary' }}">{{ count($report->unmatched) }}</span>
                </a>
              </li>
            </ul>
          </div>
          <div class="tab-content">
            <div class="tab-pane active" id="cp-problems" role="tabpanel" aria-labelledby="cp-problems-tab">
              @forelse($report->problemGroups() as $group)
                @php($fit = $group['fit'])
                <h3 class="cp-group">
                  {{ $fit->name }}
                  <small class="text-muted">
                    {{ implode(' · ', array_filter([$result['names'][$fit->shipTypeId] ?? null, $fit->doctrine !== $fit->name ? $fit->doctrine : null], static fn ($part) => $part !== null)) }}
                    · {{ count($group['problems']) }} of {{ $group['named'] }} need fixing
                  </small>
                </h3>
                @foreach($group['problems'] as $problem)
                  @php($ship = $problem['ship'])
                  @php($match = $problem['match'])
                  <div class="cp-ship">
                    <div class="d-flex flex-wrap align-items-baseline mb-1">
                      @if($ship->division !== null)
                        <span class="text-muted small mr-2">Div {{ $ship->division }}</span>
                      @endif
                      @if($match->itemsToAdd() > 0)
                        <span class="badge badge-danger mr-1">{{ $match->itemsToAdd() }} to add</span>
                      @endif
                      @if($match->itemsToRemove() > 0)
                        <span class="badge badge-warning mr-1">{{ $match->itemsToRemove() }} to remove</span>
                      @endif
                    </div>
                    @include('custom-plugin::hangar._diff', ['match' => $match, 'names' => $result['names']])
                  </div>
                @endforeach
              @empty
                <div class="card-body">
                  @if($report->conformantCount > 0)
                    <p class="mb-0"><i class="fas fa-check text-success" aria-hidden="true"></i> All {{ $report->conformantCount }} ships named after a fit are fitted correctly.</p>
                  @else
                    <p class="mb-0 text-muted">No ship here is named after a registered fit.</p>
                  @endif
                  @if($report->unmatched !== [])
                    <p class="small mb-0 mt-2">
                      {{ count($report->unmatched) }} other {{ count($report->unmatched) === 1 ? 'ship is' : 'ships are' }} not named after a fit, so {{ count($report->unmatched) === 1 ? 'it is' : 'they are' }} not checked.
                      <a href="#cp-unmatched" class="cp-show-unmatched">See {{ count($report->unmatched) === 1 ? 'it' : 'them' }}</a>
                    </p>
                  @endif
                </div>
              @endforelse
            </div>
            <div class="tab-pane" id="cp-unmatched" role="tabpanel" aria-labelledby="cp-unmatched-tab">
              <span class="sr-only" role="status" aria-live="polite" id="cp-copy-status"></span>
              @if($report->unmatched !== [])
                <p class="small text-muted px-3 pt-3 mb-2">
                  Each ship is checked only against the fit with exactly its name (case included) and hull.
                  Rename it in game or add a fit with that name. Renames show after the next asset sync.
                </p>
              @endif
              @forelse($report->unmatched as $ship)
                <div class="cp-ship d-flex flex-wrap align-items-baseline">
                  @if(\Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport::nameKey($ship->name) !== '')
                    <strong class="mr-2">{{ $ship->name }}</strong>
                  @else
                    <em class="text-muted mr-2">Unnamed</em>
                  @endif
                  <span class="text-muted mr-2">{{ $result['names'][$ship->typeId] ?? ('Type #' . $ship->typeId) }}</span>
                  @if($ship->division !== null)
                    <span class="text-muted small ml-auto">Div {{ $ship->division }}</span>
                  @endif
                  @isset($report->suggestions[$ship->itemId])
                    @php($exact = $report->suggestions[$ship->itemId])
                    <div class="cp-hint small">
                      Rename to <code class="text-body cp-name">{{ $exact }}</code>
                      <button type="button" class="btn btn-default btn-sm cp-copy" data-copy="{{ $exact }}">Copy<span class="sr-only"> name {{ $exact }}</span></button>
                    </div>
                  @endisset
                </div>
              @empty
                <div class="card-body"><p class="mb-0 text-muted">Every assembled ship here is named after a fit for its hull.</p></div>
              @endforelse
            </div>
          </div>
        </div>
      </div>

      <div class="col-xl-5">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title" id="cp-fits-title">Fits at {{ $staging->name }}</h3>
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
              <table class="table table-sm table-hover mb-0 cp-num" aria-labelledby="cp-fits-title">
                <thead>
                  <tr>
                    <th scope="col">Fit</th>
                    <th scope="col" class="text-right">Ready / Required</th>
                    <th scope="col" class="text-right">To fix</th>
                    <th scope="col" class="text-right">Missing</th>
                    <th scope="col" class="text-right">Stock</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($report->fits as $row)
                    <tr class="cp-stock-row" title="Open the stock ledger for {{ $row['name'] }}">
                      <td>
                        {{ $row['name'] }}
                        @if($row['fit']->doctrine !== $row['name'])<span class="text-muted small">· {{ $row['fit']->doctrine }}</span>@endif
                      </td>
                      <td class="text-right text-nowrap">
                        @if($row['target'] > 0)
                          <span aria-hidden="true">
                            <span class="{{ $row['short'] > 0 ? 'text-danger font-weight-bold' : '' }}">{{ $row['match'] }}</span>
                            <span class="text-muted">/ {{ $row['target'] }}</span>
                            @if($row['short'] === 0)<i class="fas fa-check text-success ml-1"></i>@endif
                          </span>
                          <span class="sr-only">{{ $row['match'] }} of {{ $row['target'] }}, {{ $row['short'] > 0 ? 'below the required number' : 'required number met' }}</span>
                        @else
                          {{ $row['match'] }}
                        @endif
                      </td>
                      @foreach(['needFix', 'missing'] as $column)
                        <td class="text-right">
                          @if($row[$column] > 0)
                            {{ $row[$column] }}
                          @else
                            <span class="text-muted" aria-hidden="true">–</span><span class="sr-only">0</span>
                          @endif
                        </td>
                      @endforeach
                      @php($ledger = $stock[$row['fitId']] ?? null)
                      @php($outside = $ledger === null ? 0 : \Goemktg\Seat\SeatCustomPlugin\Support\StockLedger::outsideHangar($ledger, $row['named']))
                      <td class="text-right text-nowrap">
                        <button type="button" class="btn btn-link p-0 cp-stock-open {{ $ledger !== null && $ledger < 0 ? 'text-danger font-weight-bold' : '' }}"
                                data-url="{{ route('custom-plugin.stock.show', [$corporation->corporation_id, $staging->id, $row['fitId']]) }}"
                                data-fit="{{ $row['fitId'] }}" data-name="{{ $row['name'] }}"
                                data-hangar="{{ $row['named'] }}" data-required="{{ $row['target'] }}"
                                aria-label="Stock ledger for {{ $row['name'] }}: {{ $ledger === null ? 'no records' : $ledger . ' ships' }}">
                          {{ $ledger === null ? '–' : ($ledger < 0 ? '−' . abs($ledger) : $ledger) }}
                        </button>
                        @if($ledger !== null && $ledger < 0)
                          <span class="badge badge-danger ml-1" title="The ledger has more losses than ships. Check the records.">check</span>
                        @elseif($outside > 0)
                          <span class="d-block small text-muted">{{ $outside }} outside hangar</span>
                        @endif
                      </td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="card-footer small">
              Ready = ships named after the fit and fitted correctly, out of the number required.
              To fix = named right but fitted wrong (listed on the left).
              Missing = ships still to buy, or to rename after the fit.
              Stock = ships the ledger says the corporation owns here (purchases, minus losses, plus adjustments); click it to record or see the history.
            </div>
          @endif
        </div>
      </div>
    </div>

    <div class="modal fade" id="cp-stock-modal" tabindex="-1" role="dialog" aria-labelledby="cp-stock-title" aria-hidden="true"
         @if($stockPanel !== null) data-reopen="{{ $stockPanel['fit']->id }}" @endif>
      <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="cp-stock-title">
              <span class="cp-stock-name">{{ $stockPanel['fit']->fit_name ?? 'Stock' }}</span>
              <small class="text-muted">at {{ $staging->name }}</small>
            </h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <dl class="d-flex flex-wrap mb-3 cp-stock-totals cp-num">
              <div class="mr-4"><dt class="small text-muted text-uppercase">Stock (ledger)</dt><dd class="h4 mb-0 cp-stock-total">–</dd></div>
              <div class="mr-4"><dt class="small text-muted text-uppercase">In hangar</dt><dd class="h4 mb-0 cp-stock-hangar">–</dd></div>
              <div><dt class="small text-muted text-uppercase">Required</dt><dd class="h4 mb-0 cp-stock-required">–</dd></div>
            </dl>
            <div class="cp-stock-body" aria-live="polite">
              @if($stockPanel !== null)
                @include('custom-plugin::hangar._stock', $stockPanel)
              @endif
            </div>
          </div>
        </div>
      </div>
    </div>
  @endif
@stop

@push('head')
  <style>
    .cp-ship { padding: .75rem 1.25rem; border-top: 1px solid rgba(0,0,0,.125); }
    .tab-pane > .cp-ship:first-child, .tab-pane > p + .cp-ship, .cp-group + .cp-ship { border-top: 0; }
    .cp-group { margin: 0; padding: 1rem 1.25rem 0; font-size: 1rem; font-weight: 600; border-top: 1px solid rgba(0,0,0,.125); }
    .tab-pane > .cp-group:first-child { border-top: 0; }
    .cp-group small { font-size: .8125rem; font-weight: 400; margin-left: .25rem; }
    .cp-hint { flex-basis: 100%; margin-top: .25rem; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .cp-name { user-select: all; font-size: .8125rem; padding: .1rem .375rem; border-radius: .2rem; background: rgba(127,127,127,.15); }
    .cp-strip { display: grid; gap: .25rem; }
    .cp-strip-row { display: grid; grid-template-columns: 6.5rem 1fr; gap: .5rem; align-items: start; }
    .cp-strip-row dt { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; opacity: .7; padding-top: .2rem; margin: 0; }
    .cp-strip-row dd { margin: 0; display: flex; flex-wrap: wrap; gap: .25rem; }
    .cp-chip { font-weight: 500; font-size: .8125rem; white-space: normal; text-align: left; }
    .cp-strip-quiet dt { font-style: italic; }
    .cp-strip-quiet .cp-chip { font-weight: 400; border: 1px solid rgba(128, 128, 128, .35); }
    .cp-num td, .cp-num th { font-variant-numeric: tabular-nums; }
    .cp-stock-row { cursor: pointer; }
    @media (max-width: 575.98px) { .cp-strip-row { grid-template-columns: 1fr; gap: .125rem; } }
  </style>
@endpush

@push('javascript')
  <script>
    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var copy = target.closest('.cp-copy');
      if (copy) {
        var name = copy.getAttribute('data-copy');
        var label = copy.firstChild;
        var status = document.getElementById('cp-copy-status');
        var show = function (text, announce) {
          label.nodeValue = text;
          if (status) { status.textContent = announce; }
          setTimeout(function () { label.nodeValue = 'Copy'; }, 2000);
        };
        // older browsers or plain http: select the name and fall back to execCommand
        var fallback = function () {
          var range = document.createRange();
          range.selectNodeContents(copy.parentNode.querySelector('.cp-name'));
          var selection = window.getSelection();
          selection.removeAllRanges();
          selection.addRange(range);
          var ok = false;
          try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
          if (ok) {
            show('Copied', 'Copied ' + name);
          } else {
            show('Press Ctrl+C', name + ' is selected. Press Ctrl+C to copy it.');
          }
        };
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(name).then(function () { show('Copied', 'Copied ' + name); }, fallback);
        } else {
          fallback();
        }
        return;
      }
      if (target.closest('.cp-show-unmatched')) {
        event.preventDefault();
        $('#cp-unmatched-tab').tab('show').trigger('focus');
      }
    });
  </script>
@endpush

@push('javascript')
  <script>
    (function () {
      var modal = document.getElementById('cp-stock-modal');
      if (!modal) {
        return;
      }
      var body = modal.querySelector('.cp-stock-body');
      var opener = null;

      function fill(button) {
        modal.querySelector('.cp-stock-name').textContent = button.getAttribute('data-name');
        modal.querySelector('.cp-stock-hangar').textContent = button.getAttribute('data-hangar');
        var required = button.getAttribute('data-required');
        modal.querySelector('.cp-stock-required').textContent = required === '0' ? '–' : required;
      }

      function showTotal() {
        var panel = body.querySelector('.cp-stock-panel');
        var total = modal.querySelector('.cp-stock-total');
        if (!panel) {
          total.textContent = '–';
          total.classList.remove('text-danger');
          return;
        }
        var stock = parseInt(panel.getAttribute('data-stock'), 10);
        total.textContent = stock < 0 ? '−' + Math.abs(stock) : String(stock);
        total.classList.toggle('text-danger', stock < 0);
      }

      var loading = 0;

      function load(button) {
        var request = ++loading;
        fill(button);
        body.innerHTML = '<p class="text-muted mb-0">Loading the ledger…</p>';
        showTotal();
        // X-Requested-With keeps this fragment out of the session's "previous URL",
        // so a rejected record redirects back to Hangar Check, not here.
        fetch(button.getAttribute('data-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (response) {
            // an expired session redirects to the login page with a 200
            if (!response.ok || response.redirected) {
              throw new Error(String(response.status));
            }
            return response.text();
          })
          .then(function (html) {
            if (request !== loading) {
              return; // another fit was opened meanwhile
            }
            if (html.indexOf('cp-stock-panel') === -1) {
              throw new Error('unexpected response');
            }
            body.innerHTML = html;
            showTotal();
            if (modal.classList.contains('show')) {
              focusForm();
            }
          })
          .catch(function () {
            if (request === loading) {
              body.innerHTML = '<div class="alert alert-danger mb-0" role="alert">Could not load the ledger. Reload the page (you may need to sign in again) and try again.</div>';
            }
          });
      }

      document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var button = target ? target.closest('.cp-stock-open') : null;
        // anywhere on a fit row opens its ledger too, unless the click was on another control
        // or ended a text selection (officers copy fit names from this table)
        if (!button && target && !target.closest('a, button, input, select, label, summary')) {
          var row = target.closest('tr.cp-stock-row');
          var selection = window.getSelection ? String(window.getSelection()) : '';
          if (row && selection === '') {
            button = row.querySelector('.cp-stock-open');
          }
        }
        if (!button) {
          return;
        }
        opener = button;
        load(button);
        $(modal).modal('show');
      });

      // Bootstrap focuses the dialog itself once shown; move on to the form (or the field in error)
      function focusForm() {
        var field = body.querySelector('.is-invalid') || body.querySelector('#cp-stock-ships');
        if (field) {
          field.focus();
        }
      }
      $(modal).on('shown.bs.modal', focusForm);

      // one record per click: a double click must not record twice
      modal.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.classList.contains('cp-stock-form')) {
          return;
        }
        if (form.dataset.sent) {
          event.preventDefault();
          return;
        }
        form.dataset.sent = '1';
        var button = form.querySelector('button[type="submit"]');
        if (button) {
          button.disabled = true;
          button.textContent = 'Recording…';
        }
      });

      $(modal).on('hidden.bs.modal', function () {
        if (opener) {
          opener.focus();
        }
      });

      var reopen = modal.getAttribute('data-reopen');
      if (reopen) {
        opener = document.querySelector('.cp-stock-open[data-fit="' + reopen + '"]');
        if (opener) {
          fill(opener);
        }
        showTotal();
        $(modal).modal('show');
      }
    })();
  </script>
@endpush
@include('custom-plugin::partials.menu-active')

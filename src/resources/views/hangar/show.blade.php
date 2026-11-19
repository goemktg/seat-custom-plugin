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
    {{-- php blocks only from here on: Blade pairs a one-line php directive with the next end of a block --}}
    @php
      $report = $result['report'];
    @endphp
    @if(! $result['hasOffice'])
      <div class="alert alert-warning">
        SeAT has no corporation office at {{ $staging->name }} in the synced assets, so there is nothing to check.
        If the office is new, wait for the next asset update.
      </div>
    @endif

    @php
      $View = \Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport::class;
      $doctrines = $report->doctrineGroups();
      // one doctrine at a time once the list is long; a short list reads better whole
      $doctrineTabs = count($doctrines) > 1 && count($report->problems) > 10;
      $totals = $View::totals($report->problems);
      $acceptedTotal = array_sum(array_map(static fn (array $p): int => $p['match']->acceptedCount(), $report->problems));
      $fitRows = array_map(static fn (array $row): array => $row + ['ledger' => $stock[$row['fitId']] ?? null], $report->fits);
      $shownFits = array_values(array_filter($fitRows, static fn (array $row): bool => ! $View::emptyRow($row, $row['ledger'])));
      $emptyFits = array_values(array_filter($fitRows, static fn (array $row): bool => $View::emptyRow($row, $row['ledger'])));
    @endphp
    <div class="row cp-hangar">
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
              @if($report->problems !== [])
                <div class="cp-totals">
                  <div class="d-flex flex-wrap align-items-center">
                    <p class="mb-2 mr-auto">
                      <strong>{{ count($report->problems) }} {{ count($report->problems) === 1 ? 'ship' : 'ships' }} to fix</strong>
                      <span class="text-muted">· {{ count($totals['add']) }} {{ count($totals['add']) === 1 ? 'item' : 'items' }} to add · {{ count($totals['remove']) }} to remove</span>
                    </p>
                    @if($acceptedTotal > 0)
                      <button type="button" class="btn btn-sm btn-default mb-2" id="cp-accepted-toggle" aria-pressed="false">Show accepted differences</button>
                    @endif
                  </div>
                  @if($totals['add'] !== [] || $totals['remove'] !== [])
                    <details class="cp-shopping" @if(count($totals['add']) + count($totals['remove']) <= 8) open @endif>
                      <summary>All ships together</summary>
                      <dl class="cp-strip mt-2 mb-0">
                        @foreach([['To add', $totals['add'], 'badge-danger', '+', 'Add'], ['To remove', $totals['remove'], 'badge-warning', '−', 'Remove']] as [$label, $lines, $badge, $sign, $verb])
                          @if($lines !== [])
                            <div class="cp-strip-row">
                              <dt>{{ $label }}</dt>
                              <dd>
                                @foreach($lines as $typeId => $count)
                                  <span class="badge cp-chip {{ $badge }}"><span aria-hidden="true">{{ $sign }}</span><span class="sr-only">{{ $verb }}</span>
                                    {{ $result['names'][$typeId] ?? ('Type #' . $typeId) }}@if($count > 1) ×{{ number_format($count) }}@endif</span>
                                @endforeach
                              </dd>
                            </div>
                          @endif
                        @endforeach
                      </dl>
                    </details>
                  @endif
                </div>
              @endif
              @if($doctrineTabs)
                <ul class="nav nav-pills cp-doctrines" role="tablist" aria-label="Doctrines">
                  @foreach($doctrines as $i => $doctrine)
                    <li class="nav-item" role="presentation">
                      <a class="nav-link {{ $i === 0 ? 'active' : '' }}" id="cp-doc-{{ $i }}-tab" data-toggle="pill" href="#cp-doc-{{ $i }}" role="tab"
                         aria-controls="cp-doc-{{ $i }}" aria-selected="{{ $i === 0 ? 'true' : 'false' }}">
                        {{ $doctrine['doctrine'] }} <span class="badge badge-light">{{ $doctrine['ships'] }}</span>
                      </a>
                    </li>
                  @endforeach
                </ul>
                <div class="tab-content">
                  @foreach($doctrines as $i => $doctrine)
                    <div class="tab-pane {{ $i === 0 ? 'active' : '' }}" id="cp-doc-{{ $i }}" role="tabpanel" aria-labelledby="cp-doc-{{ $i }}-tab">
                      @include('custom-plugin::hangar._problems', ['groups' => $doctrine['fits'], 'key' => 'cp-doc-' . $i, 'label' => $doctrine['doctrine'], 'names' => $result['names'], 'showDoctrine' => false])
                    </div>
                  @endforeach
                </div>
              @elseif($report->problems !== [])
                @include('custom-plugin::hangar._problems', ['groups' => $report->problemGroups(), 'key' => 'cp-all', 'label' => 'ships to fix', 'names' => $result['names'], 'showDoctrine' => true])
              @else
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
              @endif
            </div>
            <div class="tab-pane" id="cp-unmatched" role="tabpanel" aria-labelledby="cp-unmatched-tab">
              <span class="sr-only" role="status" aria-live="polite" id="cp-copy-status"></span>
              @if($report->unmatched !== [])
                @php
                  // ships one rename away from a fit first, then the rest by hull
                  $unmatchedGroups = [];
                  foreach ($report->unmatched as $ship) {
                      $group = isset($report->suggestions[$ship->itemId]) ? '' : ($result['names'][$ship->typeId] ?? ('Type #' . $ship->typeId));
                      $unmatchedGroups[$group][] = $ship;
                  }
                  uksort($unmatchedGroups, static fn ($a, $b): int => ($a === '' ? 0 : 1) <=> ($b === '' ? 0 : 1) ?: strnatcasecmp((string) $a, (string) $b));
                  $openGroups = count($report->unmatched) <= 20;
                @endphp
                <div class="px-3 pt-3">
                  <p class="small text-muted mb-2">
                    Each ship is checked only against the fit with exactly its name (case included) and hull.
                    Rename it in game or add a fit with that name. Renames show after the next asset sync.
                  </p>
                  <label for="cp-unmatched-search" class="sr-only">Search by ship name or hull</label>
                  <input type="search" id="cp-unmatched-search" class="form-control form-control-sm mb-2" placeholder="Search by ship name or hull" autocomplete="off" hidden>
                  <p class="small text-muted mb-2" id="cp-unmatched-count" role="status" aria-live="polite"></p>
                </div>
                @foreach($unmatchedGroups as $hull => $ships)
                  <details class="cp-hull" @if($openGroups || $hull === '') open data-cp-open @endif>
                    <summary>
                      <span class="font-weight-bold">{{ $hull === '' ? 'Close to a fit name' : $hull }}</span>
                      <span class="text-muted ml-1">{{ count($ships) }}</span>
                    </summary>
                    @foreach($ships as $ship)
                      @php($hullName = $result['names'][$ship->typeId] ?? ('Type #' . $ship->typeId))
                      <div class="cp-ship cp-unmatched-ship d-flex flex-wrap align-items-baseline" data-search="{{ mb_strtolower($ship->name . ' ' . $hullName) }}">
                        @if($View::nameKey($ship->name) !== '')
                          <strong class="mr-2">{{ $ship->name }}</strong>
                        @else
                          <em class="text-muted mr-2">Unnamed</em>
                        @endif
                        @if($hull === '')
                          <span class="text-muted mr-2">{{ $hullName }}</span>
                        @endif
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
                    @endforeach
                  </details>
                @endforeach
              @else
                <div class="card-body"><p class="mb-0 text-muted">Every assembled ship here is named after a fit for its hull.</p></div>
              @endif
            </div>
          </div>
        </div>
      </div>

      {{-- first on a narrow window (the EVE client beside it): the counts, then the list --}}
      <div class="col-xl-5 order-first order-xl-0">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title" id="cp-fits-title">Fits at {{ $staging->name }}</h3>
            @if($emptyFits !== [])
              <span class="card-tools small text-muted">{{ count($shownFits) }} of {{ count($fitRows) }} shown</span>
            @endif
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
                    <th scope="col" class="text-right"><span class="cp-tip" title="Ships named after the fit and fitted correctly, out of the number needed here">Ready / Need</span></th>
                    <th scope="col" class="text-right"><span class="cp-tip" title="Named after the fit but fitted wrong; listed under Needs fixing">Wrong fit</span></th>
                    <th scope="col" class="text-right"><span class="cp-tip" title="Ships still to buy, or to rename after the fit, to reach the number needed">Short</span></th>
                    <th scope="col" class="text-right"><span class="cp-tip" title="Ships the stock ledger says the corporation owns here; click to record or see the history">Ledger</span></th>
                  </tr>
                </thead>
                @foreach([[$shownFits, false], [$emptyFits, true]] as [$rows, $empty])
                @continue($rows === [])
                <tbody @if($empty) id="cp-empty-fits" hidden @endif>
                  @foreach($rows as $row)
                    <tr class="cp-stock-row" title="Open the stock ledger for {{ $row['name'] }}">
                      <td>
                        {{ $row['name'] }}
                        @if($row['fit']->doctrine !== $row['name'])<span class="text-muted small">· {{ $row['fit']->doctrine }}</span>@endif
                      </td>
                      <td class="text-right text-nowrap">
                        <span aria-hidden="true">
                          <span class="{{ $row['target'] > 0 && $row['short'] > 0 ? 'text-danger font-weight-bold' : '' }}">{{ $row['match'] }}</span>
                          <span class="text-muted">/ {{ $row['target'] > 0 ? $row['target'] : '–' }}</span>
                          @if($row['target'] > 0 && $row['short'] === 0)<i class="fas fa-check text-success ml-1"></i>@endif
                        </span>
                        <span class="sr-only">
                          {{ $row['match'] }} ready{{ $row['target'] > 0 ? ' of ' . $row['target'] . ', ' . ($row['short'] > 0 ? 'below the number needed' : 'number needed met') : ', no number needed set' }}
                        </span>
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
                      @php($ledger = $row['ledger'])
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
                          <span class="badge badge-danger ml-1" title="The ledger has more losses than ships. Check the records.">below 0</span>
                        @elseif($outside > 0)
                          <span class="d-block"><span class="badge badge-warning cp-outside" title="The ledger has more ships than the hangar: they are undocked, elsewhere or lost without a record">{{ $outside }} outside hangar</span></span>
                        @endif
                      </td>
                    </tr>
                  @endforeach
                </tbody>
                @endforeach
              </table>
            </div>
            <div class="card-footer small">
              @if($emptyFits !== [])
                <button type="button" class="btn btn-link btn-sm p-0 mb-2 cp-empty-toggle" aria-controls="cp-empty-fits" aria-expanded="false"
                        data-show="Show {{ count($emptyFits) }} empty {{ count($emptyFits) === 1 ? 'fit' : 'fits' }}" data-hide="Hide empty fits">
                  Show {{ count($emptyFits) }} empty {{ count($emptyFits) === 1 ? 'fit' : 'fits' }}
                </button>
                <span class="text-muted d-block mb-2">Empty: nothing needed, no ship named after it here, no ledger records.</span>
              @endif
              <details>
                <summary>What the columns mean</summary>
                <dl class="mb-0 mt-1 cp-legend">
                  <dt>Ready / Need</dt><dd>ships named after the fit and fitted correctly, out of the number needed here.</dd>
                  <dt>Wrong fit</dt><dd>named after the fit but fitted wrong (listed under Needs fixing).</dd>
                  <dt>Short</dt><dd>ships still to buy, or to rename after the fit.</dd>
                  <dt>Ledger</dt><dd>ships the stock ledger says the corporation owns here (purchases, minus losses, plus adjustments). Click it to record or see the history. <em>Outside hangar</em>: the ledger counts more ships than are in the hangar.</dd>
                </dl>
              </details>
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
            <h2 class="modal-title h5" id="cp-stock-title">
              <span class="cp-stock-name">{{ $stockPanel['fit']->fit_name ?? 'Stock' }}</span>
              <small class="text-muted">at {{ $staging->name }}</small>
            </h2>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          </div>
          <div class="modal-body">
            <dl class="d-flex flex-wrap mb-3 cp-stock-totals cp-num">
              <div class="mr-4"><dt class="small text-muted text-uppercase">Ledger</dt><dd class="h4 mb-0 cp-stock-total">–</dd></div>
              <div class="mr-4"><dt class="small text-muted text-uppercase">In hangar</dt><dd class="h4 mb-0 cp-stock-hangar">–</dd></div>
              <div><dt class="small text-muted text-uppercase">Need</dt><dd class="h4 mb-0 cp-stock-required">–</dd></div>
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
    /* AdminLTE badges and .small run at 10.5-11.2px; keep this page's at 13px */
    .cp-hangar .badge, .cp-hangar .small, .cp-hangar small { font-size: .8125rem; }
    .cp-hangar .cp-group small { font-size: .8125rem; }
    .cp-hangar [hidden] { display: none !important; } /* .d-flex would otherwise win over hidden */
    .cp-page > .cp-group:first-child, .cp-doctrines + .tab-content .cp-page > .cp-group:first-child { border-top: 0; }
    .cp-outside { font-weight: 600; margin-top: .125rem; }
    .cp-tip { border-bottom: 1px dotted currentColor; cursor: help; }
    .cp-totals { padding: 1rem 1.25rem .5rem; border-bottom: 1px solid rgba(0,0,0,.125); }
    .cp-hangar details > summary { cursor: pointer; }
    .cp-shopping > summary, .cp-accepted > summary { font-size: .8125rem; opacity: .8; }
    .cp-accepted { margin-top: .375rem; }
    .cp-doctrines { padding: .75rem 1.25rem 0; gap: .25rem; }
    .cp-pager { padding: .75rem 1.25rem; border-top: 1px solid rgba(0,0,0,.125); }
    .cp-page:focus { outline: none; }
    .cp-hull { border-top: 1px solid rgba(0,0,0,.125); }
    .cp-hull > summary { padding: .625rem 1.25rem; }
    .cp-hull > .cp-ship { padding-left: 2rem; }
    .cp-legend dt { display: inline; font-weight: 600; }
    .cp-legend dd { display: inline; margin: 0; }
    .cp-legend dd::after { content: ""; display: block; }
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
        return;
      }
      var pageLink = target.closest('[data-cp-page]');
      if (pageLink) {
        event.preventDefault();
        var paged = pageLink.closest('.cp-paged');
        var page = document.getElementById(pageLink.getAttribute('href').slice(1));
        paged.querySelectorAll('.cp-page').forEach(function (p) { p.hidden = p !== page; });
        paged.querySelectorAll('[data-cp-page]').forEach(function (a) {
          var current = a === pageLink;
          a.parentNode.classList.toggle('active', current);
          if (current) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
        });
        page.focus();
        page.scrollIntoView({ block: 'start' });
        return;
      }
      var emptyToggle = target.closest('.cp-empty-toggle');
      if (emptyToggle) {
        var rows = document.getElementById('cp-empty-fits');
        rows.hidden = !rows.hidden;
        emptyToggle.setAttribute('aria-expanded', String(!rows.hidden));
        emptyToggle.textContent = emptyToggle.getAttribute(rows.hidden ? 'data-show' : 'data-hide');
        return;
      }
      if (target.closest('#cp-accepted-toggle')) {
        var toggle = document.getElementById('cp-accepted-toggle');
        showAccepted(toggle.getAttribute('aria-pressed') !== 'true');
      }
    });

    // pages after the first are hidden only once script runs, so nothing is lost without it
    document.querySelectorAll('.cp-page[data-cp-hidden]').forEach(function (p) { p.hidden = true; });

    // accepted differences: folded by default; the officer's choice is remembered in this browser
    var acceptedKey = 'custom-plugin.hangar.accepted';
    function showAccepted(open, keep) {
      var toggle = document.getElementById('cp-accepted-toggle');
      if (!toggle) { return; }
      document.querySelectorAll('details.cp-accepted').forEach(function (d) { d.open = open; });
      toggle.setAttribute('aria-pressed', String(open));
      toggle.textContent = open ? 'Hide accepted differences' : 'Show accepted differences';
      if (keep !== false) {
        try { window.localStorage.setItem(acceptedKey, open ? '1' : '0'); } catch (e) { /* storage blocked */ }
      }
    }
    try {
      if (window.localStorage.getItem(acceptedKey) === '1') { showAccepted(true, false); }
    } catch (e) { /* storage blocked: stay folded */ }

    // ships not named after a fit: filter by name or hull
    (function () {
      var search = document.getElementById('cp-unmatched-search');
      if (!search) { return; }
      var count = document.getElementById('cp-unmatched-count');
      var groups = document.querySelectorAll('details.cp-hull');
      var timer = null;
      search.hidden = false;
      search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
          var q = search.value.trim().toLowerCase();
          var total = 0;
          groups.forEach(function (group) {
            var shown = 0;
            group.querySelectorAll('.cp-unmatched-ship').forEach(function (ship) {
              var hit = q === '' || ship.getAttribute('data-search').indexOf(q) !== -1;
              ship.hidden = !hit;
              if (hit) { shown++; }
            });
            total += shown;
            group.hidden = shown === 0;
            group.open = q === '' ? group.hasAttribute('data-cp-open') : shown > 0;
          });
          count.textContent = q === '' ? '' : total + (total === 1 ? ' ship matches' : ' ships match');
        }, 150);
      });
    })();
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

{{-- Stock ledger dialog body for one fit at one staging. See StockController::panel(). --}}
@php($bag = $errors->getBag(\Goemktg\Seat\SeatCustomPlugin\Http\Controllers\StockController::ERROR_BAG))
@php($kind = old('kind', 'purchase'))
<div class="cp-stock-panel" data-stock="{{ $stock }}">
  @if(session('stock_status'))
    <div class="alert alert-success py-2 mb-3" role="status">{{ session('stock_status') }}</div>
  @endif
  @if($bag->any())
    <div class="alert alert-danger py-2 mb-3" role="alert">
      @foreach($bag->all() as $message)<div>{{ $message }}</div>@endforeach
    </div>
  @endif

  <form method="post" action="{{ route('custom-plugin.stock.store', [$corporation->corporation_id, $staging->id, $fit->id]) }}" class="cp-stock-form mb-3">
    @csrf
    <input type="hidden" name="stock_fit" value="{{ $fit->id }}">
    <div class="form-row align-items-end">
      <div class="col-12 col-md-auto mb-2">
        <span class="d-block small font-weight-bold mb-1" id="cp-stock-kind-label">Kind</span>
        <div class="btn-group btn-group-sm btn-group-toggle" data-toggle="buttons" role="radiogroup" aria-labelledby="cp-stock-kind-label">
          @foreach(\Goemktg\Seat\SeatCustomPlugin\Support\StockLedger::KINDS as $value => $label)
            <label class="btn btn-outline-primary {{ $kind === $value ? 'active' : '' }}">
              <input type="radio" name="kind" value="{{ $value }}" autocomplete="off" @checked($kind === $value)> {{ $label }}
            </label>
          @endforeach
        </div>
      </div>
      <div class="col-4 col-md-2 mb-2">
        <label for="cp-stock-ships" class="small font-weight-bold mb-1">Ships</label>
        <input type="number" id="cp-stock-ships" name="ships" class="form-control form-control-sm {{ $bag->has('ships') ? 'is-invalid' : '' }}"
               value="{{ old('ships', 1) }}" step="1" required
               min="-{{ \Goemktg\Seat\SeatCustomPlugin\Support\StockLedger::MAX_SHIPS }}" max="{{ \Goemktg\Seat\SeatCustomPlugin\Support\StockLedger::MAX_SHIPS }}">
      </div>
      <div class="col-8 col-md-3 mb-2">
        <label for="cp-stock-date" class="small font-weight-bold mb-1">Date (UTC)</label>
        <input type="date" id="cp-stock-date" name="occurred_on" class="form-control form-control-sm {{ $bag->has('occurred_on') ? 'is-invalid' : '' }}"
               value="{{ old('occurred_on', $today) }}" max="{{ $today }}" required>
      </div>
      <div class="col-12 col-md mb-2">
        <label for="cp-stock-note" class="small font-weight-bold mb-1">Note <span class="font-weight-normal text-muted">(optional)</span></label>
        <input type="text" id="cp-stock-note" name="note" class="form-control form-control-sm {{ $bag->has('note') ? 'is-invalid' : '' }}"
               value="{{ old('note') }}" maxlength="255" placeholder="e.g. Jita contract, lost in M-OEE8">
      </div>
      <div class="col-12 col-md-auto mb-2">
        <button type="submit" class="btn btn-sm btn-primary btn-block">Record</button>
      </div>
    </div>
    <p class="small text-muted mb-0">Purchase and Loss take a positive number. For Adjust, a negative number removes ships.</p>
  </form>

  @if($entries->isEmpty())
    <p class="text-muted mb-0">
      No records yet. To start, record the ships you have now as an Adjust.
    </p>
  @else
    <div class="table-responsive">
      <table class="table table-sm mb-0 cp-num">
        <caption class="sr-only">Stock records for {{ $fit->fit_name }} at {{ $staging->name }}, newest first</caption>
        <thead>
          <tr>
            <th scope="col">Date</th>
            <th scope="col">Kind</th>
            <th scope="col" class="text-right">Ships</th>
            <th scope="col">Note</th>
            <th scope="col">By</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          @foreach($entries as $entry)
            <tr>
              <td class="text-nowrap">{{ $entry->occurred_on->toDateString() }}</td>
              <td>{{ \Goemktg\Seat\SeatCustomPlugin\Support\StockLedger::KINDS[$entry->kind] ?? $entry->kind }}</td>
              <td class="text-right font-weight-bold {{ $entry->quantity > 0 ? 'text-success' : 'text-danger' }}">
                {{ $entry->quantity > 0 ? '+' : '−' }}{{ abs($entry->quantity) }}
              </td>
              <td class="cp-stock-note">{{ $entry->note }}</td>
              <td class="text-nowrap">{{ $entry->created_by_name ?? 'Unknown' }}</td>
              <td class="text-right">
                <form method="post" action="{{ route('custom-plugin.stock.destroy', [$corporation->corporation_id, $entry->id]) }}"
                      data-confirm="Delete this record ({{ \Goemktg\Seat\SeatCustomPlugin\Support\StockLedger::KINDS[$entry->kind] ?? $entry->kind }} {{ $entry->quantity > 0 ? '+' : '−' }}{{ abs($entry->quantity) }}, {{ $entry->occurred_on->toDateString() }})?"
                      onsubmit="return confirm(this.dataset.confirm)">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-link text-danger p-0">Delete</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if($count > $entries->count())
      <p class="small text-muted mt-2 mb-0">Showing the latest {{ $entries->count() }} of {{ $count }} records. All of them count towards stock.</p>
    @endif
  @endif
</div>

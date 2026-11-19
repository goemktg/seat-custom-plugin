{{-- Slot strip: one row per slot section or hold, signed by what to do: red "+" to add (missing), amber "−" to remove (extra) --}}
@php
  $labels = [
    'low' => 'Low', 'mid' => 'Mid', 'high' => 'High', 'rig' => 'Rig',
    'cargo' => 'Cargo', 'container' => 'Container',
    'FuelBay' => 'Fuel bay', 'FleetHangar' => 'Fleet hangar',
    'FighterBay' => 'Fighter bay', 'ShipMaintenanceBay' => 'Ship maint. bay',
  ];
  $rows = [];
  foreach ([['miss', $match->missing], ['extra', $match->extra], ['miss', $match->cargoMissing], ['miss', $match->containerMissing], ['miss', $match->bayMissing]] as [$kind, $lines]) {
    foreach ($lines as $line) {
      $rows[$line['section']][] = ['kind' => $kind] + $line;
    }
  }
  $rows = array_replace(array_intersect_key(array_flip(array_keys($labels)), $rows), $rows);
@endphp
<dl class="cp-strip mb-0">
  @foreach($rows as $section => $lines)
    <div class="cp-strip-row">
      <dt>{{ $labels[$section] ?? $section }}</dt>
      <dd>
        @foreach($lines as $line)
          <span class="badge cp-chip {{ $line['kind'] === 'miss' ? 'badge-danger' : 'badge-warning' }}">
            <span aria-hidden="true">{{ $line['kind'] === 'miss' ? '+' : '−' }}</span>
            <span class="sr-only">{{ $line['kind'] === 'miss' ? 'Add' : 'Remove' }}</span>
            {{ $names[$line['typeId']] ?? ('Type #' . $line['typeId']) }}@if($line['count'] > 1) ×{{ number_format($line['count']) }}@endif
          </span>
        @endforeach
      </dd>
    </div>
  @endforeach
  @if($match->fit->unresolved !== [])
    <div class="cp-strip-row">
      <dt>Fit</dt>
      <dd class="text-warning">Unknown lines in the fit: {{ implode(', ', $match->fit->unresolved) }}</dd>
    </div>
  @endif
</dl>
{{-- Already accepted, nothing to do: folded unless the officer asks to see them --}}
@if($match->acceptedCount() > 0)
  <details class="cp-accepted">
    <summary>{{ $match->acceptedCount() }} accepted {{ $match->acceptedCount() === 1 ? 'difference' : 'differences' }}</summary>
    <dl class="cp-strip mb-0 mt-1">
      @foreach([['Refit', $match->refits, 'Fitted, counts as the cargo refit:'], ['In cargo', $match->stowed, 'In cargo, counts as fitted:']] as [$label, $lines, $srLabel])
        @if($lines !== [])
          <div class="cp-strip-row cp-strip-quiet">
            <dt title="{{ rtrim($srLabel, ':') }}">{{ $label }}</dt>
            <dd>
              <span class="sr-only">{{ $srLabel }}</span>
              @foreach($lines as $line)
                <span class="badge cp-chip badge-light">{{ $names[$line['typeId']] ?? ('Type #' . $line['typeId']) }}@if($line['count'] > 1) ×{{ number_format($line['count']) }}@endif</span>
              @endforeach
            </dd>
          </div>
        @endif
      @endforeach
    </dl>
  </details>
@endif

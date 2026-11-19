{{-- Ships to fix for a list of fit groups, cut into pages of ships. Without script every page shows. --}}
@php
  $View = \Goemktg\Seat\SeatCustomPlugin\Conformance\HangarReport::class;
  $perPage = 10;
  $pages = $View::pages($groups, $perPage);
  $shipCount = array_sum(array_map(static fn (array $group): int => count($group['problems']), $groups));
@endphp
<div class="cp-paged">
  @foreach($pages as $i => $page)
    <div class="cp-page" id="{{ $key }}-p{{ $i + 1 }}" tabindex="-1" @if($i > 0) data-cp-hidden @endif
         @if(count($pages) > 1) aria-label="Ships {{ $i * $perPage + 1 }} to {{ min($shipCount, ($i + 1) * $perPage) }} of {{ $shipCount }}" role="group" @endif>
      @foreach($page as $group)
        @php($fit = $group['fit'])
        @php($parts = $View::headingParts($names[$fit->shipTypeId] ?? null, $showDoctrine ? $fit->doctrine : '', $fit->name))
        <h3 class="cp-group">
          {{ $fit->name }}
          <small class="text-muted">
            @foreach($parts as $part){{ $part }} · @endforeach{{ $group['needFix'] }} of {{ $group['named'] }} need fixing
            @if($group['continued'])<span class="font-italic">(continued)</span>@endif
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
                <span class="badge badge-danger mr-1">{{ $match->itemsToAdd() }} {{ $match->itemsToAdd() === 1 ? 'item' : 'items' }} to add</span>
              @endif
              @if($match->itemsToRemove() > 0)
                <span class="badge badge-warning mr-1">{{ $match->itemsToRemove() }} {{ $match->itemsToRemove() === 1 ? 'item' : 'items' }} to remove</span>
              @endif
            </div>
            @include('custom-plugin::hangar._diff', ['match' => $match, 'names' => $names])
          </div>
        @endforeach
      @endforeach
    </div>
  @endforeach
  @if(count($pages) > 1)
    <nav class="cp-pager" aria-label="Pages of {{ $label }}">
      <ul class="pagination pagination-sm flex-wrap mb-0">
        @foreach($pages as $i => $page)
          <li class="page-item {{ $i === 0 ? 'active' : '' }}">
            <a class="page-link" href="#{{ $key }}-p{{ $i + 1 }}" data-cp-page @if($i === 0) aria-current="page" @endif>
              <span class="sr-only">Page </span>{{ $i + 1 }}
            </a>
          </li>
        @endforeach
      </ul>
    </nav>
  @endif
</div>

{{-- One sync history row as a sentence: "Archon Armor (Archon) updated". --}}
@php($verb = [
    'added' => 'added', 'updated' => 'updated', 'linked' => 'linked to the site',
    'returned' => 'back on the site', 'taken_over' => 'taken over', 'moved' => 'counts and stock moved', 'missing' => 'gone from the site', 'held' => 'held', 'failed' => 'failed',
][$event->kind] ?? $event->kind)
<span @class(['text-danger' => in_array($event->kind, ['held', 'failed'], true)])>
  {{ $event->fit_label ?? $event->doctrine_label }} {{ $verb }}</span>@if($event->detail !== null)<span class="text-muted">: {{ $event->detail }}</span>@endif

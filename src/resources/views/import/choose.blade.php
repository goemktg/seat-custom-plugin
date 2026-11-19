{{-- Step 1 of the import window, loaded into #cp-import-body. --}}
<form method="get" action="{{ route('custom-plugin.fits.import.review', $corporation->corporation_id) }}" data-cp-import-step="choose">
  <div class="modal-body">
    <p class="small text-muted mb-2">Step 1 of 2 · Choose the doctrines this corporation follows. Followed doctrines update every hour.</p>
    @if($doctrines === [])
      <p class="mb-0">The doctrine site has no doctrines yet.</p>
    @else
      <fieldset>
        <legend class="sr-only">Doctrines on the doctrine site</legend>
        <ul class="list-unstyled mb-0">
          @foreach($doctrines as $d)
            @php($isFollowed = isset($following[$d['slug']]))
            <li class="border-bottom py-2">
              <div class="custom-control custom-checkbox">
                <input type="checkbox" class="custom-control-input" id="cp-import-{{ $d['slug'] }}" name="slugs[]" value="{{ $d['slug'] }}"
                       @checked($isFollowed || in_array($d['slug'], $checked, true))>
                <label class="custom-control-label" for="cp-import-{{ $d['slug'] }}">
                  {{ $d['name'] }}
                  @if($d['status'] !== 'active')<span class="badge badge-light border ml-1">{{ $d['status'] }}</span>@endif
                  <span class="small text-muted ml-1">v{{ $d['version'] }}@if($isFollowed) · following @endif</span>
                </label>
              </div>
            </li>
          @endforeach
        </ul>
      </fieldset>
      <p class="small text-muted mt-2 mb-0">Up to {{ $max }} at a time. Unticking a followed doctrine here does not stop following it.</p>
    @endif
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
    @if($doctrines !== [])
      <button type="submit" class="btn btn-primary">Review changes</button>
    @endif
  </div>
</form>

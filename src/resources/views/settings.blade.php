@extends('web::layouts.grids.12')

@section('title', trans('custom-plugin::global.browser_title'))
@section('page_header', trans('custom-plugin::global.settings_title'))
@section('page_description', trans('custom-plugin::global.settings_subtitle'))

@section('full')

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Custom Plugin</h3>
        </div>
        <div class="card-body">
            <form method="post" id="seat-custom-plugin-setup">
                {{ csrf_field() }}
                <div class="form-group">
                    <label for="role-id-squad-id-map">{{ trans('custom-plugin::settings.role_id_squad_id_map') }}</label>
                    <span class="help-block">{{ trans('custom-plugin::settings.role_id_squad_id_map_desc') }}</span>
                    <textarea id="role-id-squad-id-map" name="role-id-squad-id-map" class="form-control" rows="6" placeholder='{"squad_id": "role_id"}'>{{ setting('custom-plugin.role_id_squad_id_map', true) }}</textarea>
                </div>
                <div class="form-group">
                    <label for="inactive-role-id">{{ trans('custom-plugin::settings.inactive_role_id') }}</label>
                    <span class="help-block">{{ trans('custom-plugin::settings.inactive_role_id_desc') }}</span>
                    <input type="number" id="inactive-role-id" name="inactive-role-id" class="form-control"
                        value="{{ setting('custom-plugin.inactive_role_id', true) }}" />
                </div>
                <div class="form-group">
                    <label for="ignore-role-ids">Ignore Role Ids</label>
                    <span class="help-block">Array of role IDs to ignore during add/remove. Format: [1, 2, 3]</span>
                    <input type="text" id="ignore-role-ids" name="ignore-role-ids" class="form-control" placeholder='[1, 2, 3]'
                        value="{{ setting('custom-plugin.ignore_role_ids', '[]') }}" />
                </div>
            </form>
        </div>
        <div class="card-footer clearfix">
            <button type="submit" class="btn btn-success float-right"
                form="seat-custom-plugin-setup">{{ trans('web::seat.confirm_setup') }}</button>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Doctrine site sync</h3>
        </div>
        <div class="card-body">
            @if(! $doctrineSync['configured'])
                <p class="mb-1"><strong>Not set up on this server.</strong></p>
                <p class="text-muted small mb-3">Set <code class="text-body">CUSTOM_PLUGIN_DOCTRINE_URL</code> and <code class="text-body">CUSTOM_PLUGIN_DOCTRINE_TOKEN</code> in the SeAT environment, then restart SeAT. Until then no corporation can import.</p>
            @elseif($doctrineSync['site']['error'] !== null)
                <p class="small text-danger mb-3"><i class="fas fa-circle fa-xs mr-1" aria-hidden="true"></i>{{ $doctrineSync['site']['error'] }}</p>
            @else
                <p class="small mb-3"><i class="fas fa-circle fa-xs text-success mr-1" aria-hidden="true"></i>Connected · {{ $doctrineSync['site']['count'] }} {{ \Illuminate\Support\Str::plural('doctrine', $doctrineSync['site']['count']) }} on the site</p>
            @endif

            <form method="post" id="cp-doctrine-sync" action="{{ route('custom-plugin.settings.doctrine-sync') }}">
                @csrf
                <div class="form-group mb-0">
                    <label for="cp-doctrine-sync-corporations">Corporations that can import</label>
                    <select id="cp-doctrine-sync-corporations" name="doctrine-sync-corporations[]" multiple
                            class="form-control @error('doctrine-sync-corporations') is-invalid @enderror"
                            aria-describedby="cp-doctrine-sync-help" data-lookup="{{ route('seatcore::fastlookup.corporations') }}">
                        @foreach($doctrineSync['corporations'] as $id => $name)
                            <option value="{{ $id }}" selected>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('doctrine-sync-corporations')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    <small id="cp-doctrine-sync-help" class="form-text text-muted">
                        Type to search the corporations SeAT knows. Only these see Import from doctrine site and get the hourly sync.
                        Removing one stops its syncing; fits it imported stay.
                    </small>
                </div>
            </form>
        </div>
        <div class="card-footer clearfix">
            <button type="submit" class="btn btn-success float-right" form="cp-doctrine-sync">Save corporations</button>
        </div>
    </div>

@stop

@push('javascript')
    <script>
        (function () {
            var select = $('#cp-doctrine-sync-corporations');
            select.select2({
                width: '100%',
                placeholder: 'Search corporations',
                minimumInputLength: 1,
                ajax: {
                    url: select.data('lookup'),
                    dataType: 'json',
                    delay: 250,
                    cache: true
                }
            });
        })();
    </script>
@endpush

@include('custom-plugin::partials.menu-active')

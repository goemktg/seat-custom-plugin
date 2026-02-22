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
                    <input type="text" id="role-id-squad-id-map" name="role-id-squad-id-map" class="form-control"
                        value="{{ setting('custom-plugin.role_id_squad_id_map', true) }}" />
                </div>
                <div class="form-group">
                    <label for="inactive-role-id">{{ trans('custom-plugin::settings.inactive_role_id') }}</label>
                    <span class="help-block">{{ trans('custom-plugin::settings.inactive_role_id_desc') }}</span>
                    <input type="number" id="inactive-role-id" name="inactive-role-id" class="form-control"
                        value="{{ setting('custom-plugin.inactive_role_id', true) }}" />
                </div>
            </form>
        </div>
        <div class="card-footer clearfix">
            <button type="submit" class="btn btn-success float-right"
                form="seat-custom-plugin-setup">{{ trans('web::seat.confirm_setup') }}</button>
        </div>
    </div>

@stop

@push('javascript')
    <script>
        console.log('Include any JavaScript you may need here!');

    </script>
@endpush

@extends('web::layouts.grids.12')

@section('title', 'Sign-in request refused')
@section('page_header', 'Sign-in request refused')

@section('full')
  <div class="card">
    <div class="card-body">
      <p class="mb-1">{{ $message }}</p>
      <p class="text-muted mb-0">You were not sent back to the application. Open it again and start the sign-in from there.</p>
    </div>
  </div>
@stop

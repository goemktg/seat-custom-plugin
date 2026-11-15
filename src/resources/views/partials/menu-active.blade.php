{{-- Mark this page's sidebar entry active. SeAT only does so on an exact URL match,
     which per-corporation pages never have. See Support\MenuEntry. --}}
@php($cpMenuRoute = \Goemktg\Seat\SeatCustomPlugin\Support\MenuEntry::for(\Illuminate\Support\Facades\Route::currentRouteName()))
@if($cpMenuRoute !== null)
  @push('javascript')
    <script>
      (function () {
        var href = @json(route($cpMenuRoute));
        document.querySelectorAll('.main-sidebar a.nav-link').forEach(function (link) {
          if (link.getAttribute('href') === href) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
          }
        });
      })();
    </script>
  @endpush
@endif

{{-- Auto-logout idle 30 menit khusus role 'user'; admin & super_admin sengaja tidak dibatasi waktu --}}
@auth
@if (auth()->user()->role === 'user')
    <script>
        (function () {
            var IDLE_LIMIT_MS = 30 * 60 * 1000; // 30 menit tanpa aktivitas
            var idleTimer;

            function doAutoLogout() {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '{{ route('logout') }}';

                var csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = '_token';
                csrf.value = '{{ csrf_token() }}';
                form.appendChild(csrf);

                document.body.appendChild(form);
                form.submit();
            }

            function resetIdleTimer() {
                clearTimeout(idleTimer);
                idleTimer = setTimeout(doAutoLogout, IDLE_LIMIT_MS);
            }

            ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
                document.addEventListener(evt, resetIdleTimer, { passive: true });
            });

            resetIdleTimer();
        })();
    </script>
@endif
@endauth

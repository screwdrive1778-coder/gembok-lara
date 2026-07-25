<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', companyName()) - ISP Management System</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --sheza-navy: #020b18;
            --sheza-ink: #061426;
            --sheza-panel: #071a2d;
            --sheza-green: #1bb80f;
            --sheza-lime: #7cff00;
            --sheza-teal: #12d4a3;
            --sheza-glow: rgba(124, 255, 0, .34);
        }

        body {
            background:
                radial-gradient(circle at 90% 8%, rgba(124, 255, 0, .14), transparent 28rem),
                radial-gradient(circle at 15% 92%, rgba(18, 212, 163, .12), transparent 26rem),
                #f6faf7;
        }

        .sheza-shell {
            background:
                linear-gradient(135deg, rgba(2, 11, 24, .96), rgba(6, 20, 38, .94)),
                radial-gradient(circle at 20% 20%, rgba(124, 255, 0, .22), transparent 18rem);
        }

        .sheza-network-lines {
            background-image:
                radial-gradient(circle at 20% 35%, rgba(124, 255, 0, .32) 0 2px, transparent 3px),
                radial-gradient(circle at 75% 20%, rgba(18, 212, 163, .24) 0 2px, transparent 3px),
                linear-gradient(115deg, transparent 0 42%, rgba(124, 255, 0, .13) 42% 43%, transparent 43% 100%),
                linear-gradient(155deg, transparent 0 52%, rgba(18, 212, 163, .10) 52% 53%, transparent 53% 100%);
        }

        .sheza-card {
            border: 1px solid rgba(27, 184, 15, .14);
            box-shadow: 0 22px 55px rgba(2, 11, 24, .10);
        }

        .sheza-gradient {
            background: linear-gradient(135deg, var(--sheza-green), var(--sheza-lime));
        }

        .sheza-text-gradient {
            background: linear-gradient(135deg, #ffffff, var(--sheza-lime));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
    </style>
    
    @stack('styles')
</head>
<body class="bg-[#f6faf7] text-slate-900">
    @yield('content')
    
    <!-- SweetAlert2 Flash Messages -->
    @if(session('success'))
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: '{{ session('success') }}',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                toast: true,
                position: 'top-end'
            });
        });
    </script>
    @endif

    @if(session('error'))
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '{{ session('error') }}',
                showConfirmButton: true,
                confirmButtonColor: '#1bb80f'
            });
        });
    </script>
    @endif

    @if(session('warning'))
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'warning',
                title: 'Perhatian!',
                text: '{{ session('warning') }}',
                showConfirmButton: true,
                confirmButtonColor: '#1bb80f'
            });
        });
    </script>
    @endif

    @if(session('info'))
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'info',
                title: 'Informasi',
                text: '{{ session('info') }}',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                toast: true,
                position: 'top-end'
            });
        });
    </script>
    @endif

    <!-- Global Delete Confirmation Script -->
    <script>
        function confirmDelete(formId, itemName = 'item ini') {
            Swal.fire({
                title: 'Hapus Data?',
                text: 'Apakah Anda yakin ingin menghapus ' + itemName + '?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya, Hapus!',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(formId).submit();
                }
            });
        }

        function confirmAction(message, callback) {
            Swal.fire({
                title: 'Konfirmasi',
                text: message,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0891b2',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya, Lanjutkan',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed && typeof callback === 'function') {
                    callback();
                }
            });
        }

        function showLoading(message = 'Memproses...') {
            Swal.fire({
                title: message,
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        }

        function showSuccess(message, redirect = null) {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: message,
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true
            }).then(() => {
                if (redirect) {
                    window.location.href = redirect;
                }
            });
        }

        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: message,
                confirmButtonColor: '#0891b2'
            });
        }

        function showToast(icon, message) {
            Swal.fire({
                icon: icon,
                title: message,
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
        }
    </script>
    
    @stack('scripts')
</body>
</html>

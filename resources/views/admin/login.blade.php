@extends('layouts.app')

@section('title', 'Admin Login')

@push('styles')
<style>
    .network-bg {
        background:
            radial-gradient(circle at 76% 18%, rgba(124, 255, 0, .24), transparent 18rem),
            linear-gradient(135deg, #ffffff 0%, #f6faf7 36%, #061426 37%, #020b18 100%);
        position: relative;
        overflow: hidden;
    }
    .network-bg::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image:
            radial-gradient(circle at 20% 50%, rgba(124, 255, 0, 0.12) 0%, transparent 48%),
            radial-gradient(circle at 82% 78%, rgba(18, 212, 163, 0.14) 0%, transparent 42%),
            linear-gradient(115deg, transparent 0 48%, rgba(124, 255, 0, .18) 48% 49%, transparent 49% 100%);
        animation: pulse 4s ease-in-out infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    .glass-card {
        background: rgba(255, 255, 255, 0.96);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(27, 184, 15, 0.16);
    }
    .network-icon {
        animation: float 3s ease-in-out infinite;
    }
    @keyframes float {
        0%, 100% { transform: translateY(0px); }
        50% { transform: translateY(-10px); }
    }
</style>
@endpush

@section('content')
<div class="min-h-screen network-bg flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-6xl w-full grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">
        <!-- Left Side - Branding -->
        <div class="hidden lg:block text-white space-y-8">
            <div class="space-y-4">
                <div class="flex items-center space-x-4">
                    <div class="h-20 w-20 bg-gradient-to-br from-lime-400 to-green-600 rounded-2xl flex items-center justify-center shadow-2xl network-icon">
                        <i class="fas fa-network-wired text-white text-4xl"></i>
                    </div>
                    <div>
                        <h1 class="text-5xl font-bold tracking-tight">{{ companyName() }}</h1>
                        <p class="text-lime-200 text-lg">ISP Management System</p>
                    </div>
                </div>
            </div>

            <div class="space-y-6 mt-12">
                <div class="flex items-start space-x-4">
                    <div class="h-12 w-12 bg-lime-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-users text-lime-300 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold mb-1">Customer Management</h3>
                        <p class="text-gray-300">Kelola pelanggan, paket, dan invoice dengan mudah</p>
                    </div>
                </div>

                <div class="flex items-start space-x-4">
                    <div class="h-12 w-12 bg-emerald-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-map-marked-alt text-emerald-300 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold mb-1">Network Monitoring</h3>
                        <p class="text-gray-300">Monitor jaringan ODP dan infrastruktur real-time</p>
                    </div>
                </div>

                <div class="flex items-start space-x-4">
                    <div class="h-12 w-12 bg-green-500/20 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-chart-line text-green-300 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold mb-1">Business Analytics</h3>
                        <p class="text-gray-300">Laporan lengkap dan analisis bisnis mendalam</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Login Form -->
        <div class="w-full max-w-md mx-auto">
            <div class="glass-card rounded-3xl shadow-2xl p-8 lg:p-10">
                <!-- Mobile Logo -->
                <div class="lg:hidden text-center mb-8">
                    <div class="mx-auto h-16 w-16 bg-gradient-to-br from-lime-400 to-green-600 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fas fa-network-wired text-white text-2xl"></i>
                    </div>
                    <h2 class="text-3xl font-bold text-gray-900">{{ companyName() }}</h2>
                    <p class="text-gray-600 mt-1">ISP Management System</p>
                </div>

                <!-- Title -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-gray-900 mb-2">Welcome Back</h2>
                    <p class="text-gray-600">Sign in to access admin dashboard</p>
                </div>

                <!-- Error Messages -->
                @if ($errors->any())
                    <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                            <p class="text-sm text-red-700">{{ $errors->first() }}</p>
                        </div>
                    </div>
                @endif

                <!-- Login Form -->
                <form class="space-y-6" action="{{ route('admin.login.post') }}" method="POST">
                    @csrf
                    
                    <div>
                        <label for="email" class="block text-sm font-semibold text-gray-700 mb-2">
                            Email Address
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-gray-400"></i>
                            </div>
                            <input 
                                id="email" 
                                name="email" 
                                type="email" 
                                autocomplete="email" 
                                required 
                                value="{{ old('email') }}"
                                class="block w-full pl-11 pr-4 py-3 border border-gray-300 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-lime-500 focus:border-transparent transition"
                                placeholder="admin@gembok.com"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-semibold text-gray-700 mb-2">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input 
                                id="password" 
                                name="password" 
                                type="password" 
                                autocomplete="current-password" 
                                required 
                                class="block w-full pl-11 pr-4 py-3 border border-gray-300 rounded-xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-lime-500 focus:border-transparent transition"
                                placeholder="••••••••"
                            >
                        </div>
                    </div>

                    <div class="flex items-center">
                        <input 
                            id="remember" 
                            name="remember" 
                            type="checkbox" 
                            class="h-4 w-4 text-green-600 focus:ring-lime-500 border-gray-300 rounded"
                        >
                        <label for="remember" class="ml-2 block text-sm text-gray-700">
                            Remember me for 30 days
                        </label>
                    </div>

                    <button 
                        type="submit" 
                        class="w-full flex justify-center items-center py-3.5 px-4 border border-transparent rounded-xl text-white font-semibold bg-gradient-to-r from-green-600 to-lime-500 hover:from-green-700 hover:to-lime-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-lime-500 transition-all duration-200 transform hover:scale-[1.02] shadow-lg hover:shadow-xl"
                    >
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Sign In to Dashboard
                    </button>
                </form>

                <!-- Default Credentials -->
                <div class="mt-6 p-4 bg-gradient-to-r from-lime-50 to-green-50 rounded-xl border border-lime-200">
                    <div class="flex items-center justify-center text-sm">
                        <i class="fas fa-info-circle text-green-600 mr-2"></i>
                        <span class="text-gray-700">
                            <strong>Default:</strong> admin@gembok.com / admin123
                        </span>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center mt-6 text-white text-sm">
                <p>&copy; {{ date('Y') }} {{ companyName() }}. All rights reserved.</p>
            </div>
        </div>
    </div>
</div>
@endsection

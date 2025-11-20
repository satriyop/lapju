<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-gradient-to-br from-zinc-900 via-zinc-800 to-zinc-900">
    <div class="flex min-h-screen flex-col">
        {{-- Navigation --}}
        <nav class="border-b border-zinc-700/50 bg-zinc-900/50 backdrop-blur-sm">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-red-600 to-red-700">
                            <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-white">LAPJU</div>
                            <div class="text-xs text-zinc-400">Laporan Kemajuan</div>
                        </div>
                    </div>

                    @auth
                        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                            </svg>
                            Dashboard
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                            </svg>
                            Login
                        </a>
                    @endauth
                </div>
            </div>
        </nav>

        {{-- Hero Section --}}
        <main class="flex flex-1 items-center">
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                {{-- Organization Logos --}}
                <div class="mb-12 flex items-center justify-center gap-8">
                    {{-- Korem Logo --}}
                    <div class="flex flex-col items-center gap-3">
                        <div class="flex h-24 w-24 items-center justify-center rounded-full bg-white p-2">
                            <img src="{{ asset('images/korem-logo.png') }}" alt="Korem 074 Warastratama" class="h-full w-full object-contain">
                        </div>
                        <div class="text-center">
                            <div class="text-xs font-medium text-zinc-300">Korem 074</div>
                            <div class="text-xs text-zinc-500">Warastratama</div>
                        </div>
                    </div>

                    {{-- Divider --}}
                    <div class="h-16 w-px bg-zinc-700/50"></div>

                    {{-- Koperasi Logo --}}
                    <div class="flex flex-col items-center gap-3">
                        <div class="flex h-24 w-24 items-center justify-center rounded-full bg-white p-2">
                            <img src="{{ asset('images/kopdes-logo.png') }}" alt="Kopdes Merah Putih" class="h-full w-full object-contain">
                        </div>
                        <div class="text-center">
                            <div class="text-xs font-medium text-zinc-300">Kopdes</div>
                            <div class="text-xs text-zinc-500">Merah Putih</div>
                        </div>
                    </div>
                </div>

                {{-- Main Content --}}
                <div class="text-center">
                    <h1 class="mb-4 text-6xl font-bold tracking-tight">
                        <span class="bg-gradient-to-r from-red-500 to-red-700 bg-clip-text text-transparent">LAPJU</span>
                    </h1>
                    <p class="mb-2 text-2xl font-semibold text-white">
                        Sistem Laporan Kemajuan Proyek
                    </p>
                    <p class="mb-4 text-lg text-zinc-300">
                        Kopdes Merah Putih - Korem 074/Warastratama
                    </p>
                    <p class="mx-auto mb-12 max-w-2xl text-base text-zinc-400">
                        Platform digital terintegrasi untuk monitoring, pelaporan, dan evaluasi kemajuan proyek pembangunan koperasi desa secara real-time dengan visualisasi S-Curve
                    </p>

                    {{-- Stats Grid with Animation --}}
                    <div class="mx-auto mb-16 grid max-w-3xl gap-4 sm:grid-cols-3">
                        <div class="group rounded-xl border border-zinc-700/50 bg-zinc-800/50 p-6 backdrop-blur-sm transition-all duration-300 hover:scale-105 hover:border-red-500/50 hover:bg-zinc-800">
                            <div class="mb-2 text-4xl font-bold text-red-500 transition-all duration-300 group-hover:scale-110">100%</div>
                            <div class="text-sm text-zinc-400">Akurasi Data</div>
                        </div>
                        <div class="group rounded-xl border border-zinc-700/50 bg-zinc-800/50 p-6 backdrop-blur-sm transition-all duration-300 hover:scale-105 hover:border-red-500/50 hover:bg-zinc-800">
                            <div class="mb-2 flex items-center justify-center">
                                <svg class="mr-2 h-8 w-8 animate-pulse text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                                <div class="text-4xl font-bold text-red-500">Live</div>
                            </div>
                            <div class="text-sm text-zinc-400">Update Real-time</div>
                        </div>
                        <div class="group rounded-xl border border-zinc-700/50 bg-zinc-800/50 p-6 backdrop-blur-sm transition-all duration-300 hover:scale-105 hover:border-red-500/50 hover:bg-zinc-800">
                            <div class="mb-2 text-4xl font-bold text-red-500 transition-all duration-300 group-hover:scale-110">4</div>
                            <div class="text-sm text-zinc-400">Level Hierarki</div>
                        </div>
                    </div>

                    {{-- Key Features Grid with Enhanced Icons & Animations --}}
                    <div class="mb-6 text-center">
                        <h2 class="mb-2 text-3xl font-bold text-white">Fitur Unggulan</h2>
                        <p class="text-zinc-400">Sistem monitoring proyek yang komprehensif dan mudah digunakan</p>
                    </div>

                    <div class="mx-auto mb-16 grid max-w-6xl gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        {{-- Feature 1: Real-time Reporting --}}
                        <div class="group relative overflow-hidden rounded-2xl border border-zinc-700/50 bg-zinc-800/30 p-8 backdrop-blur-sm transition-all duration-500 hover:-translate-y-2 hover:border-red-500/50 hover:bg-zinc-800/50 hover:shadow-2xl hover:shadow-red-500/20">
                            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-red-500/10 blur-2xl transition-all duration-500 group-hover:bg-red-500/20"></div>
                            <div class="relative mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-red-600 to-red-700 shadow-lg shadow-red-500/50 transition-all duration-500 group-hover:scale-110 group-hover:rotate-6 group-hover:shadow-xl group-hover:shadow-red-500/70">
                                <svg class="h-8 w-8 animate-pulse text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <h3 class="mb-3 text-lg font-bold text-white transition-colors group-hover:text-red-400">Real-time Reporting</h3>
                            <p class="text-sm leading-relaxed text-zinc-400">Update progress proyek secara langsung dengan notifikasi instant dan sinkronisasi otomatis</p>
                            <div class="mt-4 flex items-center text-xs text-red-500">
                                <span class="mr-2">Pelajari lebih lanjut</span>
                                <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </div>

                        {{-- Feature 2: Dashboard S-Curve --}}
                        <div class="group relative overflow-hidden rounded-2xl border border-zinc-700/50 bg-zinc-800/30 p-8 backdrop-blur-sm transition-all duration-500 hover:-translate-y-2 hover:border-red-500/50 hover:bg-zinc-800/50 hover:shadow-2xl hover:shadow-red-500/20">
                            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-red-500/10 blur-2xl transition-all duration-500 group-hover:bg-red-500/20"></div>
                            <div class="relative mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-red-600 to-red-700 shadow-lg shadow-red-500/50 transition-all duration-500 group-hover:scale-110 group-hover:rotate-6 group-hover:shadow-xl group-hover:shadow-red-500/70">
                                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                                </svg>
                            </div>
                            <h3 class="mb-3 text-lg font-bold text-white transition-colors group-hover:text-red-400">Dashboard S-Curve</h3>
                            <p class="text-sm leading-relaxed text-zinc-400">Visualisasi grafik S-Curve untuk analisis perbandingan progress rencana vs aktual</p>
                            <div class="mt-4 flex items-center text-xs text-red-500">
                                <span class="mr-2">Pelajari lebih lanjut</span>
                                <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </div>

                        {{-- Feature 3: Task Templating --}}
                        <div class="group relative overflow-hidden rounded-2xl border border-zinc-700/50 bg-zinc-800/30 p-8 backdrop-blur-sm transition-all duration-500 hover:-translate-y-2 hover:border-red-500/50 hover:bg-zinc-800/50 hover:shadow-2xl hover:shadow-red-500/20">
                            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-red-500/10 blur-2xl transition-all duration-500 group-hover:bg-red-500/20"></div>
                            <div class="relative mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-red-600 to-red-700 shadow-lg shadow-red-500/50 transition-all duration-500 group-hover:scale-110 group-hover:rotate-6 group-hover:shadow-xl group-hover:shadow-red-500/70">
                                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                                </svg>
                            </div>
                            <h3 class="mb-3 text-lg font-bold text-white transition-colors group-hover:text-red-400">Task Templating</h3>
                            <p class="text-sm leading-relaxed text-zinc-400">Template tugas terstandar dengan hierarki dan kalkulasi bobot progress otomatis</p>
                            <div class="mt-4 flex items-center text-xs text-red-500">
                                <span class="mr-2">Pelajari lebih lanjut</span>
                                <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </div>

                        {{-- Feature 4: Integrated Reporting --}}
                        <div class="group relative overflow-hidden rounded-2xl border border-zinc-700/50 bg-zinc-800/30 p-8 backdrop-blur-sm transition-all duration-500 hover:-translate-y-2 hover:border-red-500/50 hover:bg-zinc-800/50 hover:shadow-2xl hover:shadow-red-500/20">
                            <div class="absolute -right-4 -top-4 h-24 w-24 rounded-full bg-red-500/10 blur-2xl transition-all duration-500 group-hover:bg-red-500/20"></div>
                            <div class="relative mb-6 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-red-600 to-red-700 shadow-lg shadow-red-500/50 transition-all duration-500 group-hover:scale-110 group-hover:rotate-6 group-hover:shadow-xl group-hover:shadow-red-500/70">
                                <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <h3 class="mb-3 text-lg font-bold text-white transition-colors group-hover:text-red-400">Integrated Reporting</h3>
                            <p class="text-sm leading-relaxed text-zinc-400">Sistem pelaporan terintegrasi multi-level dari Koramil hingga Kodam</p>
                            <div class="mt-4 flex items-center text-xs text-red-500">
                                <span class="mr-2">Pelajari lebih lanjut</span>
                                <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </div>
                    </div>

                    {{-- Benefits Section --}}
                    <div class="mx-auto mb-12 max-w-4xl rounded-2xl border border-zinc-700/50 bg-zinc-800/30 p-8 backdrop-blur-sm">
                        <h2 class="mb-6 text-2xl font-bold text-white">Keunggulan Sistem</h2>
                        <div class="grid gap-4 text-left sm:grid-cols-2">
                            <div class="flex gap-3">
                                <svg class="h-6 w-6 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <div>
                                    <div class="font-medium text-white">Monitoring Terpusat</div>
                                    <div class="text-sm text-zinc-400">Pantau semua proyek dari satu dashboard</div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <svg class="h-6 w-6 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <div>
                                    <div class="font-medium text-white">Laporan Otomatis</div>
                                    <div class="text-sm text-zinc-400">Generate laporan progress secara otomatis</div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <svg class="h-6 w-6 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <div>
                                    <div class="font-medium text-white">Kalkulasi Akurat</div>
                                    <div class="text-sm text-zinc-400">Perhitungan bobot dan progress otomatis</div>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <svg class="h-6 w-6 flex-shrink-0 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                                <div>
                                    <div class="font-medium text-white">Akses Mobile</div>
                                    <div class="text-sm text-zinc-400">Responsive design untuk akses di mana saja</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- CTA Buttons --}}
                    <div class="flex items-center justify-center gap-4">
                        @auth
                            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-3 text-base font-medium text-white transition hover:bg-red-700">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                </svg>
                                Ke Dashboard
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-6 py-3 text-base font-medium text-white transition hover:bg-red-700">
                                Masuk
                            </a>
                            <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-lg border border-zinc-700 bg-zinc-800/50 px-6 py-3 text-base font-medium text-white transition hover:bg-zinc-800">
                                Daftar
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        </main>

        {{-- Footer --}}
        <footer class="border-t border-zinc-700/50 bg-zinc-900/50 py-6">
            <div class="mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
                <p class="text-sm text-zinc-400">
                    &copy; {{ date('Y') }} Korem 074/Warastratama &amp; Koperasi Merah Putih. All rights reserved.
                </p>
            </div>
        </footer>
    </div>

    @fluxScripts
</body>
</html>

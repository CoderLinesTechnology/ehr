<x-layouts.app title="Dashboard">
    <x-ui.page-header :title="'Welcome, '.auth()->user()->name" :description="tenant()->organization()->name" />
</x-layouts.app>

<x-layouts.app :title="'Shell preview'" :preview-shell="$shell">
    <x-ui.page-header title="Clients" description="Manage your clients and view their information." icon="users">
        <x-slot:actions><x-ui.button icon="plus">Add Client</x-ui.button></x-slot:actions>
    </x-ui.page-header>
</x-layouts.app>

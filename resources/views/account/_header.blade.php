{{-- Shared header of the account pages. Expects $active = 'profile' | 'security'. --}}
<x-ui.page-header title="Your account" description="Your personal profile and sign-in security. These settings follow you across every organization you belong to." />
<x-ui.tabs
    label="Account sections"
    :tabs="[
        ['label' => 'Profile', 'url' => route('account.profile'), 'active' => $active === 'profile', 'icon' => 'user'],
        ['label' => 'Security', 'url' => route('account.security'), 'active' => $active === 'security', 'icon' => 'shield'],
    ]"
/>

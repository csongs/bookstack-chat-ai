@php
    $aiAllowedRoles = AiChatSettings::getRoles();
    $userRoleIds    = user()->roles->pluck('id')->toArray();
    $canUseAi       = user()->can('settings-manage')
                   || (!empty($aiAllowedRoles) && !empty(array_intersect($aiAllowedRoles, $userRoleIds)));
@endphp
@if(!user()->isGuest() && $canUseAi)
<button type="button" id="ai-sidebar-trigger" title="Ask AI">
    <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 2a7 7 0 0 1 7 7c0 3.5-2.5 6.5-6 7v2H9v-2c-3.5-.5-6-3.5-6-7a7 7 0 0 1 7-7z"/>
        <line x1="9" y1="21" x2="15" y2="21"/>
        <line x1="9" y1="18" x2="15" y2="18"/>
        <circle cx="9" cy="10" r="1" fill="currentColor"/>
        <circle cx="15" cy="10" r="1" fill="currentColor"/>
    </svg>
    Ask AI
</button>
@endif

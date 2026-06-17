<?php

use Illuminate\Support\Facades\DB;

class AiChatController
{
    private function authorizeChat(): void
    {
        $user = user();
        if (!$user || $user->isGuest()) abort(403);
        if ($user->can('settings-manage')) return;

        $allowedRoles = AiChatSettings::getRoles();
        if (empty($allowedRoles)) abort(403);

        $userRoleIds = $user->roles->pluck('id')->toArray();
        if (empty(array_intersect($allowedRoles, $userRoleIds))) abort(403);
    }

    // ── Chat ──────────────────────────────────────────────

    public function show()
    {
        $this->authorizeChat();
        return view('ai-chat.chat');
    }

    public function ask()
    {
        $this->authorizeChat();

        $query = trim(request()->get('query', ''));
        if (!$query) abort(400);

        $api = new AiChatApi();

        return response()->eventStream(function () use ($api, $query) {
            foreach ($api->stream($query) as $chunk) {
                yield $chunk;
            }
        });
    }

    // ── Settings ──────────────────────────────────────────

    public function showSettings()
    {
        $user = user();
        if (!$user || !$user->can('settings-manage')) abort(403);

        return view('ai-chat.ai-chat-settings', [
            'allRoles'    => DB::table('roles')->orderBy('display_name')->get(['id', 'display_name']),
            'savedRoles'  => AiChatSettings::getRoles(),
            'drivers'     => AiChatApi::driverList(),
            'provider'    => AiChatSettings::get('ai-chat-provider', 'openai'),
            'systemPrompt'=> AiChatSettings::get('ai-chat-system-prompt'),
        ]);
    }

    public function saveSettings()
    {
        $user = user();
        if (!$user || !$user->can('settings-manage')) abort(403);

        AiChatSettings::set('ai-chat-provider',      trim(request()->get('provider', 'openai')));
        AiChatSettings::set('ai-chat-system-prompt', request()->get('system_prompt', ''));

        $roleIds = array_values(array_map('intval', (array) request()->get('roles', [])));
        AiChatSettings::set('ai-chat-roles', json_encode($roleIds));

        return redirect('/ai-chat/settings')->with('status', 'saved');
    }
}

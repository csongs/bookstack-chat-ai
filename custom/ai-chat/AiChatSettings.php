<?php

use Illuminate\Support\Facades\DB;

class AiChatSettings
{
    public static function get(string $key, string $default = ''): string
    {
        $val = DB::table('settings')->where('setting_key', $key)->value('value');
        return $val !== null ? $val : $default;
    }

    public static function set(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['setting_key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
    }

    public static function getRoles(): array
    {
        $raw = DB::table('settings')->where('setting_key', 'ai-chat-roles')->value('value');
        return $raw !== null ? (json_decode($raw, true) ?: []) : [];
    }
}

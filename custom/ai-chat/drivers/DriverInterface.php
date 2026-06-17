<?php

interface DriverInterface
{
    /** 用於 DB 儲存的 key，e.g. 'openai' */
    public static function driverKey(): string;

    /** UI 顯示名稱 */
    public static function label(): string;

    /**
     * 此 driver 需要的環境變數
     * 格式：[ 'VAR_NAME' => 'description', ... ]
     */
    public static function envVars(): array;

    /**
     * 從環境變數建立 driver 實例
     * 必要 env 缺失時應 throw RuntimeException
     */
    public static function make(): static;

    /** API endpoint URL */
    public function endpoint(): string;

    /** HTTP headers */
    public function headers(): array;

    /** Request body */
    public function body(string $query, string $systemPrompt): array;

    /**
     * 解析一行 SSE raw data，回傳：
     *   ['text' => '...']  → 有內容片段
     *   ['done' => true]   → stream 結束
     *   []                 → 忽略此行
     */
    public function parseLine(string $raw): array;
}

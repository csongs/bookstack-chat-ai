<?php

trait ParsesOpenAiStream
{
    public function parseLine(string $raw): array
    {
        if (trim($raw) === '[DONE]') return ['done' => true];
        $data = json_decode($raw, true);
        $text = $data['choices'][0]['delta']['content'] ?? null;
        return $text !== null ? ['text' => $text] : [];
    }
}

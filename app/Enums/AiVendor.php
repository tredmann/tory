<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiVendor: string implements HasLabel
{
    case Ollama = 'ollama';
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Mistral = 'mistral';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ollama => 'Ollama',
            self::Anthropic => 'Claude',
            self::OpenAi => 'OpenAI',
            self::Mistral => 'Mistral',
        };
    }

    public function requiresKey(): bool
    {
        return $this !== self::Ollama;
    }

    public function supportsEmbeddings(): bool
    {
        return $this !== self::Anthropic;
    }
}

<?php

namespace Dashed\DashedOpenai;

use Exception;
use Illuminate\Support\Str;
use Dashed\DashedAi\AiProvider;
use Illuminate\Support\Facades\Http;
use Dashed\DashedAi\Enums\AiCapability;
use Filament\Forms\Components\TextInput;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedAi\Exceptions\AiRateLimitException;

class OpenAiProvider extends AiProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function supportedCapabilities(): array
    {
        return [
            AiCapability::Text,
            AiCapability::Json,
            AiCapability::Vision,
            AiCapability::Image,
        ];
    }

    public function isConnected(): bool
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return false;
        }

        return cache()->rememberForever('ai_connected_openai', function () use ($apiKey) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(10)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-3.5-turbo',
                        'messages' => [['role' => 'user', 'content' => 'Hi']],
                        'max_tokens' => 1,
                    ]);
            } catch (Exception) {
                return false;
            }

            return $response->successful() || $response->status() === 429;
        });
    }

    public function text(string $prompt, array $options = []): ?string
    {
        $messages = $this->buildMessages($prompt, $options);
        $response = $this->chatRequest($messages, $options['max_tokens'] ?? 10000);

        if ($response === null) {
            return null;
        }

        return Str::markdown($response);
    }

    public function json(string $prompt, array $options = []): ?array
    {
        $messages = $this->buildMessages($prompt, $options);
        $response = $this->chatRequest($messages, $options['max_tokens'] ?? 10000);

        return $this->parseJsonResponse($response);
    }

    public function vision(string $prompt, string $imageData, string $mimeType, array $options = []): ?string
    {
        $messages = [];
        $system = $this->buildSystemPrompt($options);
        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$imageData}"]],
            ],
        ];

        return $this->chatRequest($messages, $options['max_tokens'] ?? 200);
    }

    public function image(string $prompt, array $options = []): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey || ! $this->isConnected()) {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => $options['model'] ?? 'dall-e-3',
                    'prompt' => $prompt,
                    'n' => 1,
                    'size' => $options['size'] ?? '1024x1024',
                ]);
        } catch (Exception) {
            return null;
        }

        if ($response->successful()) {
            return $response->json('data.0.url');
        }

        return null;
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('open_ai_api_key')
                ->label('OpenAI API sleutel')
                ->password()
                ->revealable()
                ->placeholder('sk-...')
                ->helperText('Je vindt je API sleutel op platform.openai.com → API Keys.'),
        ];
    }

    protected function apiKey(): ?string
    {
        return Customsetting::get('open_ai_api_key');
    }

    protected function buildMessages(string $prompt, array $options): array
    {
        $messages = [];
        $system = $this->buildSystemPrompt($options);

        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }

    protected function chatRequest(array $messages, int $maxTokens = 10000): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey || ! $this->isConnected()) {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                ]);
        } catch (Exception) {
            return null;
        }

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        if ($response->status() === 429) {
            throw new AiRateLimitException('OpenAI rate limit exceeded');
        }

        return null;
    }
}

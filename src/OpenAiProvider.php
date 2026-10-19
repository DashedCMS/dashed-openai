<?php

namespace Dashed\DashedOpenai;

use Exception;
use Illuminate\Support\Str;
use Dashed\DashedAi\AiProvider;
use Illuminate\Support\Facades\Http;
use Dashed\DashedAi\Logging\AiLogger;
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
            AiCapability::Embedding,
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
        $response = $this->chatRequest($messages, $options['max_tokens'] ?? 10000, [], 'text', $options);

        if ($response === null) {
            return null;
        }

        return Str::markdown($response);
    }

    public function json(string $prompt, array $options = []): ?array
    {
        // Prepend a concise raw-JSON instruction to the user's system options.
        $existingSystem = $options['system'] ?? '';
        $jsonEnforcer = 'Retourneer uitsluitend geldige JSON. Geen markdown, geen code fences (```), geen uitleg voor of na de JSON. Begin direct met { of [.';
        $options['system'] = trim($existingSystem."\n\n".$jsonEnforcer);

        $messages = $this->buildMessages($prompt, $options);
        $response = $this->chatRequest(
            $messages,
            $options['max_tokens'] ?? 10000,
            ['response_format' => ['type' => 'json_object']],
            'json',
            $options,
        );

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

        return $this->chatRequest($messages, $options['max_tokens'] ?? 200, [], 'vision', $options);
    }

    public function image(string $prompt, array $options = []): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey || ! $this->isConnected()) {
            return null;
        }

        $payload = [
            'model' => $options['model'] ?? 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $options['size'] ?? '1024x1024',
        ];
        $log = AiLogger::begin('openai', 'image', ['model' => $payload['model'], 'prompt' => $prompt, 'options' => $options + ['size' => $payload['size']]])
            ->units(1, 'image');

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/images/generations', $payload);
        } catch (Exception $e) {
            $log->fail($e);

            return null;
        }

        if ($response->successful()) {
            $log->response($response->json('data.0.url'))->succeed();

            return $response->json('data.0.url');
        }

        $log->fail('HTTP ' . $response->status() . ' ' . ($response->json('error.message') ?? ''));

        return null;
    }

    public function embed(string $text, array $options = []): array
    {
        $apiKey = $this->apiKey();
        if (! $apiKey) {
            return [];
        }

        $model = $options['model'] ?? 'text-embedding-3-small';
        $log = AiLogger::begin('openai', 'embedding', ['model' => $model, 'prompt' => $text, 'options' => $options]);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => $model,
                    'input' => $text,
                ])
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            $log->fail($e);

            throw $e;
        }

        $embedding = $response['data'][0]['embedding'] ?? [];
        $log->model($response['model'] ?? null)
            ->usage($response['usage'] ?? null)
            ->response('[embedding, ' . count($embedding) . ' dimensies]')
            ->succeed();

        return $embedding;
    }

    public function settingsSchema(): array
    {
        return [
            TextInput::make('open_ai_api_key')
                ->label(__('OpenAI API sleutel'))
                ->password()
                ->revealable()
                ->placeholder(__('sk-...'))
                ->helperText(__('Je vindt je API sleutel op platform.openai.com → API Keys.')),
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

    protected function chatRequest(array $messages, int $maxTokens = 10000, array $extraPayload = [], string $kind = 'text', array $options = []): ?string
    {
        $apiKey = $this->apiKey();
        if (! $apiKey || ! $this->isConnected()) {
            return null;
        }

        $payload = array_merge([
            'model' => $options['model'] ?? 'gpt-4o',
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ], $extraPayload);

        $log = AiLogger::begin('openai', $kind, ['model' => $payload['model'], 'prompt' => $messages, 'options' => $options + ['max_tokens' => $maxTokens]]);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (Exception $e) {
            $log->fail($e);

            return null;
        }

        if ($response->successful()) {
            $log->model($response->json('model'))
                ->usage($response->json('usage'))
                ->response($response->json('choices.0.message.content'))
                ->succeed();

            return $response->json('choices.0.message.content');
        }

        $log->fail('HTTP ' . $response->status() . ' ' . ($response->json('error.message') ?? ''));

        if ($response->status() === 429) {
            throw new AiRateLimitException('OpenAI rate limit exceeded');
        }

        return null;
    }
}

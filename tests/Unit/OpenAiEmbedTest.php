<?php

use Dashed\DashedOpenai\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('returns an embedding vector for given text', function () {
    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response([
            'data' => [
                ['embedding' => [0.1, 0.2, 0.3]],
            ],
        ]),
    ]);

    // OpenAiProvider reads its key from Customsetting; use an anonymous
    // subclass to stub the api key without touching the DB.
    $provider = new class extends OpenAiProvider
    {
        protected function apiKey(): ?string
        {
            return 'sk-test';
        }
    };

    $vector = $provider->embed('theelichthouder');

    expect($vector)->toBe([0.1, 0.2, 0.3]);
});

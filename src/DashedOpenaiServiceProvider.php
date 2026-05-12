<?php

namespace Dashed\DashedOpenai;

use Dashed\DashedAi\AiManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedOpenaiServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-openai';

    public function configurePackage(Package $package): void
    {
        $package->name(self::$name);
    }

    public function bootingPackage(): void
    {
        cms()->registerIntegration([
            'slug' => 'openai',
            'label' => 'OpenAI',
            'icon' => 'heroicon-o-sparkles',
            'category' => 'ai',
            'settings_page' => \Dashed\DashedAi\Filament\Pages\Settings\AiSettingsPage::class,
            'health_check' => fn (?string $siteId = null) => \Dashed\DashedCore\Integrations\IntegrationHealth::fromSettings(['open_ai_api_key'], $siteId, 'API key ontbreekt'),
            'package' => 'dashed-openai',
        ]);

        app(AiManager::class)->register(new OpenAiProvider());
    }
}

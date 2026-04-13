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
        app(AiManager::class)->register(new OpenAiProvider());
    }
}

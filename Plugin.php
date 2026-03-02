<?php

namespace App\Vito\Plugins\Arifnd\VitoDnsDesec;

use App\Vito\Plugins\Arifnd\VitoDnsPorkbun\DNSProviders\Desec;
use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterDNSProvider;
use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;

class Plugin extends AbstractPlugin
{
    protected string $name = 'deSEC';

    protected string $description = 'deSEC DNS plugin for VitoDeploy';

    public function register(): void {}

    public function boot(): void
    {
        $this->desec();
    }

    private function desec(): void
    {
        RegisterDNSProvider::make(Desec::id())
            ->label('deSEC DNS Plugin')
            ->handler(Desec::class)
            ->form(
                DynamicForm::make([
                    DynamicField::make('token')
                        ->text()
                        ->label('Token')
                        ->description('deSEC Token'),
                ])
            )
            ->register();
    }
}
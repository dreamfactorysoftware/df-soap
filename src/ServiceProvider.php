<?php

namespace DreamFactory\Core\Soap;

use DreamFactory\Core\Enums\LicenseLevel;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\Soap\Models\SoapConfig;
use DreamFactory\Core\Soap\Services\Soap;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'                  => 'soap',
                    'label'                 => 'SOAP Service',
                    'description'           => 'A service to handle SOAP Services',
                    'group'                 => ServiceTypeGroups::REMOTE,
                    'subscription_required' => LicenseLevel::SILVER,
                    'config_handler'        => SoapConfig::class,
                    'factory'               => function ($config) {
                        return new Soap($config);
                    },
                ])
            );
        });
    }

    public function boot()
    {
        // add migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}

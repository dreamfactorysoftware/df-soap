<?php
namespace DreamFactory\Core\Soap;

use DreamFactory\Core\Components\ServiceDocBuilder;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use DreamFactory\Core\Soap\Models\SoapConfig;
use DreamFactory\Core\Soap\Services\Soap;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    use ServiceDocBuilder;

    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'            => 'soap',
                    'label'           => 'SOAP Service',
                    'description'     => 'A service to handle SOAP Services',
                    'group'           => ServiceTypeGroups::REMOTE,
                    'config_handler'  => SoapConfig::class,
                    'default_api_doc' => function ($service) {
                        /** @var \DreamFactory\Core\Models\Service $service */
                        $soap = new Soap(['config' => $service->getConfigAttribute()]);

                        return $this->buildServiceDoc($service->id, $soap->buildApiDocInfo());
                    },
                    'factory'         => function ($config) {
                        return new Soap($config);
                    },
                ])
            );
        });
    }
}

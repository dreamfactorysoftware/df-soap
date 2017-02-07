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
                        try {
                            /** @var \DreamFactory\Core\Models\Service $service */
                            $service->protectedView = false;
                            $soap = new Soap(
                                [
                                    'id'     => $service->id,
                                    'name'   => $service->name,
                                    'config' => $service->getConfigAttribute()
                                ]);

                            return $this->buildServiceDoc($service->id, $soap->buildApiDocInfo());
                        } catch (\Exception $ex) {
                            \Log::error('Failed to get API Doc from service: ' . $ex->getMessage());
                            return [];
                        }
                    },
                    'factory'         => function ($config) {
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

<?php

use App\Providers\AppServiceProvider;
use App\Providers\OperatorSettingsServiceProvider;
use App\Providers\QueueConsumerHeartbeatServiceProvider;

return [
    AppServiceProvider::class,
    OperatorSettingsServiceProvider::class,
    QueueConsumerHeartbeatServiceProvider::class,
];

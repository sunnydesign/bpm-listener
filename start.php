#!/usr/bin/php

<?php

/**
 * Camunda Process Initiator
 */

sleep(1); // timeout for start through supervisor

require_once __DIR__ . '/vendor/autoload.php';

// Libs
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Kubia\Camunda\CamundaListener;

// Config
$config = __DIR__ . '/config.php';
$config_env = __DIR__ . '/config.env.php';
if (is_file($config)) {
    require_once $config;
} elseif (is_file($config_env)) {
    require_once $config_env;
}

// Create connection
$connection = new AMQPStreamConnection(
    RMQ_HOST,
    RMQ_PORT,
    RMQ_USER,
    RMQ_PASS,
    RMQ_VHOST,
    false,
    'AMQPLAIN',
    null,
    'en_US',
    3.0,
    3.0,
    null,
    true,
    60
);

// Create connection for logging
if(LOGGING) {
    $connectionLog = new AMQPStreamConnection(
        RMQ_HOST,
        RMQ_PORT,
        RMQ_USER_LOG,
        RMQ_PASS_LOG,
        RMQ_VHOST_LOG,
        false,
        'AMQPLAIN',
        null,
        'en_US',
        3.0,
        3.0,
        null,
        true,
        60
    );
} else {
    $connectionLog = null;
}
// Config
$camundaConfig = [
    'apiUrl'   => CAMUNDA_API_URL,
    'apiLogin' => CAMUNDA_API_LOGIN,
    'apiPass'  => CAMUNDA_API_PASS
];
$rmqConfig = [
    'queue'            => RMQ_QUEUE_IN,
    'tickTimeout'      => RMQ_TICK_TIMEOUT,
    'reconnectTimeout' => RMQ_RECONNECT_TIMEOUT,
    'queueLog'         => RMQ_QUEUE_LOG,
    'vhostLog'         => RMQ_VHOST_LOG,
    'userLog'          => RMQ_USER_LOG,
    'passLog'          => RMQ_PASS_LOG,
    'logging'          => LOGGING
];

// Run worker
$worker = new CamundaListener($connection, $connectionLog, $camundaConfig, $rmqConfig);
$worker->run();
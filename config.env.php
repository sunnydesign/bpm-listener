<?php
define('CAMUNDA_API_LOGIN', getenv('CAMUNDA_API_LOGIN'));
define('CAMUNDA_API_PASS', getenv('CAMUNDA_API_PASS'));
define('CAMUNDA_API_URL', getenv('CAMUNDA_API_URL'));
define('CAMUNDA_TICK_TIMEOUT',  getenv('CAMUNDA_TICK_TIMEOUT'));
define('RMQ_HOST', getenv('RMQ_HOST'));
define('RMQ_PORT', getenv('RMQ_PORT'));
define('RMQ_VHOST', getenv('RMQ_VHOST'));
define('RMQ_USER', getenv('RMQ_USER'));
define('RMQ_PASS', getenv('RMQ_PASS'));
define('RMQ_QUEUE_IN', getenv('RMQ_QUEUE_IN'));
define('RMQ_QUEUE_LOG', getenv('RMQ_QUEUE_LOG'));
define('RMQ_VHOST_LOG', getenv('RMQ_VHOST_LOG'));
define('RMQ_USER_LOG', getenv('RMQ_USER_LOG'));
define('RMQ_PASS_LOG', getenv('RMQ_PASS_LOG'));
define('RMQ_RECONNECT_TIMEOUT', getenv('RMQ_RECONNECT_TIMEOUT'));
define('RMQ_TICK_TIMEOUT', getenv('RMQ_TICK_TIMEOUT'));
define('LOGGING', getenv('LOGGING'));
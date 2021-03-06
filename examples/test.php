#!/usr/bin/php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class AMQPResponse {
    private $response;
    private $corr_id;
    private $callback_queue;
    private $channel;

    function __construct(&$callback_queue, &$channel) {
        $this->callback_queue = $callback_queue;
        $this->channel = $channel;
    }

    function send($data = []) {
        $this->corr_id = uniqid();
        $delivery_data = ['correlation_id' => $this->corr_id, 'reply_to' => $this->callback_queue];
        $data = array_merge($data, $delivery_data);
        $message = new AMQPMessage(json_encode($data), $delivery_data);
        $this->channel->basic_publish($message, '', RMQ_QUEUE_IN);

        while(!$this->response) {
            $this->channel->wait();
        }

        return $this->response;
    }

    function onResponse(AMQPMessage $rep) {
        if($rep->get('correlation_id') == $this->corr_id) {
            $this->response = $rep->body;
        }
    }
}

// for test; remove it
for($i=0; $i<1; $i++) {

    $connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
    $channel = $connection->channel();
    $channel->confirm_select(); // change channel mode to confirm mode

    list($callback_queue) = $channel->queue_declare('', false, true, false, !false);
    $AMQPResponse = new AMQPResponse( $callback_queue, $channel );
    $channel->basic_consume($callback_queue, '', false, false, false, false, [$AMQPResponse, 'onResponse']);

    // Usage
    $usageHelp = 'Usage for example: php ./examples/test.php --name="checkOtp" --otp="94876" id="db36f0c4-3927-11ea-8da5-0242ac110029"';
    $options = getopt('', ['name:', 'otp:', 'id:']);

    if(!isset($options['name']) || !isset($options['otp']) || !isset($options['id'])) {
        fwrite(STDERR, "Error: Missing options.\n");
        fwrite(STDERR, $usageHelp . "\n");
        exit(1);
    }

    $data = [
        "data" => [
            "otp" => $options['otp']
        ],
        "headers" => [
            "camundaListenerMessageName" => $options['name'],
            "camundaProcessInstanceId"   => $options['id'],
        ],
        'time'   => time(),
    ];
    print " [x] Sent '" . json_encode($data) . PHP_EOL;

    $response = $AMQPResponse->send($data);

    print " [x] Response '$response'" . PHP_EOL;

    $channel->close();
    $connection->close();

}

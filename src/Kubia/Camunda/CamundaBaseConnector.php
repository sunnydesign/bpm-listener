<?php

namespace Kubia\Camunda;

use Camunda\Entity\Request\ProcessInstanceRequest;
use Camunda\Service\ProcessInstanceService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use Quancy\Logger\Logger;

/**
 * Base Abstract Class CamundaBaseConnector
 */
abstract class CamundaBaseConnector
{
    /** @var \PhpAmqpLib\Connection\AMQPStreamConnection */
    public $connection;

    /** @var \PhpAmqpLib\Channel\AMQPChannel  */
    public $channel;

    /** @var string */
    public $camundaUrl;

    /** @var object */
    public $processVariables;

    /** @var array */
    public $updatedVariables;

    /** @var array */
    public $message;

    /** @var array */
    public $headers;

    /** @var string */
    public $requestErrorMessage = 'Request error';

    /** @var array Unsafe parameters in headers **/
    public $unsafeHeadersParams = [];

    /** @var string */
    public $logOwner = '';

    /** @var array */
    public $camundaConfig = [];

    /** @var array */
    public $rmqConfig = [];

    /**
     * CamundaBaseConnector constructor
     * @param $connection
     * @param $camundaConfig
     * @param $rmqConfig
     */
    function __construct(AMQPStreamConnection &$connection, array $camundaConfig, array $rmqConfig)
    {
        $this->camundaConfig = $camundaConfig;
        $this->rmqConfig = $rmqConfig;
        // connect to camunda api with basic auth
        $this->camundaUrl = sprintf($this->camundaConfig['apiUrl'], $this->camundaConfig['apiLogin'], $this->camundaConfig['apiPass']);
        $this->connection = $connection;
        $this->channel = $this->connection->channel();
    }

    /**
     * Get process variables
     * @return bool
     */
    public function getProcessVariables(): bool
    {
        $processInstanceId = $this->headers['camundaProcessInstanceId'];

        // Get process variables request
        $getVariablesRequest = (new ProcessInstanceRequest())
            ->set('deserializeValues', false);

        $getVariablesService = new ProcessInstanceService($this->camundaUrl);
        $this->processVariables = $getVariablesService->getVariableList($processInstanceId, $getVariablesRequest);

        if($getVariablesService->getResponseCode() != 200) {
            $logMessage = sprintf(
                "Process variables from process instance <%s> not received, because `%s`",
                $processInstanceId,
                $getVariablesService->getResponseContents()->message ?? $this->requestErrorMessage
            );
            Logger::log($logMessage, 'input', $this->rmqConfig['queue'], $this->logOwner, 1 );

            return false;
        } else {
            return true;
        }
    }

    /**
     * Validate message
     */
    public function validateMessage(): void
    {
        // Headers
        if(!$this->headers) {
            $logMessage = '`headers` not is set in incoming message';
            Logger::log($logMessage, 'input', $this->rmqConfig['queue'], $this->logOwner, 1);
            //exit(1);
        }

        // Unsafe params
        foreach ($this->unsafeHeadersParams as $paramName) {
            if(!isset($this->headers[$paramName])) {
                $logMessage = '`' . $paramName . '` param not is set in incoming message';
                Logger::log($logMessage, 'input', $this->rmqConfig['queue'], $this->logOwner, 1);
                //exit(1);
            }
        }
    }

    /**
     * Get formatted error response
     * for synchronous request
     * @param string $message
     * @return string
     */
    public function getErrorResponseForSynchronousRequest(string $message): string
    {
        $response = [
            'success' => false,
            'error'   => [
                [
                    'message' => $message
                ]
            ]
        ];

        return json_encode($response);
    }

    /**
     * Close connection
     */
    public function cleanupConnection(): void
    {
        // Connection might already be closed.
        // Ignoring exceptions.
        try {
            if($this->connection !== null) {
                $this->connection->close();
            }
        } catch (\ErrorException $e) {
        }
    }

    /**
     * Shutdown
     */
    public function shutdown(): void
    {
        $this->connection->close();
    }

    /**
     * Callback
     * @param AMQPMessage $msg
     */
    public function callback(AMQPMessage $msg): void
    {
        Logger::log(sprintf("Received %s", $msg->body), 'input', $this->rmqConfig['queue'], $this->logOwner, 0 );

        // Set manual acknowledge for received message
        $this->channel->basic_ack($msg->delivery_info['delivery_tag']); // manual confirm delivery message

        // Update variables
        $this->message = json_decode($msg->body, true);
        $this->headers = $this->message['headers'] ?? null;

        // something else
    }

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {
        while(true) {
            try {
                register_shutdown_function([$this, 'shutdown']);

                Logger::log('Waiting for messages. To exit press CTRL+C', 'input', $this->rmqConfig['queue'], $this->logOwner, 0);

                $this->channel = $this->connection->channel();
                $this->channel->confirm_select(); // change channel mode to confirm mode
                $this->channel->basic_qos(0, 1, false); // one message in one loop
                $this->channel->basic_consume($this->rmqConfig['queue'], '', false, false, false, false, [$this, 'callback']);

                while ($this->channel->is_consuming()) {
                    $this->channel->wait(null, true, 0);
                    usleep($this->rmqConfig['tickTimeout']);
                }

            } catch(AMQPRuntimeException $e) {
                echo $e->getMessage() . PHP_EOL;
                $this->cleanupConnection();
                usleep($this->rmqConfig['reconnectTimeout']);
            } catch(\RuntimeException $e) {
                echo "Runtime exception " . $e->getMessage() . PHP_EOL;
                $this->cleanupConnection();
                usleep($this->rmqConfig['reconnectTimeout']);
            } catch(\ErrorException $e) {
                echo "Error exception " . $e->getMessage() . PHP_EOL;
                $this->cleanupConnection();
                usleep($this->rmqConfig['reconnectTimeout']);
            }
        }
    }
}
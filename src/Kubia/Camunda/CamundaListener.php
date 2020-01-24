<?php

namespace Kubia\Camunda;

use Camunda\Entity\Request\MessageRequest;
use Camunda\Service\MessageService;
use PhpAmqpLib\Message\AMQPMessage;
use Kubia\Logger\Logger;

/**
 * Class CamundaListener
 */
class CamundaListener extends CamundaBaseConnector
{
    /** @var array */
    public $unsafeHeadersParams = [
        'camundaListenerMessageName',
        'camundaProcessInstanceId'
    ];

    /** @var string */
    public $logOwner = 'bpm-listener';

    /**
     * Mix process variables
     */
    public function mixProcessVariables(): void
    {
        if($this->processVariables) {
            $processVariablesMessage = json_decode($this->processVariables->message->value, true);
            $paramsFromVariables = $processVariablesMessage['data']['parameters'] ?? [];
            $paramsFromMessage = $this->message['data'] ?? [];

            $processVariablesMessage['data']['parameters'] = array_merge($paramsFromVariables, $paramsFromMessage);
            $processVariablesMessage['headers'] = array_merge($processVariablesMessage['headers'], $this->headers);

            // Update variables
            $this->updatedVariables['message'] = [
                'value' => json_encode($processVariablesMessage),
                'type' => 'Json'
            ];
        } else {
            $this->updatedVariables['message'] = [
                'value' => json_encode($this->message),
                'type' => 'Json'
            ];
        }
    }

    /**
     * Callback
     * @param AMQPMessage $msg
     */
    public function callback(AMQPMessage $msg): void
    {
        Logger::stdout(sprintf("Received %s", $msg->body), 'input', $this->rmqConfig['queue'], $this->logOwner, 0 );

        $this->requestErrorMessage = 'Request error';

        // Set manual acknowledge for received message
        $this->channel->basic_ack($msg->delivery_info['delivery_tag']); // manual confirm delivery message

        $this->msg = $msg;

        // Update variables
        $this->message = json_decode($msg->body, true);
        $this->headers = $this->message['headers'] ?? null;

        // Validate message
        $this->validateMessage();

        // get process variables
        $this->getProcessVariables();

        // mix process variables with variables from rabbit mq
        $this->mixProcessVariables();

        // add correlation_id and reply_to to process variables if is synchronous request
        $this->mixRabbitCorrelationInfo();

        $messageRequest = (new MessageRequest())
            ->set('processVariables', $this->updatedVariables)
            ->set('messageName', $this->headers['camundaListenerMessageName'])
            ->set('processInstanceId', $this->headers['camundaProcessInstanceId'])
            ->set('resultEnabled', true);

        $messageService = new MessageService($this->camundaUrl);

        // Corellate message request
        $messageService->correlate($messageRequest);

        // success
        if($messageService->getResponseCode() == 200) {
            $logMessage = sprintf(
                "Correlate a Message <%s> received",
                $this->headers['camundaListenerMessageName']
            );
            $this->logEvent($logMessage, ['type' => 'business', 'message' => $logMessage]);
        } else {
            // if is synchronous mode
            if($msg->has('correlation_id') && $msg->has('reply_to'))
                $this->sendSynchronousResponse($msg, false);

            $response = $messageService->getResponseContents()->message ?? $this->requestErrorMessage;
            $logMessage = sprintf(
                "Correlate a Message <%s> not received, because `%s`",
                $this->headers['camundaListenerMessageName'],
                $response
            );
            $this->logEvent($logMessage, ['type' => 'business', 'message' => $logMessage]);
        }
    }

    /**
     * Logging event
     * @param string $message
     * @param array $errors
     */
    public function logEvent(string $message, array $errors = []): void
    {
        Logger::stdout($message, 'input', $this->rmqConfig['queue'], $this->logOwner, empty($errors) ? 1 : 0);
        if(isset($this->rmqConfig['queueLog'])) {
            Logger::elastic('bpm',
                'in progress',
                '',
                $this->data,
                $this->headers,
                $errors,
                $this->channel,
                $this->rmqConfig['queueLog']
            );
        }
    }
}
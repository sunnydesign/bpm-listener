<?php

namespace Kubia\Camunda;

use Camunda\Entity\Request\MessageRequest;
use Camunda\Service\MessageService;
use PhpAmqpLib\Message\AMQPMessage;
use Quancy\Logger\Logger;

/**
 * Class CamundaListener
 */
class CamundaListener extends CamundaBaseConnector
{
    /** @var array */
    public $unsafeHeadersParams = [
        'camundaWorkerId',
        'camundaExternalTaskId'
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

            $mixedProcessVariablesDataParameters = array_merge($processVariablesMessage['data']['parameters'], $this->message['data']);
            $processVariablesMessage['data']['parameters'] = $mixedProcessVariablesDataParameters;
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
     * if synchronous mode
     * add correlation id and temporary queue
     * @param AMQPMessage $msg
     */
    function mixRabbitCorrelationInfo(AMQPMessage $msg): void
    {
        if($msg->has('correlation_id') && $msg->has('reply_to')) {
            $this->updatedVariables['rabbitCorrelationId'] = [
                'value' => $msg->get('correlation_id'),
                'type'  => 'string',
            ];
            $this->updatedVariables['rabbitCorrelationReplyTo'] = [
                'value' => $msg->get('reply_to'),
                'type'  => 'string',
            ];
        }
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

        // Validate message
        $this->validateMessage();

        // get process variables
        $this->getProcessVariables();

        // mix process variables with variables from rabbit mq
        $this->mixProcessVariables();

        // add correlation_id and reply_to to process variables if is synchronous request
        $this->mixRabbitCorrelationInfo($msg);

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
            Logger::log($logMessage, 'input', $this->rmqConfig['queue'], $this->logOwner, 0 );
        } else {
            $response = $messageService->getResponseContents()->message ?? $this->requestErrorMessage;
            $logMessage = sprintf(
                "Correlate a Message <%s> not received, because `%s`",
                $this->headers['camundaListenerMessageName'],
                $response
            );
            Logger::log($logMessage, 'input', $this->rmqConfig['queue'], $this->logOwner, 1 );

            // if is synchronous mode
            if($msg->has('correlation_id') && $msg->has('reply_to')) {
                $responseToSync = $this->getErrorResponseForSynchronousRequest($this->requestErrorMessage);
                $sync_msg = new AMQPMessage($responseToSync, ['correlation_id' => $msg->get('correlation_id')]);
                $msg->delivery_info['channel']->basic_publish($sync_msg, '', $msg->get('reply_to'));
            }
        }
    }
}
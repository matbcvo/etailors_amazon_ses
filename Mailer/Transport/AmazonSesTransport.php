<?php

declare(strict_types=1);

namespace MauticPlugin\AmazonSesBundle\Mailer\Transport;

use Aws\CommandPool;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\Ses\Exception\SesException;
use Aws\SesV2\SesV2Client;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;


class AmazonSesTransport  extends AbstractTransport
{
    use TokenTransportTrait;

    const MAUTIC_AMAZONSES_API_SCHEME = 'mautic+ses+api';

    const AMAZON_REGION = [
        'us-east-1'         =>  'us-east-1',
        'us-east-2'         =>  'us-west-2',
        'ap-south-1'        =>  'ap-south-1',
        'ap-northeast-2'    =>  'ap-northeast-2',
        'ap-southeast-1'    =>  'ap-southeast-1',
        'ap-southeast-2'    =>  'ap-southeast-2',
        'ap-northeast-1'    =>  'ap-northeast-1',
        'ca-central-1'      =>  'ca-central-1',
        'eu-central-1'      =>  'eu-central-1',
        'eu-west-1'         =>  'eu-west-1',
        'eu-west-2'         =>  'eu-west-2',
        'sa-east-1'         =>  'sa-east-1',
        'us-gov-west-1'     =>  'us-gov-west-1'
    ];


     const STD_HEADER_KEYS = [
        'MIME-Version',
        'received',
        'dkim-signature',
        'Content-Type',
        'Content-Transfer-Encoding',
        'To',
        'From',
        'Subject',
        'Reply-To',
        'CC',
        'BCC',
    ];

    private $enableTemplate;

    public function __construct(
        // TransportCallback $callback,
         SesV2Client $amazonclient,
         EventDispatcherInterface $dispatcher = null,
         LoggerInterface $logger = null,
         $settings = []
    ) {
        parent::__construct($dispatcher, $logger);
        $this->logger  = $logger;
        $this->client  = $amazonclient;
        $this->dispatcher = $dispatcher;
        $this->settings = $settings;

        /**
         * Since symfony/mailer is transactional by default, we need to set the max send rate to 1
         * to avoid sending multiple emails at once.
         * We are getting tokinzed emails, so there will be MaxSendRate emails per call
         * Mailer should process tokinzed emails one by one
         * This transport SHOULD NOT RUN IN PARALLEL.
         */
        $this->setMaxPerSecond(1);

    }



    public function __toString(): string
    {
        try {
            $credentials = $this->getCredentials();
        } catch (\Exception $exception) {
            $credentials = new Credentials('', '');
        }

        $parameters = http_build_query(['region' => $this->client->getRegion()]);
        return sprintf('mautic+ses+api://%s@%s', $credentials->getAccessKeyId(), $parameters);
    }





    protected function doSend(SentMessage $message): void
    {
        try {
            /*
            * Get the original email message.
            */
            $email = $message->getOriginalMessage();
            if (!$email instanceof MauticMessage) {
                throw new \Exception('Message must be an instance of '.MauticMessage::class);
            }

            $this->message = $email;

            /**
             * This array will be used to replace
             * metadata in the current message
             * in case there are failures.
             */
            $failures = [];

            /*
            * If there is an attachment, send mail using sendRawEmail method
            * SES does not support sending attachments as bulk emails
            */
            if ($email->getAttachments() || !$this->enableTemplate) {
                $commands      = [];
                foreach ($this->convertMessageToRawPayload() as $payload) {
                    $commands[] = $this->client->getCommand('sendEmail', $payload);
                }

                $pool     = new CommandPool($this->client, $commands, [
                    'concurrency' => $this->settings['maxSendRate'],
                    'fulfilled'   => function (Result $result, $iteratorId) {
                    },
                    'rejected' => function (AwsException $reason, $iteratorId) use ($commands, &$failures) {
                        $data = $commands[$iteratorId]->toArray();
                        $failed = Address::create($data['Destination']['ToAddresses'][0]);
                        array_push($failures, $failed->getAddress());
                        $this->logger->debug('Rejected: message to '.implode(',', $data['Destination']['ToAddresses']).' with reason '.$reason->getMessage());
                    },
                ]);
                $promise = $pool->promise();
                $promise->wait();
            } else {
                [$template, $payload] = $this->makeTemplateAndMessagePayload();
                $this->createSesTemplate($template);
                $results  = $this->client->sendBulkEmail($payload)->toArray();
                foreach ($results['BulkEmailEntryResults'] as $i => $result) {
                    if ('SUCCESS' != $result['Status']) {
                        //Save the position of the response, it should match the position of the email in the payload
                        $failures[] = $i;
                    }
                }
            }
            // todo queue mode enabled
            $this->processFailures($failures);
        } catch (SesException $exception) {
            $message = $exception->getAwsErrorMessage() ?: $exception->getMessage();
            $code    = $exception->getStatusCode() ?: $exception->getCode();
            throw new TransportException(sprintf('Unable to send an email: %s (code %s).', $message, $code));
        } catch (\Exception $exception) {
            $this->logger->info($exception);
            throw new TransportException(sprintf('Unable to send an email: %s .', $exception->getMessage(),$exception->getCode()));
        }
    }



    /**
     * Convert MauticMessage to JSON payload that works with RAW sends.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function convertMessageToRawPayload(): \Generator
    {
        $metadata = $this->getMetadata();

        $payload       = [];
        if (empty($metadata)) {
            $sentMessage   = clone $this->message;
            $this->logger->debug('No metadata found, sending email as raw');
            $this->addSesHeaders($payload, $sentMessage);
            $payload = [
                'Content' => [
                    'Raw' => [
                        'Data' => $sentMessage->toString(),
                    ],
                ],
                'Destination' => [
                    'ToAddresses'  => $this->stringifyAddresses($sentMessage->getTo()),
                    'CcAddresses'  => $this->stringifyAddresses($sentMessage->getCc()),
                    'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc()),
                ],
            ];
            yield $payload;
            $payload = [];
        } else {
            /**
             * This message is a tokenzied message, SES API does not support tokens in Raw Emails
             * We need to create a new message for each recipient.
             */
            $metadataSet  = reset($metadata);
            $tokens       = (!empty($metadataSet['tokens'])) ? $metadataSet['tokens'] : [];
            $mauticTokens = array_keys($tokens);
            foreach ($metadata as $recipient => $mailData) {
                $sentMessage   = clone $this->message;
                $sentMessage->clearMetadata();
                $sentMessage->updateLeadIdHash($mailData['hashId']);
                $sentMessage->to(new Address($recipient, $mailData['name'] ?? ''));
                MailHelper::searchReplaceTokens($mauticTokens, $mailData['tokens'], $sentMessage);
                $this->addSesHeaders($payload, $sentMessage);
                $payload['Destination']      = [
                    'ToAddresses'  => $this->stringifyAddresses($sentMessage->getTo()),
                    'CcAddresses'  => $this->stringifyAddresses($sentMessage->getCc()),
                    'BccAddresses' => $this->stringifyAddresses($sentMessage->getBcc()),
                ];
                $payload['Content'] = [
                    'Raw' => [
                        'Data' => $sentMessage->toString(),
                    ],
                ];
                yield $payload;
                $payload = [];
            }
        }
    }

    /**
     * Add SES supported headers to the payload.
     *
     * @param array<string, mixed> $payload
     * @param MauticMessage        $sentMessage the message to be sent
     */
    private function addSesHeaders(&$payload, MauticMessage &$sentMessage): void
    {
        $payload['FromEmailAddress'] = $sentMessage->getFrom()[0]->getAddress();
        $payload['ReplyToAddresses'] =  $this->stringifyAddresses($sentMessage->getReplyTo());

        foreach ($sentMessage->getHeaders()->all() as $header) {
            if ($header instanceof MetadataHeader) {
                $payload['EmailTags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
            } else {
                switch ($header->getName()) {
                    case 'X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS':
                        $payload['FeedbackForwardingEmailAddress'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'X-SES-FEEDBACK-FORWARDNG-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FeedbackForwardingEmailAddressIdentityArn'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    case 'X-SES-FROM-EMAIL-ADDRESS-IDENTITYARN':
                        $payload['FromEmailAddressIdentityArn'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                    /**
                     * https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-sesv2-2019-09-27.html#sendemail
                     * ListManagementOptions is stopped intentionally because Mautic is managing this.
                     */
                    case 'X-SES-CONFIGURATION-SET':
                        $payload['ConfigurationSetName'] = $header->getBodyAsString();
                        $sentMessage->getHeaders()->remove($header->getName());
                        break;
                }
            }
        }
    }

    /**
     * @param array<string|int, mixed> $failures
     */
    private function processFailures(array $failures): void
    {
        if (empty($failures)) {
            return;
        }
        //Make a copy of the metadata
        $metadata = $this->getMetadata();
        $keys     = array_keys($metadata);

        //Clear the metadata
        $this->message->clearMetadata();

        //Add the metadata for the failed recipients

        if(!empty($metadata)){
            foreach ($failures as $failure) {
                if (is_int($failure)) {
                    $this->message->addMetadata($keys[$failure], $metadata[$keys[$failure]]);
                } else {
                    $this->message->addMetadata($failure, $metadata[$failure]);
                }
            }
        }


        $this->logger->debug('There are partial failures, replacing metadata, and failing the message');
        /*
            The message that failed will be retried with only the failed recipients
            This transport assume that the queue mode is enabled
        */

        throw new \Exception('There are  '.count($failures).' partial failures, check logs for exception reasons');
    }


    public function getMetadata()
    {
        return ($this->message instanceof MauticMessage) ? $this->message->getMetadata() : [];
    }

    protected function getCredentials()
    {
        return $this->client->getCredentials()->wait();
    }

    public function getMaxBatchLimit(): int
    {
        return 5000;
    }


}
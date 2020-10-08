<?php

namespace Gcoop\Log;

use GuzzleLogMiddleware\Handler\HandlerInterface;
use GuzzleLogMiddleware\Handler\LogLevelStrategy\ThresholdStrategy;
use GuzzleLogMiddleware\Handler\LogLevelStrategy\LogLevelStrategyInterface;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * @author Jose C. Massón <jose@gcoop.coop>
 * Creamos esta clase solo para no usar ArrayHandler
 * Modificamos el método formatBody()
 */
final class GcoopArrayHandler implements HandlerInterface
{
    /**
     * @var LogLevelStrategyInterface
     */
    private $logLevelStrategy;

    /**
     * @param LogLevelStrategyInterface|null $logLevelStrategy
     */
    public function __construct(LogLevelStrategyInterface $logLevelStrategy = null)
    {
        $this->logLevelStrategy = $logLevelStrategy === null ? new LogLevelStrategy() : $logLevelStrategy;
    }

    /**
     * @param LoggerInterface $logger
     * @param \Exception|TransferStats|MessageInterface $value
     * @param array $options
     * @return void
     */
    public function log(LoggerInterface $logger, $value, array $options = [])
    {
        if ($value instanceof ResponseInterface) {
            $context['response']['headers'] = $value->getHeaders();
            $context['response']['statusCode'] = $value->getStatusCode();
            $context['response']['version'] = 'HTTP/' . $value->getProtocolVersion();
            $context['response']['message'] = $value->getReasonPhrase();

            if ($value->getBody()->getSize() > 0) {
                $context['response']['body'] = $this->formatBody($value, $options);
            }

            $level = $this->logLevelStrategy->getLevel($value, $options);
            $logger->log($level, 'Guzzle HTTP response', $context);
            return;
        }

        if ($value instanceof RequestInterface) {
            $context['request']['method'] = $value->getMethod();
            $context['request']['headers'] = $value->getHeaders();
            $context['request']['uri'] = $value->getRequestTarget();
            $context['request']['version'] = 'HTTP/' . $value->getProtocolVersion();

            if ($value->getBody()->getSize() > 0) {
                $context['request']['body'] = $this->formatBody($value, $options);
            }

            $level = $this->logLevelStrategy->getLevel($value, $options);
            $logger->log($level, 'Guzzle HTTP request', $context);
            return;
        }

        if ($value instanceof \Exception) {
            $context['reason']['code'] = $value->getCode();
            $context['reason']['message'] = $value->getMessage();
            $context['reason']['line'] = $value->getLine();
            $context['reason']['file'] = $value->getFile();

            $level = $this->logLevelStrategy->getLevel($value, $options);
            $logger->log($level, 'Guzzle HTTP exception', $context);
            return;
        }

        if ($value instanceof TransferStats) {
            $logger->debug('Guzzle HTTP statistics', [
                'time' => $value->getTransferTime(),
                'uri' => $value->getEffectiveUri(),
            ]);

            return;
        }

        throw new \RuntimeException();
    }

    /**
     * @param MessageInterface $message
     * @param array $options
     * @return string|array
     */
    private function formatBody(MessageInterface $message, array $options)
    {
        $stream = $message->getBody();


        if ($stream->isSeekable() === false || $stream->isReadable() === false) {
            return 'Body stream is not seekable/readable.';
        }

        if (isset($options['log']['sensitive']) && $options['log']['sensitive'] === true) {
            return 'Body contains sensitive information therefore it is not included.';
        }

        if ($stream->getSize() >= 3500) {
            return $stream->read(200) . ' (truncated...)';
        }
        $body = $message->getBody()->__toString();

        $isJson = preg_grep('/application\/[\w\.\+]*(json)/', $message->getHeader('Content-Type'));
        if (!empty($isJson)) {
            $body = json_decode($body, true);
        }

        $stream->rewind();
        return $body;
    }
}

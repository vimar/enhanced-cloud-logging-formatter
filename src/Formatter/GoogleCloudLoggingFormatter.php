<?php declare(strict_types=1);

/*
 * This file is part of the Enhanced Cloud Logging Formatter package.
 *
 * (c) Vincent Mariani <vincent@sleipne.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vimar\EnhancedCloudLoggingFormatter\Formatter;

use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Encodes message information into JSON in a format compatible with Cloud logging.
 * Add Error Reporting entry if level >= $errorReportingLevel (default: Logger::ERROR)
 *
 * @see https://cloud.google.com/logging/docs/structured-logging
 * @see https://cloud.google.com/logging/docs/reference/v2/rest/v2/LogEntry
 *
 */
class GoogleCloudLoggingFormatter extends JsonFormatter
{
    protected int $batchMode;

    protected bool $appendNewline;

    protected bool $ignoreEmptyContextAndExtra;

    protected bool $includeStacktraces = false;

    protected Level $errorReportingLevel = Level::Error;

    protected string $service = '';

    protected string $version = '';

    static protected ?string $requestId = null;

    /**
     * @param int self::BATCH_MODE_* $batchMode
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = true,
        bool $includeStacktraces = true,
        Level $errorReportingLevel =  Level::Error,
        string $service =  '',
        string $version =  '',
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);
        $this->errorReportingLevel = $errorReportingLevel;
        $this->service = $service;
        $this->version = $version;

        if (!static::$requestId) {
            static::$requestId = uniqid(date("Y/m/d-H:i:s-"));
        }
    }

    protected function normalizeRecord(LogRecord $record): array
    {
        /** @var array<mixed> $normalized */
        $normalized = $this->normalize($record->toArray());

        // Re-key level for GCP logging
        $normalized['severity'] = $normalized['level_name'];
        $normalized['time'] = (new \DateTime($normalized['datetime']))->format(\DateTimeInterface::RFC3339_EXTENDED);

        // move all context properties to "jsonPayload"
        if (isset($normalized['context']) && is_array($normalized['context'])) {
            $normalized = array_merge($normalized, $normalized['context']);
            unset($normalized['context']);
        }

        // Add some generic contexts
        $normalized = $this->setHttpRequest($normalized);
        $normalized = $this->setReportError($normalized);

        if (php_sapi_name()=='cli' && isset($_SERVER['argv'])) {
            $normalized['scriptCommand'] = implode(' ', $_SERVER['argv']);
        }

        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $normalized['scriptFileName'] = $_SERVER['SCRIPT_FILENAME'];
        }

        // Remove keys that are not used by GCP
        unset($normalized['level'], $normalized['level_name'], $normalized['datetime']);

        return $normalized;
    }

    protected function setHttpRequest(array $record): array
    {
        if (isset($_SERVER['REQUEST_METHOD']) && isset($_SERVER['REQUEST_URI'])) {
            $record['httpRequest'] = [
                'requestMethod' => $_SERVER['REQUEST_METHOD'],
                'requestUrl' => sprintf(
                    '%s://%s%s',
                    $_SERVER['REQUEST_SCHEME'],
                    $_SERVER['HTTP_HOST'],
                    $_SERVER['REQUEST_URI']
                ),
            ];

            if (isset($_SERVER['HTTP_REFERER'])) {
                $record['httpRequest']['referer'] = $_SERVER['HTTP_REFERER'];
            }

            $clientIp = $this->getClientIp();
            if (!empty($clientIp)) {
                $record['httpRequest']['remoteIp'] = $clientIp;
            }

            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $record['httpRequest']['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
            }

            if (isset($_SERVER['SERVER_PROTOCOL'])) {
                $record['httpRequest']['protocol'] = $_SERVER['SERVER_PROTOCOL'];
            }
        }

        // as httpRequest can't have custom properties, we put requestId at root
        $record['requestId'] = static::$requestId;

        return $record;
    }

    protected function setReportError(array $record): array
    {
        if ($record['level'] >= $this->errorReportingLevel) {
            if (isset($record['exception']) && $record['exception'] instanceof \Throwable) {
                $ex = $record['exception'];
            } else {
                $ex = $record['exception'] = new \Exception($record['message']);
            }

            $trace = $ex->getTrace();

            $functionName = '';
            if (isset($trace[0])) {
                $function = $trace[0];

                if (isset($function['class'])) {
                    $functionName = $function['class'] . '::';
                }
                if (isset($function['function'])) {
                    $functionName .= $function['function'];
                }
            }

            /**
             * We need to declare a specific context property because it's part of ErrorContext structure
             *
             * @see https://cloud.google.com/error-reporting/docs/formatting-error-messages
             * @see https://cloud.google.com/error-reporting/reference/rest/v1beta1/ErrorContext
             */
            if (isset($record['httpRequest'])) {
                $record['context']['httpRequest'] = $record['httpRequest'];
            }

            $record['context']['reportLocation'] = [
                'filePath'   => $ex->getFile(),
                'functionName' => $functionName,
                'lineNumber' => $ex->getLine(),
            ];

            if ($this->service || $this->version) {
                $record['serviceContext'] = [
                    'service'   => $this->service,
                    'version' => $this->version,
                ];
            }

            $record['@type'] = 'type.googleapis.com/google.devtools.clouderrorreporting.v1beta1.ReportedErrorEvent';
        }

        return $record;
    }

    /**
     * Returns the client IP address that made the request.
     *
     * @param  boolean $proxy Whether the current request has been made behind a proxy or not
     *
     * @return string Client IP(s)
     */
    protected function getClientIp()
    {
        if (isset($_SERVER["HTTP_CLIENT_IP"]) && (!empty($_SERVER["HTTP_CLIENT_IP"]))) {
            return $_SERVER["HTTP_CLIENT_IP"];
        }

        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && (!empty($_SERVER["HTTP_X_FORWARDED_FOR"]))) {
            $ips = explode(',', str_replace(' ', '', $_SERVER['HTTP_X_FORWARDED_FOR']));

            if (isset($ips[0])) {
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'];
    }
}

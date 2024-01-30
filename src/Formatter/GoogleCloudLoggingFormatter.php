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
use Monolog\Logger;

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
    /** @var self::BATCH_MODE_* */
    protected $batchMode;
    /** @var bool */
    protected $appendNewline;
    /** @var bool */
    protected $ignoreEmptyContextAndExtra;
    /** @var bool */
    protected $includeStacktraces = false;
    /** @var int */
    protected $errorReportingLevel = Logger::ERROR;
    /** @var string */
    protected $service = '';
    /** @var string */
    protected $version = '';

    static protected $requestId = null;

    /**
     * @param self::BATCH_MODE_* $batchMode
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = true,
        bool $includeStacktraces = true,
        int $errorReportingLevel =  Logger::ERROR,
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

    /** {@inheritdoc} **/
    public function format(array $record): string
    {
        // Re-key level for GCP logging
        $record['severity'] = $record['level_name'];
        $record['time'] = $record['datetime']->format(\DateTimeInterface::RFC3339_EXTENDED);

        // move all context properties to "jsonPayload"
        if (isset($record['context']) && is_array($record['context'])) {
            $record = array_merge($record, $record['context']);
            unset($record['context']);
        }

        // Add some generic contexts
        $record = $this->setHttpRequest($record);
        $record = $this->setReportError($record);

        if (php_sapi_name()=='cli' && isset($_SERVER['argv'])) {
            $record['scriptCommand'] = implode(' ', $_SERVER['argv']);
        }

        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $record['scriptFileName'] = $_SERVER['SCRIPT_FILENAME'];
        }

        // Remove keys that are not used by GCP
        unset($record['level'], $record['level_name'], $record['datetime']);
        
        return parent::format($record);
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

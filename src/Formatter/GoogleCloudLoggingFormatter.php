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
 * Add Error Reporting entry if level >= ERROR
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

    static protected $requestId = null;

    /**
     * @param self::BATCH_MODE_* $batchMode
     */
    public function __construct(
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true,
        bool $ignoreEmptyContextAndExtra = true,
        bool $includeStacktraces = true
    ) {
        parent::__construct($batchMode, $appendNewline, $ignoreEmptyContextAndExtra, $includeStacktraces);

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

        // Add some generic contexts
        $record = $this->setHttpRequest($record);
        $record = $this->setReportError($record);

        // Remove keys that are not used by GCP
        unset($record['channel'], $record['level'], $record['level_name'], $record['datetime']);
        
        return parent::format($record);
    }

    protected function setHttpRequest(array $record): array
    {
        // HttpRequest;
        if (isset($_SERVER['REQUEST_METHOD']) && isset($_SERVER['REQUEST_URI'])) {
            $record['context']['httpRequest'] = [
                'requestMethod' => $_SERVER['REQUEST_METHOD'],
                'requestUrl' => $_SERVER['REQUEST_URI'],
            ];
        }

        $record['context']['labels']['channel'] = $record['channel'];
        $record['context']['labels']['requestId'] = static::$requestId;

        return $record;
    }

    protected function setReportError(array $record): array
    {
        if (isset($record['context']['exception']) && $record['context']['exception'] instanceof \Throwable) {
            $ex = $record['context']['exception'];
        } else {
            $ex = new \Exception($record['message']);
        }

        if (isset($record['context']['exception'])) {
            $ex = $record['context']['exception'];
        }

        if ($record['level'] >= Logger::ERROR) {
            $record['context']['reportLocation'] = [
                'filePath'   => $ex->getFile(),
                'lineNumber' => $ex->getLine(),
            ];

            $record['@type'] = 'type.googleapis.com/google.devtools.clouderrorreporting.v1beta1.ReportedErrorEvent';
        }

        return $record;
    }
}

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
    /** {@inheritdoc} **/
    public function format(array $record): string
    {
        // Re-key level for GCP logging
        $record['severity'] = $record['level_name'];
        $record['time'] = $record['datetime']->format(\DateTimeInterface::RFC3339_EXTENDED);

        if ($record['level'] >= Logger::ERROR) {
            $record = $this->setReportError($record);
        }

        // Remove keys that are not used by GCP
        unset($record['level'], $record['level_name'], $record['datetime']);

        return parent::format($record);
    }

    protected function setReportError(array $record): array
    {
        $ex = new \Exception($record['message']);

        if (isset($record['context']['exception'])) {
            $ex = $record['context']['exception'];
        }

        $record['context']['reportLocation'] = [
            'filePath'   => $ex->getFile(),
            'lineNumber' => $ex->getLine(),
        ];

        $record['context']['@type'] = 'type.googleapis.com/google.devtools.clouderrorreporting.v1beta1.ReportedErrorEvent';

        return $record;
    }
}
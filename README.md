# Enhanced Cloud Logging Formatter v3.x

[![Total Downloads](https://img.shields.io/packagist/dt/vimar/enhanced-cloud-logging-formatter.svg)](https://packagist.org/packages/vimar/enhanced-cloud-logging-formatter)
[![Latest Stable Version](https://img.shields.io/packagist/v/vimar/enhanced-cloud-logging-formatter.svg)](https://packagist.org/packages/vimar/enhanced-cloud-logging-formatter)

>**Note** v3.x version is only compatible with Monolog 3.x, please use [v2.x](https://github.com/vimar/enhanced-cloud-logging-formatter/blob/2.x/README.md) if you use Monolog 2.x.

The `Enhanced Cloud Logging Formatter` is a custom formatter for Monolog that replaces the default `GoogleCloudLoggingFormatter` provided by Monolog. This formatter is designed to be used when you want to send logs from your PHP application to Google Cloud Logging.

## Features

- **Error Reporting**: The `GoogleCloudLoggingFormatter` enables error reporting, allowing you to control the reporting of error events to Google Cloud Platform (GCP). The `errorReportingLevel` parameter can be modified to specify the minimum log level that triggers error reporting.

- **Complete Log Metadata**: This formatter completes log metadata, providing additional context information before sending logs to GCP. This ensures that logs include comprehensive details, making it easier to analyze and debug issues. Additional metadata contains:
  - Unique RequestId to easily search all the logs for a single HTTP request
  - Exhaustive HTTP info for each log entries, such as request Url, referer, remote IP and User Agent
  - For CLI commands, script command and script filename called
  - When an error is thrown, add information about error context (report location, stack trace, exception message, service name and version number if they are provided)

## Installation

You can install the `Enhanced Cloud Logging Formatter` via Composer:

```bash
composer require vimar/enhanced-cloud-logging-formatter
```

## Basic Usage

To use the `GoogleCloudLoggingFormatter`, you need to configure Monolog in your application.

```php
<?php

require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Vimar\EnhancedCloudLoggingFormatter\Formatter\GoogleCloudLoggingFormatter;

// Create a logger
$log = new Logger('my_logger');

// Create a handler with GoogleCloudLoggingFormatter
$handler = new StreamHandler('php://stdout');
$handler->setFormatter(new GoogleCloudLoggingFormatter());

// Add the handler to the logger
$log->pushHandler($handler);

// Example logs
$log->info('This is an informational message.');
$log->error('An error occurred!');
```

In this example, logs are sent to the standard output (`php://stdout`) using a StreamHandler with the `GoogleCloudLoggingFormatter`. Adjust the handler configuration based on your specific logging needs.

## Configuration Options

The GoogleCloudLoggingFormatter replaces the default Google Cloud Logging formatter from Monolog and supports the following configuration option:

- `errorReportingLevel` - The minimum log level that triggers error reporting.
- `service` - GCP custom service name.
- `version` - GCP custom version number.

```php
new GoogleCloudLoggingFormatter(
    errorReportingLevel: Logger::CRITICAL,
    service: 'myService',
    'version': '1.2.3'
)
```

## Contributing

If you encounter issues or have suggestions for improvements, feel free to contribute to the project. Submit bug reports or feature requests through the GitHub repository.

## License

The GoogleCloudLoggingFormatter is open-source software licensed under the MIT License. Feel free to use, modify, and distribute it in your projects.

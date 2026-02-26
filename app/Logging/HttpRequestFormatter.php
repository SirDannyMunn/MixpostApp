<?php

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class HttpRequestFormatter extends LineFormatter
{
    public function __construct()
    {
        // allowInlineLineBreaks=true, ignoreEmptyContextAndExtra=true for clean output
        parent::__construct(null, 'Y-m-d H:i:s', true, true);
    }

    public function format(LogRecord $record): string
    {
        $datetime = ($record->datetime instanceof \DateTimeInterface)
            ? $record->datetime->format('Y-m-d H:i:s')
            : date('Y-m-d H:i:s');

        $level = method_exists($record->level, 'getName') ? $record->level->getName() : 'INFO';
        $channel = $record->channel ?? 'http';
        $message = (string) ($record->message ?? 'HTTP request');
        $context = $record->context ?? [];

        $lines = [];
        $lines[] = "[{$datetime}] {$level}.{$channel}";
        $lines[] = $message;

        // Core fields
        $lines[] = 'Method: ' . ($context['method'] ?? '-');
        $lines[] = 'URL: ' . ($context['url'] ?? '-');
        $lines[] = 'IP: ' . ($context['ip'] ?? '-') . ' | User: ' . ($context['user_id'] ?? '-');
        $lines[] = 'Status: ' . ($context['status'] ?? '-') . ' | Duration: ' . ($context['duration_ms'] ?? '-') . 'ms';

        // Headers (sanitized upstream)
        if (isset($context['headers']) && is_array($context['headers']) && !empty($context['headers'])) {
            $lines[] = 'Headers:';
            foreach ($context['headers'] as $key => $value) {
                $rendered = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                $lines[] = '  - ' . $key . ': ' . $rendered;
            }
        }

        // Body (sanitized upstream)
        if (array_key_exists('body', $context)) {
            $lines[] = 'Body:';
            $body = $context['body'];
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json !== false) {
                foreach (explode("\n", $json) as $jl) {
                    $lines[] = '  ' . $jl;
                }
            } else {
                // Fallback rendering
                $export = var_export($body, true);
                foreach (explode("\n", $export) as $el) {
                    $lines[] = '  ' . $el;
                }
            }
        }

        // Response data
        if (array_key_exists('response', $context) && is_array($context['response'])) {
            $response = $context['response'];
            
            $lines[] = 'Response:';
            $lines[] = '  Status: ' . ($response['status'] ?? '-');
            
            // Response headers
            if (isset($response['headers']) && is_array($response['headers']) && !empty($response['headers'])) {
                $lines[] = '  Headers:';
                foreach ($response['headers'] as $key => $value) {
                    $rendered = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
                    $lines[] = '    - ' . $key . ': ' . $rendered;
                }
            }
            
            // Response body
            if (array_key_exists('body', $response)) {
                $lines[] = '  Body:';
                $respBody = $response['body'];
                
                if (is_string($respBody)) {
                    // Try to parse as JSON if it's a string
                    $jsonDecoded = json_decode($respBody, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $respBody = $jsonDecoded;
                    }
                }
                
                $json = json_encode($respBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if ($json !== false) {
                    foreach (explode("\n", $json) as $jl) {
                        $lines[] = '    ' . $jl;
                    }
                } else {
                    // Fallback for non-JSON responses
                    if (is_string($respBody)) {
                        foreach (explode("\n", $respBody) as $bl) {
                            $lines[] = '    ' . $bl;
                        }
                    } else {
                        $export = var_export($respBody, true);
                        foreach (explode("\n", $export) as $el) {
                            $lines[] = '    ' . $el;
                        }
                    }
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }
}

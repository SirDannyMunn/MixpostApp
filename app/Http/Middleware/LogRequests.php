<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogRequests
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        try {
            $durationMs = (int) round((microtime(true) - $start) * 1000);

            $summary = sprintf('%s %s', $request->method(), $request->fullUrl());
            
            // Debug: capture raw input for POST requests with empty parsed body
            $rawInput = null;
            $parsedBody = $request->all();
            if (empty($parsedBody) && $request->isMethod('POST')) {
                $rawInput = $request->getContent();
                if (strlen($rawInput) > 5000) {
                    $rawInput = substr($rawInput, 0, 5000) . '... [truncated]';
                }
            }
            
            Log::channel('http')->info($summary, [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'user_id' => optional($request->user())->id,
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'headers' => $this->sanitizeHeaders($request->headers->all()),
                'body' => $this->sanitizeBody($parsedBody),
                'raw_input' => $rawInput,
                'response' => $this->extractResponse($response),
            ]);
        } catch (\Throwable $e) {
            // Never break requests due to logging issues
            Log::warning('[HttpLog] Failed to log request', [
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    protected function sanitizeHeaders(array $headers): array
    {
        $redactHeaders = [
            'authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token', 'set-cookie',
        ];

        $sanitized = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower($key);
            if (in_array($lower, $redactHeaders, true)) {
                $sanitized[$key] = ['[redacted]'];
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    protected function sanitizeBody(array $input): array
    {
        $redactKeys = [
            'password', 'password_confirmation', 'current_password', 'token', '_token',
            'api_key', 'secret', 'session', 'remember_token', 'access_token', 'refresh_token',
        ];

        $walker = function ($value, $key) use (&$walker, $redactKeys) {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $k => $v) {
                    $result[$k] = $walker($v, $k);
                }
                return $result;
            }

            if (in_array(strtolower((string) $key), $redactKeys, true)) {
                return '[redacted]';
            }

            if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                return '[uploaded_file]';
            }

            return $value;
        };

        $result = [];
        foreach ($input as $k => $v) {
            $result[$k] = $walker($v, $k);
        }

        return $result;
    }

    protected function extractResponse($response): array
    {
        $data = [
            'status' => $response->getStatusCode(),
            'headers' => [],
            'body' => null,
        ];

        // Extract headers
        foreach ($response->headers->all() as $key => $values) {
            $data['headers'][$key] = $values;
        }

        // Extract body (limit size to prevent massive logs)
        try {
            $content = $response->getContent();
            if (strlen($content) > 100000) {
                // Truncate large responses
                $data['body'] = substr($content, 0, 100000) . '... [truncated]';
            } else {
                // Try to decode JSON for better formatting
                $json = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data['body'] = $json;
                } else {
                    $data['body'] = $content;
                }
            }
        } catch (\Throwable $e) {
            $data['body'] = '[error extracting body: ' . $e->getMessage() . ']';
        }

        return $data;
    }
}

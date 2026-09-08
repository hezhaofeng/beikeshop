<?php

namespace Plugin\PaypalB\Services;

use Illuminate\Http\Client\Response;

final class PaypalBApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly string $paypalName = '',
        public readonly array $issues = [],
        public readonly bool $retryable = false,
        \Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response, string $message): self
    {
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $issues  = [];
        foreach ((array) ($payload['details'] ?? []) as $detail) {
            $issue = trim((string) ($detail['issue'] ?? ''));
            if ($issue !== '') {
                $issues[] = $issue;
            }
        }

        $description = trim((string) data_get($payload, 'details.0.description', $payload['message'] ?? ''));
        $fullMessage = trim($message . ($description !== '' ? ' ' . $description : ''));
        $status      = $response->status();
        $retryable   = in_array($status, [408, 409, 425, 429], true)
            || ($status >= 500 && $status <= 599);

        return new self(
            $fullMessage,
            $status,
            trim((string) ($payload['name'] ?? '')),
            array_values(array_unique($issues)),
            $retryable,
        );
    }

    public static function connection(string $message, \Throwable $previous): self
    {
        return new self($message, null, 'CONNECTION_ERROR', [], true, $previous);
    }

    public static function configuration(string $message): self
    {
        return new self($message, null, 'CONFIGURATION_ERROR');
    }

    public static function malformedResponse(string $message, int $httpStatus = null): self
    {
        return new self($message, $httpStatus, 'MALFORMED_RESPONSE', [], true);
    }

    public function failureClass(): string
    {
        if ($this->paypalName === 'CONFIGURATION_ERROR') {
            return 'configuration';
        }
        if ($this->paypalName === 'MALFORMED_RESPONSE') {
            return 'provider_unknown';
        }
        if ($this->httpStatus === null) {
            return 'connection';
        }
        if (in_array($this->httpStatus, [408, 409, 425, 429], true)) {
            return 'provider_transient';
        }

        return $this->retryable ? 'provider_5xx' : 'provider_rejected';
    }
}

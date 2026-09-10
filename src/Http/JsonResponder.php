<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Minimal JSON response builder for scheduler HTTP endpoints.
 *
 * Wraps responses in the same envelope as api.masiarek.pl's JsonHandler
 * so that the output is structurally compatible when migrating.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class JsonResponder
{
    /**
     * Write a successful JSON response.
     *
     * @param ResponseInterface   $response
     * @param string              $message
     * @param array<string,mixed> $data
     * @param int                 $status
     * @return ResponseInterface
     */
    public static function ok(
        ResponseInterface $response,
        string $message,
        array $data = [],
        int $status = 200
    ): ResponseInterface {
        $body = \json_encode(
            \array_merge(['ok' => true, 'message' => $message], $data),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
        );

        $response->getBody()->write($body);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Write an error JSON response.
     *
     * @param ResponseInterface $response
     * @param string            $message
     * @param int               $status
     * @param string|null       $error    Machine-readable error code.
     * @return ResponseInterface
     */
    public static function error(
        ResponseInterface $response,
        string $message,
        int $status = 500,
        ?string $error = null
    ): ResponseInterface {
        $payload = ['ok' => false, 'message' => $message];
        if ($error !== null) {
            $payload['error'] = $error;
        }

        $body = \json_encode(
            $payload,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
        );

        $response->getBody()->write($body);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}

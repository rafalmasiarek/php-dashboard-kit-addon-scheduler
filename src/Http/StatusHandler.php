<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitScheduler\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use rafalmasiarek\DashboardKitScheduler\Clock;
use rafalmasiarek\DashboardKitScheduler\Scheduler;

/**
 * GET /cron/status — public endpoint.
 *
 * Lists all registered tasks with their cron expression, last_run_at and is_due flag.
 *
 * @package rafalmasiarek\DashboardKitScheduler
 */
final class StatusHandler
{
    /**
     * @var Scheduler
     */
    private Scheduler $scheduler;

    /**
     * @var Clock
     */
    private Clock $clock;

    /**
     * @param Scheduler $scheduler
     * @param Clock     $clock
     */
    public function __construct(Scheduler $scheduler, Clock $clock)
    {
        $this->scheduler = $scheduler;
        $this->clock     = $clock;
    }

    /**
     * Handle the status request.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface      $response
     * @return ResponseInterface
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now  = $this->clock->now();
        $meta = $this->scheduler->describeTasks($now);

        $items = [];
        foreach ($meta as $taskName => $info) {
            $items[] = [
                'id'          => $taskName,
                'expression'  => $info['expression'],
                'is_due'      => $info['is_due'],
                'last_run_at' => $info['last_run_at'],
            ];
        }

        return JsonResponder::ok($response, 'Scheduler tasks status.', [
            'items'       => $items,
            'server_time' => $now->format(\DATE_ATOM),
        ]);
    }
}

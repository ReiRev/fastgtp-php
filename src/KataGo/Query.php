<?php

declare(strict_types=1);

namespace FastGtp\KataGo;

use FastGtp\FastGtpException;
use FastGtp\HttpTransporter;

/** One KataGo analysis query on the server. */
final class Query
{
    private readonly string $path;

    /** @internal Use KataGo::createQuery() or KataGo::query(). */
    public function __construct(
        private readonly HttpTransporter $transporter,
        string $katagoPath,
        public readonly string $id,
    ) {
        $this->path = $katagoPath . '/queries/' . HttpTransporter::segment($id);
    }

    /** The query as it is now. */
    public function get(): QueryResult
    {
        return $this->transporter->read('GET', $this->path, null, QueryResult::fromResponse(...));
    }

    /**
     * Wait until the query has SUCCEEDED or FAILED, reading it as often as the server's Retry-After asks.
     *
     * A query KataGo rejects comes back FAILED with KataGo's error, not as an
     * exception. If it does not finish in time, it is deleted, which stops
     * it, and a TimeoutException is thrown.
     */
    public function wait(float $timeoutSeconds = 600.0): QueryResult
    {
        $started = hrtime(true);
        // Sleep the clock did not see, so a test's fake sleep still uses up the time.
        $unseen = 0.0;
        $current = $this->get();
        while (!$current->isFinished()) {
            $remaining = $timeoutSeconds - (hrtime(true) - $started) / 1e9 - $unseen;
            if ($remaining <= 0) {
                try {
                    $this->delete();
                } catch (FastGtpException $e) {
                    // It may have finished and been removed in the meantime.
                    if (!$e->is('query-not-found')) {
                        throw $e;
                    }
                }
                throw new TimeoutException("KataGo query {$this->id} did not finish in {$timeoutSeconds} seconds.");
            }
            // Use the time that is left, and read once more at the deadline,
            // so a query that finishes just in time is not thrown away.
            $wait = min((float) ($current->retryAfter ?? 1), $remaining);
            $before = hrtime(true);
            $this->transporter->sleep($wait);
            $unseen += max(0.0, $wait - (hrtime(true) - $before) / 1e9);
            $current = $this->get();
        }

        return $current;
    }

    /** Remove the query. KataGo stops it first if it has not finished. */
    public function delete(): void
    {
        $this->transporter->send('DELETE', $this->path);
    }
}

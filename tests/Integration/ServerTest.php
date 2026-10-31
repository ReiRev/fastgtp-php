<?php

declare(strict_types=1);

namespace FastGtp\Tests\Integration;

use FastGtp\FastGtpException;
use FastGtp\HttpTransporter;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a fastgtp server named by FASTGTP_URL, with a GTP engine and
 * a KataGo named by FASTGTP_KATAGO (default "default"). CI starts one with
 * GNU Go and KataGo's CPU build.
 *
 * Until the client's public classes exist, these tests send requests through
 * HttpTransporter, which every public class will use.
 */
final class ServerTest extends TestCase
{
    private const GAME = ['rules' => 'japanese', 'komi' => 6.5, 'boardXSize' => 9, 'boardYSize' => 9];

    private static SpecCheckingHttp $http;

    private static HttpTransporter $transporter;

    private static string $katago;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('FASTGTP_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set FASTGTP_URL to run the integration tests.');
        }
        $guzzle = new GuzzleClient(['timeout' => 60, 'http_errors' => false]);
        $spec = json_decode((string) $guzzle->get(rtrim($url, '/') . '/openapi.json')->getBody(), true);
        self::$http = new SpecCheckingHttp($guzzle, $spec);
        $factory = new HttpFactory();
        self::$transporter = new HttpTransporter($url, self::$http, $factory, $factory);
        self::$katago = getenv('FASTGTP_KATAGO') ?: 'default';
    }

    public function testGame(): void
    {
        $t = self::$transporter;
        $path = '/sessions/' . $t->json('POST', '/sessions')['session_id'];
        try {
            $t->json('PUT', "$path/board", ['size' => 9, 'komi' => 6.5]);
            $this->assertSame('E5', $t->json('POST', "$path/moves", ['color' => 'BLACK', 'vertex' => 'E5'])['vertex']);
            try {
                $t->json('POST', "$path/moves", ['color' => 'WHITE', 'vertex' => 'E5']);
                $this->fail('An occupied point was accepted');
            } catch (FastGtpException $e) {
                $this->assertTrue($e->is('illegal-move'));
            }
            $this->assertSame('WHITE', $t->json('POST', "$path/moves", ['color' => 'WHITE'])['color']);
        } finally {
            $t->send('DELETE', $path);
        }
        try {
            $t->json('GET', $path);
            $this->fail('The session was not deleted');
        } catch (FastGtpException $e) {
            $this->assertTrue($e->is('session-not-found'));
        }
    }

    public function testKataGoAnalysis(): void
    {
        $t = self::$transporter;
        $katago = '/katago/' . self::$katago;
        $this->assertContains(['id' => self::$katago], $t->json('GET', '/katago')['items']);
        $this->assertNotSame('', $t->json('GET', "$katago/version")['version']);

        $query = $t->json('POST', "$katago/queries", self::GAME + [
            'moves' => [['B', 'E5'], ['W', 'C3']],
            'analyzeTurns' => [0, 2],
            'maxVisits' => 10,
        ]);
        $deadline = microtime(true) + 120;
        while (!in_array($query['status'], ['SUCCEEDED', 'FAILED'], true) && microtime(true) < $deadline) {
            usleep(200_000);
            $query = $t->json('GET', "$katago/queries/{$query['id']}");
        }

        $this->assertSame('SUCCEEDED', $query['status'], json_encode($query['error'] ?? null));
        $this->assertSame([0, 2], array_column($query['responses'], 'turnNumber'));
        // KataGo's numbers change from run to run, so check only their range.
        foreach ($query['responses'] as $response) {
            $this->assertGreaterThanOrEqual(0.0, $response['rootInfo']['winrate']);
            $this->assertLessThanOrEqual(1.0, $response['rootInfo']['winrate']);
            $this->assertNotEmpty($response['moveInfos']);
        }
    }

    public function testKataGoRejectsAnIllegalMove(): void
    {
        $t = self::$transporter;
        $katago = '/katago/' . self::$katago;
        $query = $t->json('POST', "$katago/queries", self::GAME + ['moves' => [['B', 'E5'], ['W', 'E5']]]);
        $deadline = microtime(true) + 60;
        while ($query['status'] !== 'FAILED' && microtime(true) < $deadline) {
            usleep(200_000);
            $query = $t->json('GET', "$katago/queries/{$query['id']}");
        }

        $this->assertSame('FAILED', $query['status']);
        $this->assertSame('moves', $query['error']['field']);
    }

    public function testEveryResponseWasChecked(): void
    {
        // The other tests ran first; each of their responses passed the spec check.
        $this->assertGreaterThan(8, count(self::$http->checked));
    }
}

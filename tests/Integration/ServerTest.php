<?php

declare(strict_types=1);

namespace FastGtp\Tests\Integration;

use FastGtp\Client;
use FastGtp\Color;
use FastGtp\FastGtpException;
use FastGtp\HttpTransporter;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a fastgtp server named by FASTGTP_URL, with a GTP engine and
 * a KataGo named by FASTGTP_KATAGO (default "default"). tests/server/compose.yaml
 * starts one with GNU Go and KataGo's CPU build.
 *
 * SpecCheckingHttp throws on any response that does not match the spec.
 *
 * The KataGo tests send requests through HttpTransporter until the client
 * has KataGo classes.
 */
final class ServerTest extends TestCase
{
    private const GAME = ['rules' => 'japanese', 'komi' => 6.5, 'boardXSize' => 9, 'boardYSize' => 9];

    private static SpecCheckingHttp $http;

    private static Client $client;

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
        self::$client = new Client($url, self::$http);
        self::$transporter = new HttpTransporter($url, self::$http, $factory, $factory);
        self::$katago = getenv('FASTGTP_KATAGO') ?: 'default';
    }

    public function testGame(): void
    {
        $session = self::$client->createSession();
        try {
            // A handle made from the id reaches the same session.
            $again = self::$client->session($session->id);
            $this->assertSame($session->id, $again->info()->id);
            $this->assertSame(9, $session->setBoard(9, 6.5)->size);
            $this->assertSame('E5', $session->play(Color::Black, 'E5')->vertex);
            try {
                $again->play(Color::White, 'E5');
                $this->fail('An occupied point was accepted');
            } catch (FastGtpException $e) {
                $this->assertTrue($e->is('illegal-move'));
            }
            $this->assertSame(Color::White, $session->generateMove(Color::White)->color);
            $session->undo();
            // Only the first move is loaded.
            $loaded = $session->loadSgf('(;GM[1]SZ[9];B[ee];W[cc])', 1)->sgf;
            $this->assertStringContainsString('ee', $loaded);
            $this->assertStringNotContainsString('cc', $loaded);
            $this->assertStringContainsString('SZ[9]', $session->board()->sgf);
        } finally {
            $session->delete();
        }
        try {
            $session->info();
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
        while (!in_array($query['status'], ['SUCCEEDED', 'FAILED'], true) && microtime(true) < $deadline) {
            usleep(200_000);
            $query = $t->json('GET', "$katago/queries/{$query['id']}");
        }

        $this->assertSame('FAILED', $query['status']);
        $this->assertSame('moves', $query['error']['field']);
    }
}

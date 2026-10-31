<?php

declare(strict_types=1);

namespace FastGtp\Tests\Integration;

use FastGtp\Client;
use FastGtp\Color;
use FastGtp\ProblemException;
use FastGtp\QueryStatus;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a fastgtp server named by FASTGTP_URL, with a GTP engine and
 * a KataGo named by FASTGTP_KATAGO (default "default"). CI starts one with
 * GNU Go and fastgtp's fake analysis engine.
 */
final class ServerTest extends TestCase
{
    private const GAME = ['rules' => 'japanese', 'komi' => 6.5, 'boardXSize' => 9, 'boardYSize' => 9];

    private static SpecCheckingHttp $http;

    private static Client $client;

    private static string $katago;

    public static function setUpBeforeClass(): void
    {
        $url = getenv('FASTGTP_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set FASTGTP_URL to run the integration tests.');
        }
        $guzzle = new GuzzleClient(['timeout' => 30, 'http_errors' => false]);
        $spec = json_decode((string) $guzzle->get(rtrim($url, '/') . '/openapi.json')->getBody(), true);
        self::$http = new SpecCheckingHttp($guzzle, $spec);
        $factory = new HttpFactory();
        self::$client = new Client($url, self::$http, $factory, $factory);
        self::$katago = getenv('FASTGTP_KATAGO') ?: 'default';
    }

    public function testGame(): void
    {
        $client = self::$client;
        $session = $client->createSession();
        try {
            $this->assertSame($session->id, $client->getSession($session->id)->id);
            $this->assertSame(9, $client->setBoard($session->id, 9, 6.5)->size);
            $this->assertSame('E5', $client->play($session->id, Color::Black, 'E5')->vertex);
            try {
                $client->play($session->id, Color::White, 'E5');
                $this->fail('An occupied point was accepted');
            } catch (ProblemException $e) {
                $this->assertTrue($e->is('illegal-move'));
            }
            $this->assertSame(Color::White, $client->generateMove($session->id, Color::White)->color);
            $client->undo($session->id);
            $this->assertStringContainsString('SZ[9]', $client->loadSgf($session->id, '(;GM[1]SZ[9];B[ee];W[cc])', 1)->sgf);
            $this->assertStringContainsString('SZ[9]', $client->getBoard($session->id)->sgf);
        } finally {
            $client->deleteSession($session->id);
        }
        try {
            $client->getSession($session->id);
            $this->fail('The session was not deleted');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('session-not-found'));
        }
    }

    public function testKataGo(): void
    {
        $client = self::$client;
        $this->assertContains(self::$katago, $client->listKataGos());
        $this->assertNotSame('', $client->getKataGoVersion(self::$katago)['version']);
        $this->assertNotEmpty($client->getKataGoModels(self::$katago));

        $query = $client->analyze(self::$katago, self::GAME + [
            'moves' => [['B', 'E5'], ['W', 'C3']],
            'analyzeTurns' => [2, 0],
            'maxVisits' => 10,
        ], 60);
        $this->assertSame(QueryStatus::Succeeded, $query->status);
        $this->assertSame([0, 2], array_column($query->responses, 'turnNumber'));

        $created = $client->createQuery(self::$katago, self::GAME + ['moves' => []]);
        $client->deleteQuery(self::$katago, $created->id);
        try {
            $client->getQuery(self::$katago, $created->id);
            $this->fail('The query was not deleted');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('query-not-found'));
        }
    }

    public function testRejections(): void
    {
        $client = self::$client;
        try {
            $client->createQuery(self::$katago, self::GAME + ['moves' => [], 'overrideSettings' => ['x' => 1]]);
            $this->fail('An unknown field was accepted');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('invalid-request'));
            $this->assertSame('overrideSettings', $e->field);
        }
        try {
            $client->getKataGoVersion('no-such-katago');
            $this->fail('An unknown KataGo was found');
        } catch (ProblemException $e) {
            $this->assertTrue($e->is('katago-not-found'));
        }
    }

    public function testEveryResponseWasChecked(): void
    {
        // The other tests ran first; each of their responses passed the spec check.
        $this->assertGreaterThan(10, count(self::$http->checked));
    }
}

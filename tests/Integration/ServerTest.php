<?php

declare(strict_types=1);

namespace FastGtp\Tests\Integration;

use FastGtp\FastGtpClient;
use FastGtp\Color;
use FastGtp\FastGtpException;
use FastGtp\KataGo\QueryStatus;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a fastgtp server named by FASTGTP_URL, with a GTP engine and
 * a KataGo named by FASTGTP_KATAGO (default "default"). tests/server/compose.yaml
 * starts one with GNU Go and KataGo's CPU build.
 *
 * SpecCheckingHttp throws on any response that does not match the spec.
 */
final class ServerTest extends TestCase
{
    private const GAME = ['rules' => 'japanese', 'komi' => 6.5, 'boardXSize' => 9, 'boardYSize' => 9];

    private static SpecCheckingHttp $http;

    private static FastGtpClient $client;

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
        self::$client = new FastGtpClient($url, self::$http);
        self::$katago = getenv('FASTGTP_KATAGO') ?: 'default';
    }

    public function testGame(): void
    {
        $session = self::$client->createSession();
        try {
            // A handle made from the id reaches the same session.
            $again = self::$client->session($session->id);
            $this->assertSame('GNU Go', $again->engine()->name);
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
            $session->engine();
            $this->fail('The session was not deleted');
        } catch (FastGtpException $e) {
            $this->assertTrue($e->is('session-not-found'));
        }
    }

    public function testKataGo(): void
    {
        $this->assertContains(self::$katago, array_map(fn($k) => $k->name, self::$client->katagos()));
        $katago = self::$client->katago(self::$katago);
        $this->assertNotSame('', $katago->version()->version);
        $this->assertNotEmpty($katago->models());
        try {
            self::$client->katago('no-such-katago')->version();
            $this->fail('An unknown KataGo was found');
        } catch (FastGtpException $e) {
            $this->assertTrue($e->is('katago-not-found'));
        }
    }

    public function testKataGoAnalysis(): void
    {
        $katago = self::$client->katago(self::$katago);
        $result = $katago->analyze(self::GAME + [
            'moves' => [['B', 'E5'], ['W', 'C3']],
            'analyzeTurns' => [0, 2],
            'maxVisits' => 10,
        ], 120);

        $this->assertSame(QueryStatus::Succeeded, $result->status, json_encode($result->error));
        $this->assertSame([0, 2], array_column($result->responses, 'turnNumber'));
        // KataGo's numbers change from run to run, so check only their range.
        foreach ($result->responses as $response) {
            $this->assertGreaterThanOrEqual(0.0, $response['rootInfo']['winrate']);
            $this->assertLessThanOrEqual(1.0, $response['rootInfo']['winrate']);
            $this->assertNotEmpty($response['moveInfos']);
        }
        $this->assertNotNull($result->finishedAt);
    }

    public function testKataGoRejectsAnIllegalMove(): void
    {
        $result = self::$client->katago(self::$katago)->analyze(self::GAME + ['moves' => [['B', 'E5'], ['W', 'E5']]], 60);

        $this->assertSame(QueryStatus::Failed, $result->status);
        $this->assertSame('moves', $result->error['field']);
    }

    public function testQueryFromIdAndDelete(): void
    {
        $katago = self::$client->katago(self::$katago);
        $id = $katago->createQuery(self::GAME + ['moves' => [], 'maxVisits' => 10])->id;

        // A handle made from the id reaches the same query.
        $query = $katago->query($id);
        $this->assertSame($id, $query->wait(60)->id);
        $query->delete();
        try {
            $query->get();
            $this->fail('The query was not deleted');
        } catch (FastGtpException $e) {
            $this->assertTrue($e->is('query-not-found'));
        }
    }
}

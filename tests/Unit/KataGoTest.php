<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\FastGtpException;
use PHPUnit\Framework\TestCase;

final class KataGoTest extends TestCase
{
    private const PROBLEMS = 'https://github.com/ReiRev/fastgtp/blob/main/docs/problems.md#';

    public function testListVersionAndModels(): void
    {
        $http = (new FakeHttp())
            ->answer(200, ['items' => [['id' => 'default'], ['id' => 'human']]])
            ->answer(200, ['version' => '1.18.0', 'git_hash' => 'abc'])
            ->answer(200, ['models' => [[
                'name' => 'm.bin.gz',
                'internalName' => 'm',
                'maxBatchSize' => 8,
                'usesHumanSLProfile' => false,
                'version' => 15,
                'usingFP16' => 'false',
                'futureField' => 1,
            ]]]);
        $client = $http->client();

        $katagos = $client->katagos();
        $this->assertSame(['default', 'human'], array_map(fn($k) => $k->name, $katagos));
        $version = $katagos[0]->version();
        $this->assertSame('1.18.0', $version->version);
        $this->assertSame('abc', $version->gitHash);
        // Fields KataGo adds later are kept.
        $this->assertSame(1, $client->katago('default')->models()[0]['futureField']);
        $this->assertSame('/katago', $http->requests[0]->getUri()->getPath());
        $this->assertSame('/katago/default/version', $http->requests[1]->getUri()->getPath());
        $this->assertSame('/katago/default/models', $http->requests[2]->getUri()->getPath());
    }

    public function testKataGoFromNameSendsNothing(): void
    {
        $http = new FakeHttp();
        $this->assertSame('a/b', $http->client()->katago('a/b')->name);
        $this->assertSame([], $http->requests);
    }

    public function testNameIsEscaped(): void
    {
        $http = (new FakeHttp())->answer(200, ['version' => '1.18.0', 'git_hash' => 'abc']);
        $http->client()->katago('a b')->version();
        $this->assertSame('/katago/a%20b/version', $http->requests[0]->getUri()->getPath());
    }

    public function testUnknownKataGo(): void
    {
        $http = (new FakeHttp())->answer(404, [
            'type' => self::PROBLEMS . 'katago-not-found',
            'title' => 'KataGo not found',
            'status' => 404,
        ]);
        try {
            $http->client()->katago('nope')->version();
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertTrue($e->is('katago-not-found'));
        }
    }

    public function testBodyUnlikeFastgtps(): void
    {
        $http = (new FakeHttp())
            ->answer(200, ['items' => [['name' => 'default']]])
            ->answer(200, ['items' => [['id' => '..']]])
            ->answer(200, ['version' => '1.18.0'])
            ->answer(200, ['models' => 'm.bin.gz']);
        $client = $http->client();
        $calls = [
            fn() => $client->katagos(),
            fn() => $client->katagos(),
            fn() => $client->katago('default')->version(),
            fn() => $client->katago('default')->models(),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Response $i was accepted");
            } catch (FastGtpException $e) {
                $this->assertSame(200, $e->status);
            }
        }
    }

    public function testNamesThatWouldChangeThePath(): void
    {
        $client = (new FakeHttp())->client();
        foreach (['', '.', '..'] as $name) {
            try {
                $client->katago($name);
                $this->fail("\"$name\" was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

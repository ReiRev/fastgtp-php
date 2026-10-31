<?php

declare(strict_types=1);

namespace FastGtp\Tests\Unit;

use FastGtp\FastGtpException;
use FastGtp\Fields;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class FieldsTest extends TestCase
{
    public function testFieldsOfTheRightType(): void
    {
        $fields = new Fields([
            's' => 'x',
            'i' => 9,
            'f' => 6.5,
            'n' => 7,
            'l' => ['a', 'b'],
            'o' => ['k' => 'v'],
            'e' => [],
            'os' => [['a' => 1], []],
        ]);

        $this->assertSame('x', $fields->string('s'));
        $this->assertSame(9, $fields->int('i'));
        $this->assertSame(6.5, $fields->float('f'));
        // JSON has one number type, so a komi of 7 arrives as an int.
        $this->assertSame(7.0, $fields->float('n'));
        $this->assertSame(['a', 'b'], $fields->strings('l'));
        $this->assertSame('v', $fields->object('o')->string('k'));
        $this->assertInstanceOf(Fields::class, $fields->object('e'));
        $this->assertSame([['a' => 1], []], $fields->objects('os'));
    }

    public function testMissingOrMistypedFields(): void
    {
        $fields = new Fields(['s' => 1, 'i' => '9', 'f' => '6.5', 'l' => ['a', 1], 'm' => ['k' => 'a'], 'o' => ['a'], 'os' => [['a' => 1], 'b']]);
        $reads = [
            fn() => $fields->string('s'),
            fn() => $fields->string('missing'),
            fn() => $fields->int('i'),
            fn() => $fields->float('f'),
            fn() => $fields->strings('l'),
            fn() => $fields->strings('m'),
            fn() => $fields->object('o'),
            fn() => $fields->objects('os'),
            fn() => $fields->objects('m'),
        ];
        foreach ($reads as $i => $read) {
            try {
                $read();
                $this->fail("Read $i was accepted");
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBadFieldBecomesFastGtpException(): void
    {
        $http = (new FakeHttp())->answer(200, ['size' => 9]);
        try {
            $http->transporter()->read('GET', '/x', null, fn(array $data) => (new Fields($data))->string('sgf'));
            $this->fail('No exception');
        } catch (FastGtpException $e) {
            $this->assertSame(200, $e->status);
            $this->assertSame('The response has no valid "sgf"', $e->getMessage());
            $this->assertInstanceOf(UnexpectedValueException::class, $e->getPrevious());
        }
    }

    public function testUncheckedValueBecomesFastGtpException(): void
    {
        // A field passed to a typed parameter without going through Fields.
        $http = (new FakeHttp())->answer(200, ['size' => '9']);
        $this->expectException(FastGtpException::class);
        $http->transporter()->read('GET', '/x', null, fn(array $data) => (fn(int $size) => $size)($data['size']));
    }

    public function testReadReturnsTheValue(): void
    {
        $http = (new FakeHttp())->answer(200, ['size' => 9]);
        $this->assertSame(9, $http->transporter()->read('GET', '/x', null, fn(array $data) => (new Fields($data))->int('size')));
    }
}

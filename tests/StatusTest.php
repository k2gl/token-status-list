<?php

declare(strict_types=1);

namespace K2gl\TokenStatusList\Tests;

use K2gl\TokenStatusList\Exception\InvalidStatusListException;
use K2gl\TokenStatusList\Status;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

#[CoversClass(Status::class)]
final class StatusTest extends TestCase
{
    public function testRegisteredValuesHaveNamesAndPredicates(): void
    {
        // act
        $valid = Status::valid();
        $invalid = Status::invalid();
        $suspended = Status::suspended();

        // assert: values follow Section 7.1
        fact($valid->value)->is(0x00);
        fact($invalid->value)->is(0x01);
        fact($suspended->value)->is(0x02);

        // assert: names
        fact($valid->name())->is('VALID');
        fact($invalid->name())->is('INVALID');
        fact($suspended->name())->is('SUSPENDED');

        // assert: predicates
        fact($valid->isValid())->true();
        fact($valid->isInvalid())->false();
        fact($invalid->isInvalid())->true();
        fact($invalid->isValid())->false();
        fact($suspended->isSuspended())->true();
        fact($suspended->isValid())->false();
    }

    public function testOfWrapsAnyByteValue(): void
    {
        // act
        $status = Status::of(0xFF);

        // assert
        fact($status->value)->is(255);
        fact($status->name())->null();
        fact($status->isValid())->false();
        fact($status->equals(Status::of(255)))->true();
        fact($status->equals(Status::valid()))->false();
    }

    #[DataProvider('applicationSpecificValues')]
    public function testKnowsTheApplicationSpecificRange(int $value, bool $expected): void
    {
        // act + assert
        fact(Status::of($value)->isApplicationSpecific())->is($expected);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function applicationSpecificValues(): array
    {
        return [
            'VALID'             => [0x00, false],
            '0x03'              => [0x03, true],
            '0x04 reserved'     => [0x04, false],
            '0x0B reserved'     => [0x0B, false],
            '0x0C'              => [0x0C, true],
            '0x0F'              => [0x0F, true],
            '0x10 reserved'     => [0x10, false],
        ];
    }

    #[DataProvider('valuesOutsideAByte')]
    public function testRejectsValuesOutsideAByte(int $value): void
    {
        // act + assert
        fact(static fn () => Status::of($value))->throws(InvalidStatusListException::class, 'range from 0 to 255');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function valuesOutsideAByte(): array
    {
        return [
            'negative' => [-1],
            '256'      => [256],
        ];
    }
}

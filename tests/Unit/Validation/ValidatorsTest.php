<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validation;

use App\DTO\Input\OrderData;
use App\DTO\Input\WorkLogData;
use App\Model\Enum\OrderPriority;
use App\Model\Validation\CzechValidators;
use App\Security\PasswordPolicy;
use App\Services\OrderNumberGenerator;
use PHPUnit\Framework\TestCase;

final class ValidatorsTest extends TestCase
{
    public function testIcoChecksum(): void
    {
        self::assertTrue(CzechValidators::isValidIco('12345679'));
        self::assertTrue(CzechValidators::isValidIco('25596641'));
        self::assertTrue(CzechValidators::isValidIco('2559 6641'));
        self::assertFalse(CzechValidators::isValidIco('12345678'));
        self::assertFalse(CzechValidators::isValidIco('1234567'));
        self::assertFalse(CzechValidators::isValidIco('abcdefgh'));
    }

    public function testDicAndPhone(): void
    {
        self::assertTrue(CzechValidators::isValidDic('CZ12345679'));
        self::assertFalse(CzechValidators::isValidDic('12345679'));
        self::assertTrue(CzechValidators::isValidPhone('+420 777 123 456'));
        self::assertTrue(CzechValidators::isValidPhone('777123456'));
        self::assertFalse(CzechValidators::isValidPhone('123'));
        self::assertFalse(CzechValidators::isValidPhone('volejte mi'));
    }

    public function testPasswordPolicy(): void
    {
        self::assertSame([], PasswordPolicy::validate('Demo1234!'));
        self::assertNotSame([], PasswordPolicy::validate('kratke1'));
        self::assertNotSame([], PasswordPolicy::validate('bezcislicAbc'));
        self::assertNotSame([], PasswordPolicy::validate('vsechnomale123'));
    }

    public function testOrderNumberFormat(): void
    {
        self::assertSame('SRV-2026-0001', OrderNumberGenerator::format(2026, 1));
        self::assertSame('SRV-2026-0815', OrderNumberGenerator::format(2026, 815));
        self::assertSame('SRV-2027-12345', OrderNumberGenerator::format(2027, 12345));
        self::assertTrue(OrderNumberGenerator::isValid('SRV-2026-0001'));
        self::assertFalse(OrderNumberGenerator::isValid('SRV-26-1'));

        $this->expectException(\InvalidArgumentException::class);
        OrderNumberGenerator::format(2026, 0);
    }

    public function testWorkLogHoursAreConvertedToMinutesExactly(): void
    {
        self::assertSame(90, WorkLogData::hoursToMinutes('1.5'));
        self::assertSame(15, WorkLogData::hoursToMinutes('0.25'));
        self::assertSame(20, WorkLogData::hoursToMinutes('0.33'));

        $data = WorkLogData::fromArray(['description' => ' Výměna ', 'hours' => '1,25', 'work_date' => '2026-03-01']);
        self::assertSame('Výměna', $data->description);
        self::assertSame(75, $data->minutes);
        self::assertSame('2026-03-01', $data->workDate->format('Y-m-d'));
    }

    public function testOrderDataNormalization(): void
    {
        $data = OrderData::fromArray([
            'customer_id' => '3',
            'device_id' => '7',
            'assigned_technician_id' => '',
            'priority' => 'nesmysl',
            'problem_description' => '  Nejde zapnout  ',
            'estimated_price' => '2 500,50',
            'due_date' => null,
        ]);

        self::assertSame(3, $data->customerId);
        self::assertNull($data->technicianId);
        self::assertSame(OrderPriority::Normal, $data->priority);
        self::assertSame('Nejde zapnout', $data->problemDescription);
        self::assertSame('2500.50', $data->estimatedPrice);
        self::assertNull($data->dueDate);
    }
}

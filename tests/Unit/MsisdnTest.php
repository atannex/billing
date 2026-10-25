<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Unit;

use Atannex\Billing\Exceptions\InvalidMsisdn;
use Atannex\Billing\Support\Msisdn;
use Atannex\Billing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class MsisdnTest extends TestCase
{
    /**
     * @return array<string, array{string, string, ?string}>
     */
    public static function numbers(): array
    {
        return [
            'local MTN 67x' => ['677 12 34 56', '237677123456', 'mtn'],
            'MTN 650' => ['650123456', '237650123456', 'mtn'],
            'MTN 68x' => ['681234567', '237681234567', 'mtn'],
            'Orange 69x' => ['699-12-34-56', '237699123456', 'orange'],
            'Orange 655' => ['655123456', '237655123456', 'orange'],
            'Orange 640' => ['640123456', '237640123456', 'orange'],
            'Orange 686' => ['686123456', '237686123456', 'orange'],
            'Nexttel' => ['661234567', '237661234567', 'nexttel'],
            'E.164' => ['+237 677 12 34 56', '237677123456', 'mtn'],
            '00 prefix' => ['00237677123456', '237677123456', 'mtn'],
            'without plus' => ['237699123456', '237699123456', 'orange'],
            'Nigeria' => ['+2348031234567', '2348031234567', null],
        ];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function it_normalizes_and_detects_the_operator(string $input, string $international, ?string $operator): void
    {
        $msisdn = Msisdn::parse($input);

        $this->assertSame($international, $msisdn->international());
        $this->assertSame('+'.$international, $msisdn->e164());
        $this->assertSame($operator, $msisdn->operator());
    }

    #[Test]
    public function it_reads_local_numbers_for_another_country(): void
    {
        $msisdn = Msisdn::parse('0241234567', 'GH');

        $this->assertSame('233241234567', $msisdn->international());
        $this->assertSame('GH', $msisdn->country);
    }

    #[Test]
    public function it_rejects_numbers_of_the_wrong_length(): void
    {
        $this->assertNull(Msisdn::tryParse('6771234'));
        $this->assertNull(Msisdn::tryParse(''));
        $this->assertNull(Msisdn::tryParse(null));

        $this->expectException(InvalidMsisdn::class);
        Msisdn::parse('+999 123');
    }
}

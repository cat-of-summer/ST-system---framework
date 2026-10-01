<?php

namespace ST_system\Tests\Unit\Schemas;

use ST_system\Schemas\Yandex\MedicalFeed;
use ST_system\Schemas\Yandex\MedicalFeed\Price;
use ST_system\Tests\TestCase;

final class MedicalFeedTest extends TestCase {

    private function feed(array $override = []): string {
        return MedicalFeed::create()->fill($override + [
            'date'    => '2026-10-01 12:00',
            'name'    => 'Клиника «Здоровье» & партнёры',
            'url'     => 'https://example.com/?a=1&b=2',
            'doctors' => [[
                'id'          => 'd1',
                'name'        => 'Иванов <Иван>',
                'url'         => 'https://example.com/doctors/1',
                'description' => '  Опыт > 10 лет & "стаж"  ',
                'experience_years' => '12',
            ]],
            'clinics' => [[
                'id'   => 'c1',
                'name' => 'Филиал "Центр"',
                'url'  => 'https://example.com/clinics/1',
            ]],
            'services' => [['id' => 's1', 'name' => 'Приём']],
            'offers'   => [[
                'id'         => 'o1',
                'url'        => 'https://example.com/offers/1',
                'service_id' => 's1',
                'clinic_id'  => 'c1',
                'doctor_id'  => 'd1',
                'speciality' => 'терапевт',
                'price'      => ['base_price' => '1500', 'currency' => 'RUB'],
            ]],
        ])->print();
    }

    private static function load(string $xml): \DOMDocument {
        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        self::assertTrue($ok && !$errors, 'Невалидный XML: '.implode('; ', array_map(fn($e) => trim($e->message), $errors)));

        return $dom;
    }

    public function testFeedIsWellFormedAndValuesRoundTrip(): void {
        $dom   = self::load($this->feed());
        $xpath = new \DOMXPath($dom);

        $this->assertSame('2026-10-01 12:00', $dom->documentElement->getAttribute('date'));
        $this->assertSame('Клиника «Здоровье» & партнёры', $xpath->evaluate('string(/shop/name)'));
        $this->assertSame('https://example.com/?a=1&b=2', $xpath->evaluate('string(/shop/url)'));
        $this->assertSame('Иванов <Иван>', $xpath->evaluate('string(//doctor/name)'));
        $this->assertSame('Опыт > 10 лет & "стаж"', $xpath->evaluate('string(//doctor/description)'));
        $this->assertSame('d1', $xpath->evaluate('string(//doctor/internal_id)'));
        $this->assertSame('Филиал "Центр"', $xpath->evaluate('string(//clinic/name)'));
        $this->assertSame('o1', $xpath->evaluate('string(//offer/@id)'));
        $this->assertSame('1500', $xpath->evaluate('string(//offer/price/base_price)'));
    }

    public function testAlreadyEscapedEntitiesAreNotDoubleEscaped(): void {
        $xpath = new \DOMXPath(self::load($this->feed(['name' => 'A &amp; B'])));

        $this->assertSame('A & B', $xpath->evaluate('string(/shop/name)'));
    }

    public function testPriceDiscountsList(): void {
        $xml = Price::create()->fill([
            'base_price'       => '100',
            'currency'         => 'RUB',
            'discounts'        => [['name' => 'Акция & скидка', 'amount' => '10'], ['name' => 'Пенсионерам', 'amount' => 5]],
            'free_appointment' => ['ОМС'],
        ])->print();

        $this->assertSame(
            '<price><base_price>100</base_price><currency>RUB</currency>'
            .'<discount name="Акция &amp; скидка">10</discount><discount name="Пенсионерам">5</discount>'
            .'<free_appointment>ОМС</free_appointment></price>',
            $xml
        );
    }

    public function testPriceDiscountWithoutAmountIsRejected(): void {
        $this->expectException(\Exception::class);

        Price::create()->fill(['base_price' => 1, 'currency' => 'RUB', 'discounts' => [['name' => 'x']]]);
    }

    public function testInvalidDateIsRejected(): void {
        $this->expectException(\Exception::class);

        $this->feed(['date' => '01.10.2026']);
    }

    public function testDateDefaultsToNow(): void {
        $schema = MedicalFeed::create()->fill(['name' => 'N', 'url' => 'https://example.com/']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $schema->field('date'));
    }
}

<?php

namespace ST_system\Tests\Unit\Schemas;

use ST_system\Schemas\DefaultSchema;
use ST_system\Schemas\SchemaOrg\BreadcrumbList;
use ST_system\Schemas\SchemaOrg\FaqPage;
use ST_system\Schemas\SchemaOrg\Organization;
use ST_system\Schemas\SchemaOrg\Product;
use ST_system\Tests\TestCase;

final class SchemaOrgTest extends TestCase {

    /** Достаёт JSON из <script type="application/ld+json">. */
    private static function jsonLd(string $html): array {
        self::assertMatchesRegularExpression('~^<script type="application/ld\+json">.*</script>$~s', $html);

        return json_decode(substr($html, strlen('<script type="application/ld+json">'), -strlen('</script>')), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function organizationData(): array {
        // Пример из docs/src/Schemas/SchemaOrg/Organization.php.md.
        return [
            'id'          => 'https://example.com/#organization',
            'name'        => 'ГК Хевел',
            'legal_name'  => 'ООО «Хевел Ритейл»',
            'tax_id'      => '2124045449',
            'identifiers' => [['property_id' => 'ОГРН', 'name' => 'ОГРН', 'value' => '1182130009687']],
            'url'         => 'https://example.com/',
            'logo'        => ['url' => 'https://example.com/logo.png', 'width' => 512, 'height' => 512],
            'telephone'   => '+7-495-933-06-03',
            'email'       => 'info@example.com',
            'address'     => [
                'street_address'   => 'ул. Профсоюзная, д. 65, к. 1',
                'address_locality' => 'Москва',
                'postal_code'      => '117342',
                'address_country'  => 'RU',
            ],
            'geo'            => ['latitude' => '55.65336', 'longitude' => '37.53811'],
            'area_served'    => 'Россия',
            'contact_points' => [['contact_type' => 'sales', 'telephone' => '+7-495-933-06-03', 'available_language' => ['Russian']]],
            'same_as'        => ['https://vk.ru/example'],
        ];
    }

    public function testOrganizationFromDocs(): void {
        $data = self::jsonLd(Organization::create()->fill(self::organizationData())->print());

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Organization', $data['@type']);
        $this->assertSame('https://example.com/#organization', $data['@id']);
        $this->assertSame('ООО «Хевел Ритейл»', $data['legalName']);
        $this->assertSame('2124045449', $data['taxID']);
        $this->assertSame(['@type' => 'Country', 'name' => 'Россия'], $data['areaServed']);
        $this->assertSame(['https://vk.ru/example'], $data['sameAs']);
        $this->assertSame('PostalAddress', $data['address']['@type']);
        $this->assertSame('Москва', $data['address']['addressLocality']);
        $this->assertSame('ImageObject', $data['logo']['@type']);
        $this->assertSame('PropertyValue', $data['identifier'][0]['@type']);
        $this->assertSame('ContactPoint', $data['contactPoint'][0]['@type']);
        $this->assertArrayNotHasKey('geo', $data);
    }

    public function testOrganizationMovesGeoIntoPlace(): void {
        $data = Organization::create()->fill(self::organizationData())->toArray();

        $this->assertSame('Place', $data['location']['@type']);
        $this->assertSame('GeoCoordinates', $data['location']['geo']['@type']);
        $this->assertSame('55.65336', $data['location']['geo']['latitude']);
        $this->assertSame('Москва', $data['location']['address']['addressLocality']);
        $this->assertArrayNotHasKey('geo', $data['address']);
    }

    public function testOrganizationExplicitLocationWins(): void {
        $data = Organization::create()->fill([
            'id'       => 'https://example.com/#org',
            'name'     => 'Org',
            'geo'      => ['latitude' => '1', 'longitude' => '2'],
            'location' => ['name' => 'Офис', 'geo' => ['latitude' => '3', 'longitude' => '4']],
        ])->toArray();

        $this->assertSame('Офис', $data['location']['name']);
        $this->assertSame('3', $data['location']['geo']['latitude']);
    }

    public function testJsonLdEscapesClosingScriptTag(): void {
        $html = Organization::create()->fill(['id' => 'x', 'name' => '</script><b>'])->print();

        $this->assertStringNotContainsString('</script><b>', $html);
        $this->assertSame('</script><b>', self::jsonLd($html)['name']);
    }

    public function testMissingRequiredFieldThrows(): void {
        $this->expectException(\Exception::class);

        Organization::create()->fill(['name' => 'No id']);
    }

    public function testPrintBeforeFillThrows(): void {
        $this->expectException(\Exception::class);

        Organization::create()->print();
    }

    public function testBreadcrumbList(): void {
        $data = self::jsonLd(BreadcrumbList::create()->fill(['items' => [
            ['position' => '1', 'name' => 'Главная', 'item' => 'https://example.com/'],
            ['position' => 2, 'name' => 'Каталог'],
        ]])->print());

        $this->assertSame('BreadcrumbList', $data['@type']);
        $this->assertSame(1, $data['itemListElement'][0]['position']);
        $this->assertSame('https://example.com/', $data['itemListElement'][0]['item']);
        $this->assertSame('Каталог', $data['itemListElement'][1]['name']);
    }

    public function testFaqPageStripsTags(): void {
        $data = self::jsonLd(FaqPage::create()->fill(['questions' => [
            ['question' => '<b>Как?</b>', 'answer' => 'Вот &amp; так'],
        ]])->print());

        $this->assertSame('FAQPage', $data['@type']);
        $this->assertSame('Question', $data['mainEntity'][0]['@type']);
        $this->assertSame('Как?', $data['mainEntity'][0]['name']);
        $this->assertSame('Вот & так', $data['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testProductWithOffer(): void {
        $data = self::jsonLd(Product::create()->fill([
            'name'   => 'Товар',
            'sku'    => 'A-1',
            'offers' => ['price' => '100', 'price_currency' => 'RUB', 'availability' => 'https://schema.org/InStock'],
        ])->print());

        $this->assertSame('Product', $data['@type']);
        $this->assertSame('Offer', $data['offers']['@type']);
        $this->assertSame('RUB', $data['offers']['priceCurrency']);
    }

    public function testInlineSchemaAndHooks(): void {
        $schema = new DefaultSchema([
            'fields'  => ['name' => 'required|string|trim', 'tags' => DefaultSchema::arrayOf('string|trim')],
            'toArray' => fn(DefaultSchema $s) => ['name' => $s->field('name'), 'tags' => $s->field('tags')],
        ]);

        $schema->before(function (array &$data) { $data['name'] .= '!'; })
            ->fill(['name' => '  X ', 'tags' => [' a', 'b ']]);

        $this->assertSame(['name' => 'X !', 'tags' => ['a', 'b']], $schema->toArray());
        $this->assertSame('{"name":"X !","tags":["a","b"]}', $schema->print());
    }

    public function testNameScopeAndPath(): void {
        $this->assertSame('faq-page', FaqPage::name());
        $this->assertSame('schema-org', FaqPage::scope());
        $this->assertSame('schema-org.faq-page', FaqPage::path());
    }
}

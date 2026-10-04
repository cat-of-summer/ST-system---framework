<?php

namespace ST_system\Tests\Unit\MCP;

use ST_system\MCP\Tools\Args;
use ST_system\Rule;
use ST_system\Tests\TestCase;

final class ArgsTest extends TestCase {

    private const SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'id'       => ['type' => 'string', 'pattern' => '^[a-f0-9]{4}$'],
            'language' => ['type' => 'string', 'enum' => ['auto', 'ru', 'en'], 'default' => 'auto'],
            'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            'note'     => ['type' => 'string', 'maxLength' => 5],
        ],
        'required'             => ['id'],
        'additionalProperties' => false,
    ];

    private static function error(array $schema, $args, array $rules = []): string {
        try {
            Args::validate($schema, $args, $rules);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        self::fail('Ожидалась ошибка аргументов');
    }

    public function testDefaultsAreFilledAndOptionalKeysStayAbsent(): void {
        $this->assertSame(
            ['id' => 'abcd', 'language' => 'auto', 'limit' => 20],
            Args::validate(self::SCHEMA, ['id' => 'abcd'])
        );
    }

    public function testNullMeansAbsent(): void {
        $this->assertSame(['id' => 'abcd', 'language' => 'auto', 'limit' => 20, 'note' => null], Args::validate(self::SCHEMA, ['id' => 'abcd', 'limit' => null, 'note' => null]));
        $this->assertSame([], Args::validate(['type' => 'object', 'properties' => []], null));
    }

    public function testNumericStringsAreCoerced(): void {
        $args = Args::validate(self::SCHEMA, ['id' => 'abcd', 'limit' => '50']);

        $this->assertSame(50, $args['limit']);
    }

    public function testRequiredAndUnknownParameters(): void {
        $this->assertStringContainsString('id', self::error(self::SCHEMA, []));
        $this->assertStringContainsString('id', self::error(self::SCHEMA, ['id' => '']));
        $this->assertSame('Неизвестные параметры: extra.', self::error(self::SCHEMA, ['id' => 'abcd', 'extra' => 1]));
    }

    public function testArgumentsMustBeAnObject(): void {
        $this->assertSame('arguments должен быть объектом.', self::error(self::SCHEMA, ['abcd']));
        $this->assertSame('arguments должен быть объектом.', self::error(self::SCHEMA, 'abcd'));
    }

    public function testTypesBoundsEnumAndPattern(): void {
        $this->assertStringContainsString('limit.должно быть целым числом', self::error(self::SCHEMA, ['id' => 'abcd', 'limit' => 'много']));
        $this->assertStringContainsString('limit.должно быть целым числом', self::error(self::SCHEMA, ['id' => 'abcd', 'limit' => 2.5]));
        $this->assertStringContainsString('limit', self::error(self::SCHEMA, ['id' => 'abcd', 'limit' => 0]));
        $this->assertStringContainsString('limit', self::error(self::SCHEMA, ['id' => 'abcd', 'limit' => 101]));
        $this->assertStringContainsString('language.допустимо auto, ru, en', self::error(self::SCHEMA, ['id' => 'abcd', 'language' => 'de']));
        $this->assertStringContainsString('id', self::error(self::SCHEMA, ['id' => '../etc']));
        $this->assertStringContainsString('note', self::error(self::SCHEMA, ['id' => 'abcd', 'note' => 'слишком']));
        $this->assertStringContainsString('id.должно быть строкой', self::error(self::SCHEMA, ['id' => ['a']]));
    }

    public function testNumberAndBoolean(): void {
        $schema = ['type' => 'object', 'properties' => [
            'ratio' => ['type' => 'number', 'minimum' => 0],
            'flag'  => ['type' => 'boolean'],
        ]];

        $this->assertSame(['ratio' => 0.5, 'flag' => true], Args::validate($schema, ['ratio' => '0.5', 'flag' => 'true']));
        $this->assertSame(['ratio' => 3, 'flag' => false], Args::validate($schema, ['ratio' => 3, 'flag' => false]));
        $this->assertStringContainsString('flag.должно быть true или false', self::error($schema, ['flag' => 'да']));
        $this->assertStringContainsString('ratio.должно быть числом', self::error($schema, ['ratio' => 'x']));
    }

    public function testNullableType(): void {
        $schema = ['type' => 'object', 'properties' => ['parent' => ['type' => ['string', 'null']]]];

        $this->assertSame(['parent' => null], Args::validate($schema, ['parent' => null]));
        $this->assertSame(['parent' => 'x'], Args::validate($schema, ['parent' => 'x']));
    }

    public function testArraysWithItems(): void {
        $schema = ['type' => 'object', 'properties' => [
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => 3],
        ]];

        $this->assertSame(['ids' => [1, 2]], Args::validate($schema, ['ids' => ['1', 2]]));
        $this->assertStringContainsString('ids.1.должно быть целым числом', self::error($schema, ['ids' => [1, 'x']]));
        $this->assertStringContainsString('ids', self::error($schema, ['ids' => []]));
        $this->assertStringContainsString('ids', self::error($schema, ['ids' => [1, 2, 3, 4]]));
        $this->assertStringContainsString('ids.должно быть массивом', self::error($schema, ['ids' => ['a' => 1]]));
    }

    public function testNestedObjects(): void {
        $schema = ['type' => 'object', 'properties' => [
            'props' => [
                'type'       => 'object',
                'properties' => ['name' => ['type' => 'string'], 'port' => ['type' => 'integer']],
                'required'   => ['name'],
            ],
        ]];

        $this->assertSame(['props' => ['name' => 'db', 'port' => 5432]], Args::validate($schema, ['props' => ['name' => 'db', 'port' => '5432']]));
        $this->assertStringContainsString('props.name', self::error($schema, ['props' => ['port' => 1]]));
        $this->assertSame('Неизвестные параметры: props.extra.', self::error($schema, ['props' => ['name' => 'db', 'extra' => 1]]));
    }

    public function testAdditionalPropertiesAreKeptWhenAllowed(): void {
        $schema = ['type' => 'object', 'properties' => [
            'free' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ], 'additionalProperties' => true];

        $this->assertSame(
            ['free' => ['login' => 'root', 'host' => 'db'], 'other' => 1],
            Args::validate($schema, ['free' => ['login' => 'root', 'host' => 'db'], 'other' => 1])
        );
    }

    /** Правила Rule инструмента дополняют схему: преобразования и проверки, которых нет в JSON Schema. */
    public function testExtraRules(): void {
        $schema = ['type' => 'object', 'properties' => [
            'email' => ['type' => 'string'],
            'name'  => ['type' => 'string'],
        ], 'required' => ['email']];

        $rules = [
            'email' => 'trim|email',
            'name'  => ['trim', Rule::create(function (&$v) { return $v !== 'root'; })->handleError(function () { return 'имя занято'; })],
        ];

        $this->assertSame(['email' => 'a@b.ru', 'name' => 'Ann'], Args::validate($schema, ['email' => ' a@b.ru ', 'name' => ' Ann '], $rules));
        $this->assertStringContainsString('email', self::error($schema, ['email' => 'nope'], $rules));
        $this->assertStringContainsString('name.имя занято', self::error($schema, ['email' => 'a@b.ru', 'name' => 'root'], $rules));
    }
}

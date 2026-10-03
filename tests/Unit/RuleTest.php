<?php

namespace ST_system\Tests\Unit;

use ST_system\Rule;
use ST_system\Tests\TestCase;

final class RuleTest extends TestCase {

    /** Уникальное имя алиаса: реестр глобальный и не сбрасывается. */
    private static function aliasName(string $base): string {
        return $base.'_'.bin2hex(random_bytes(4));
    }

    public function testQuickStartCoercesAndDropsUnknownKeys(): void {
        $data = ['email' => 'user@example.com', 'age' => '21', 'extra' => 'x'];

        $errors = Rule::object([
            'email' => 'required|string|email',
            'age'   => 'required|int|min:18',
        ])->apply($data);

        $this->assertSame([], $errors);
        $this->assertSame(['email' => 'user@example.com', 'age' => 21], $data);
    }

    public function testThrowableJoinsErrors(): void {
        $data = ['age' => '17'];

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Value is too small');

        Rule::object(['age' => 'required|int|min:18'])->throwable()->apply($data);
    }

    public function testThrowableGroupsErrorsByField(): void {
        $data = ['age' => '17'];

        try {
            Rule::object(['age' => 'required|int|min:18', 'name' => 'required|string'])->throwable()->apply($data);
            $this->fail('ValidationException expected');
        } catch (\ST_system\Exceptions\ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame([
                'age'  => ['Value is too small'],
                'name' => ['This field is required'],
            ], $e->getErrors());
        }
    }

    public function testApplyMutatesCheckDoesNot(): void {
        $value = '42';
        $this->assertSame([], Rule::create('int')->apply($value));
        $this->assertSame(42, $value);

        $value = '42';
        $this->assertSame([], Rule::create('int')->check($value));
        $this->assertSame('42', $value);
    }

    public function testClosureRule(): void {
        $rule = Rule::create(fn(&$v) => is_string($v) && $v !== '');

        $this->assertSame([], $rule->check('x'));
        // Провал без handleError не порождает сообщения — см. docs/src/Rule.php.md.
        $this->assertSame([], $rule->check(''));
        $this->assertSame(['empty'], (clone $rule)->handleError(fn() => 'empty')->check(''));

        $messages = Rule::create(fn(&$v) => $v > 0 ? [] : ['negative'])->check(-1);
        $this->assertSame(['negative'], $messages);
    }

    public function testHandleErrorReplacesMessages(): void {
        $rule = Rule::create(fn(&$v) => $v > 0)->handleError(fn($v) => "Значение {$v} должно быть положительным");

        $this->assertSame(['Значение -5 должно быть положительным'], $rule->check(-5));
    }

    public function testUnknownRuleThrows(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown rule: 'definitely_not_a_rule'");

        Rule::create('definitely_not_a_rule');
    }

    /** @dataProvider coercionCases */
    public function testTypeCoercion(string $spec, $input, $expected): void {
        $value = $input;

        $this->assertSame([], Rule::create($spec)->apply($value));
        $this->assertSame($expected, $value);
    }

    public function coercionCases(): array {
        return [
            'int from string'    => ['int', '42', 42],
            'int from float'     => ['integer', 3.0, 3],
            'int from bool'      => ['int', true, 1],
            'int from null'      => ['int', null, 0],
            'float from string'  => ['float', '1.5', 1.5],
            'float from int'     => ['float', 2, 2.0],
            'bool from on'       => ['bool', 'on', true],
            'bool from "0"'      => ['bool', '0', false],
            'bool from null'     => ['bool', null, false],
            'string from null'   => ['string', null, ''],
            'array from null'    => ['array', null, []],
            'trim'               => ['trim', '  a  ', 'a'],
            'trim array'         => ['trim', [' a ', '  ', 'b'], [0 => 'a', 2 => 'b']],
            'trim chars'         => ['trim:/', '/a/b/', 'a/b'],
            'ltrim'              => ['ltrim', '  a  ', 'a  '],
            'rtrim'              => ['rtrim', '  a  ', '  a'],
            'uppercase'          => ['uppercase', 'привет', 'ПРИВЕТ'],
            'lowercase'          => ['lowercase', 'ПРИВЕТ', 'привет'],
            'strip_tags'         => ['strip_tags', '<b>x</b>', 'x'],
            // Имена перепутаны намеренно — см. docs/src/Rule.php.md.
            'html_encode decodes' => ['html_encode', '&amp;', '&'],
            'html_decode encodes' => ['html_decode', '&', '&amp;'],
        ];
    }

    /** @dataProvider failingCases */
    public function testTypeFailures(string $spec, $input, string $message): void {
        $this->assertSame([$message], Rule::create($spec)->check($input));
    }

    public function failingCases(): array {
        return [
            ['int', 'abc', 'Must be an integer'],
            ['int', [1], 'Must be an integer'],
            ['float', 'x', 'Must be a number'],
            ['bool', 'maybe', 'Must be a boolean'],
            ['string', [], 'Must be a string'],
            ['array', 'x', 'Must be an array'],
            ['email', 'not-an-email', 'Invalid email address'],
            ['url', 'not a url', 'Invalid URL'],
            ['max:3', 'abcd', 'Value is too large'],
            ['min:3', 'ab', 'Value is too small'],
            ['max:10', 11, 'Value is too large'],
            ['between:2,4', 'a', 'Value is out of range'],
            ['in:a,b', 'c', 'Not a valid option'],
            ['notIn:a,b', 'a', 'Value is not allowed'],
            ['digits:3', '12a', 'Must be digits only'],
            ['digits:3', '1234', 'Must be digits only'],
            ['hex_color', '#ggg', 'Invalid hex color'],
            ['date', 'not a date', 'Invalid date'],
            ['date_format:Y-m-d', '2026-13-01', 'Invalid date format'],
            ['json', '{bad', 'Invalid JSON'],
            ['starts_with:ab,cd', 'xx', 'Invalid prefix'],
            ['ends_with:ab', 'xx', 'Invalid suffix'],
            ['contains:needle', 'haystack', 'Must contain'],
            ['accepted', 'nope', 'Must be accepted'],
            ['declined', 'yes', 'Must be declined'],
            ['required', '', 'This field is required'],
            ['required', null, 'This field is required'],
        ];
    }

    /** @dataProvider passingCases */
    public function testValidValuesPass(string $spec, $input): void {
        $this->assertSame([], Rule::create($spec)->check($input));
    }

    public function passingCases(): array {
        return [
            ['email', 'user@example.com'],
            ['url', 'https://example.com/a?b=c'],
            ['max:3', 'абв'],
            ['min:2', [1, 2]],
            ['between:2,4', 3],
            ['in:a,b', 'b'],
            ['digits:4', '0123'],
            ['hex_color', '#a1B2c3'],
            ['date', '2026-10-01'],
            ['date_format:Y-m-d', '2026-10-01'],
            ['json', '{"a":1}'],
            ['starts_with:ab,cd', 'cdx'],
            ['ends_with:ab', 'xab'],
            ['contains:a', ['a', 'b']],
            ['accepted', 'yes'],
            ['declined', 'off'],
            ['nullable|int', null],
            ['nullable|email', ''],
        ];
    }

    public function testOrderIsIndependentOfSpecPosition(): void {
        $value = '50';

        $this->assertSame([], Rule::create('min:0|max:100|int')->apply($value));
        $this->assertSame(50, $value);
    }

    public function testRegexObjectFormAcceptsCommas(): void {
        $rule = Rule::regex('/^\d{1,3}$/');

        $this->assertSame([], $rule->check('123'));
        $this->assertSame(['Invalid format'], $rule->check('1234'));
        $this->assertSame([], Rule::regex('/^[a,b]+$/')->check('a,b'));
        $this->assertSame(['Value does not match any allowed type'], Rule::anyOf(Rule::regex('/^x$/'))->check('y'));
    }

    public function testInAndNotInObjectForms(): void {
        $this->assertSame([], Rule::in(['a,b', 'c'])->check('a,b'));
        $this->assertSame(['Not a valid option'], Rule::in(['a', 'b'])->check('c'));
        $this->assertSame(['Value is not allowed'], Rule::notIn(['x'])->check('x'));
    }

    public function testCountRequiresExactNumberOfItems(): void {
        $this->assertSame([], Rule::create('count:2')->check([1, 2]));
        $this->assertNotEmpty(Rule::create('count:2')->check([1, 2, 3]));
    }

    public function testSometimesSkipsMissingField(): void {
        $data = ['other' => 1];

        $this->assertSame([], Rule::object(['email' => 'sometimes|string|email'])->apply($data));
        $this->assertSame([], $data);
    }

    public function testPresentRequiresKey(): void {
        $data = [];
        $this->assertSame(['email.This field must be present'], Rule::object(['email' => 'present'])->check($data));
    }

    public function testRequiredInObjectPrefixesKey(): void {
        $data = [];

        $this->assertSame(['name.This field is required'], Rule::object(['name' => 'required|string'])->check($data));
    }

    public function testDefaultSubstitutesMissingValue(): void {
        $data = ['timeout' => null];

        Rule::object(['timeout' => ['nullable|int', Rule::default(30)], 'retries' => 'default:3'])->apply($data);

        $this->assertSame(['timeout' => 30, 'retries' => '3'], $data);
    }

    public function testRequiredIfAndProhibitedIf(): void {
        $this->assertSame(['This field is required'], Rule::requiredIf(true)->check(null));
        $this->assertSame([], Rule::requiredIf(false)->check(null));
        $this->assertSame([], Rule::requiredIf(fn() => false)->check(null));

        $this->assertSame(['This field is not allowed'], Rule::prohibitedIf(true)->check('x'));
        $this->assertSame([], Rule::prohibitedIf(true)->check(null));
    }

    public function testExcludeIfRemovesKey(): void {
        $data = ['a' => 1, 'b' => 2];

        Rule::object(['a' => [Rule::excludeIf(true), 'int'], 'b' => 'int'])->apply($data);

        $this->assertSame(['b' => 2], $data);
    }

    public function testWhenAppliesConditionally(): void {
        $data = ['discount' => '5'];
        Rule::object(['discount' => Rule::when(fn() => true, 'required|float|min:0')])->apply($data);
        $this->assertSame(['discount' => 5.0], $data);

        $data = ['discount' => '5'];
        Rule::object(['discount' => Rule::when(fn() => false, 'required|float|min:0')])->apply($data);
        $this->assertSame(['discount' => '5'], $data);
    }

    public function testAnyOfKeepsFirstSuccessfulCoercion(): void {
        $rule = Rule::anyOf('int', 'string|in:auto');

        $value = '10';
        $this->assertSame([], $rule->apply($value));
        $this->assertSame(10, $value);

        $value = 'auto';
        $this->assertSame([], $rule->apply($value));
        $this->assertSame('auto', $value);

        $this->assertSame(['Value does not match any allowed type'], $rule->check('other'));
    }

    public function testForEachDropsFailingItems(): void {
        $data = ['tags' => ['ok', '', 'also-ok']];

        $errors = Rule::object(['tags' => Rule::forEach('required|string')])->apply($data);

        $this->assertSame(['ok', 'also-ok'], array_values($data['tags']));
        $this->assertSame(['tags.1.This field is required'], $errors);
    }

    public function testForEachDsl(): void {
        $value = ['1', '2'];

        $this->assertSame([], Rule::create('array|foreach:int')->apply($value));
        $this->assertSame([1, 2], $value);
    }

    public function testDotNotationSchema(): void {
        $data = [
            'user'  => ['name' => 'Ann', 'email' => 'ann@example.com', 'tags' => [' a ', 'b ']],
            'items' => [['name' => 'x', 'price' => '1.5'], ['name' => 'y', 'price' => '2']],
        ];

        $errors = Rule::object([
            'user.name'     => 'required|string',
            'user.email'    => 'required|string|email',
            'user.tags.*'   => 'string|trim',
            'items.*.name'  => 'required|string',
            'items.*.price' => 'required|float|min:0',
        ])->apply($data);

        $this->assertSame([], $errors);
        $this->assertSame(['a', 'b'], $data['user']['tags']);
        $this->assertSame(1.5, $data['items'][0]['price']);
        $this->assertSame(2.0, $data['items'][1]['price']);
    }

    public function testDotNotationRejectsWildcardMixedWithKeys(): void {
        $this->expectException(\RuntimeException::class);

        Rule::object(['a.*' => 'string', 'a.b' => 'string']);
    }

    public function testDotNotationRejectsDuplicateDefinition(): void {
        $this->expectException(\RuntimeException::class);

        Rule::object(['user' => 'array', 'user.name' => 'string']);
    }

    public function testAliasRegistersFrozenRule(): void {
        $name = self::aliasName('positive');

        $rule = Rule::create(fn(&$v) => $v > 0)->handleError(fn() => 'not positive')->alias($name);

        $this->assertSame([], Rule::create("int|{$name}")->check('5'));
        $this->assertSame(['not positive'], Rule::create($name)->check(-1));

        $this->expectException(\RuntimeException::class);
        $rule->order(1);
    }

    public function testDuplicateAliasThrows(): void {
        $name = self::aliasName('dup');
        Rule::create(fn() => true)->alias($name);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Rule alias '{$name}' already registered");

        Rule::create(fn() => true)->alias($name);
    }

    public function testScopedAliasIsVisibleOnlyInsideScope(): void {
        $name = self::aliasName('scoped');

        Rule::scope('TestScope', function () use ($name) {
            $this->assertSame('TestScope', Rule::currentPrefix());
            Rule::create(fn() => true)->alias($name);
            $this->assertNotNull(Rule::get($name));
        });

        $this->assertNull(Rule::currentPrefix());
        $this->assertNull(Rule::get($name));
    }

    public function testScopeIsRestoredAfterException(): void {
        try {
            Rule::scope('Broken', function () { throw new \LogicException('boom'); });
        } catch (\LogicException $e) {
        }

        $this->assertNull(Rule::currentPrefix());
    }

    public function testFiltered(): void {
        $positive = Rule::filtered(fn($v, array $p) => [array_values(array_filter((array)$v, fn($x) => $x > 0)), false])
            ->order(600)
            ->handleError(fn($v) => 'No positive values left');

        $data = ['scores' => [-1, 2, 3]];
        $this->assertSame([], Rule::object(['scores' => $positive])->apply($data));
        $this->assertSame(['scores' => [2, 3]], $data);

        $data = ['scores' => [-1]];
        $this->assertSame([], Rule::object(['scores' => $positive])->apply($data));
        $this->assertSame([], $data);

        $data = ['scores' => [-1]];
        $this->assertSame(['scores.No positive values left'], Rule::object(['scores' => ['required', $positive]])->apply($data));
    }

    public function testBeforeAndAfterHooks(): void {
        $log = [];

        $rule = Rule::create(function (&$v) use (&$log) { $log[] = 'run'; return true; })
            ->before(function (&$v) use (&$log) { $log[] = 'before'; })
            ->after(function (&$v) use (&$log) { $log[] = 'after'; });

        $rule->check(1);

        $this->assertSame(['before', 'run', 'after'], $log);
    }

    public function testIsSentinel(): void {
        $this->assertFalse(Rule::isSentinel(null));
        $this->assertFalse(Rule::isSentinel(new \stdClass()));
    }
}

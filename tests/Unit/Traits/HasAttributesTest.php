<?php

namespace ST_system\Tests\Unit\Traits;

use ST_system\Tests\TestCase;
use ST_system\Traits\HasAttributes;

final class HasAttributesTest extends TestCase {

    private function model(): object {
        return new class {
            use HasAttributes;

            public int $resolved = 0;

            protected function attributeMap(): array {
                return [
                    'cached'   => ['resolveCached', true],
                    'fresh'    => 'resolveFresh',
                    'callable' => fn() => 'from-closure',
                ];
            }

            protected function resolveCached(): int { return ++$this->resolved; }
            protected function resolveFresh(): int { return ++$this->resolved; }

            protected function getFullNameAttribute(): string {
                return trim(($this->attributes['first'] ?? '').' '.($this->attributes['last'] ?? ''));
            }

            protected function setEmailAttribute(string $value): void {
                $this->attributes['email'] = strtolower($value);
            }
        };
    }

    public function testPlainAttributes(): void {
        $model = $this->model();
        $model->title = 'x';

        $this->assertSame('x', $model->title);
        $this->assertTrue(isset($model->title));
        $this->assertNull($model->missing);
        $this->assertFalse(isset($model->missing));

        unset($model->title);
        $this->assertFalse(isset($model->title));
    }

    public function testAccessorAndMutator(): void {
        $model = $this->model();
        $model->first = 'Ann';
        $model->last  = 'Lee';
        $model->email = 'ANN@EXAMPLE.COM';

        $this->assertSame('Ann Lee', $model->full_name);
        $this->assertTrue(isset($model->full_name));
        $this->assertSame('ann@example.com', $model->email);
    }

    public function testAttributeMapResolversAndCache(): void {
        $model = $this->model();

        $this->assertSame(1, $model->cached);
        $this->assertSame(1, $model->cached);
        $this->assertSame(2, $model->fresh);
        $this->assertSame(3, $model->fresh);
        $this->assertSame('from-closure', $model->callable);
        $this->assertTrue(isset($model->cached));
    }

    public function testSettingResetsCachedValue(): void {
        $model = $this->model();

        $this->assertSame(1, $model->cached);
        $model->cached = 'ignored';
        $this->assertSame(2, $model->cached);
    }
}

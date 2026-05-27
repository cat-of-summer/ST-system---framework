<?php

namespace ST_system\Schemas;

use ST_system\Schema;

abstract class DefaultSchema extends Schema
{
    private static array $booted = [];
    private static array $defs   = [];

    public function __construct()
    {
        static::ensureBooted();
        parent::__construct(self::$defs[static::class]);
    }

    public static function boot(): void
    {
        static::ensureBooted();
    }

    private static function ensureBooted(): void
    {
        $class = static::class;
        if (!isset(self::$booted[$class])) {
            self::$booted[$class] = true;
            $scope = static::defineScope();
            if ($scope !== null) {
                $result = null;
                static::withinScope($scope, static function () use (&$result, $class): void {
                    $result = $class::define();
                });
            } else {
                $result = static::define();
            }
            self::$defs[$class] = $result->entityDef;
        }
    }

    protected static function defineScope(): ?string
    {
        return null;
    }

    abstract protected static function define(): Schema;
}

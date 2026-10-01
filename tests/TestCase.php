<?php

namespace ST_system\Tests;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

    private array $tmpDirs = [];

    protected function tearDown(): void {
        foreach ($this->tmpDirs as $dir)
            static::removeDir($dir);

        $this->tmpDirs = [];

        parent::tearDown();
    }

    /** Пустая папка внутри корня прогона; удаляется после теста. */
    protected function tmpDir(string $name = ''): string {
        $dir = ST_TESTS_ROOT.'/tmp/'.($name !== '' ? $name.'-' : '').bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $this->tmpDirs[] = $dir;
    }

    /** Записывает файл (создавая папки) и возвращает его путь. */
    protected function writeFile(string $path, string $content): string {
        if (!is_dir(dirname($path)))
            mkdir(dirname($path), 0777, true);

        file_put_contents($path, $content);

        return $path;
    }

    protected static function getStatic(string $class, string $property) {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);

        return $ref->getValue();
    }

    protected static function setStatic(string $class, string $property, $value): void {
        $ref = new \ReflectionProperty($class, $property);
        $ref->setAccessible(true);
        $ref->setValue(null, $value);
    }

    protected static function getProperty(object $object, string $property) {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }

    /** Вызов private/protected метода: объекта или класса (для статических). */
    protected static function callPrivate($objectOrClass, string $method, ...$args) {
        $ref = new \ReflectionMethod($objectOrClass, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs(is_object($objectOrClass) ? $objectOrClass : null, $args);
    }

    public static function removeDir(string $dir): void {
        if (!is_dir($dir))
            return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item)
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());

        @rmdir($dir);
    }
}

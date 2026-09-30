<?php

declare(strict_types=1);

namespace OCA\Memories\Tests;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::injectStatic();
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::injectStatic();
        $this->injectInstance();
    }

    private static function injectStatic(): void
    {
        foreach ((new \ReflectionClass(static::class))->getProperties() as $prop) {
            if (!$prop->isStatic()) {
                continue;
            }
            self::injectProp($prop, null);
        }
    }

    private function injectInstance(): void
    {
        foreach ((new \ReflectionClass($this))->getProperties() as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            self::injectProp($prop, $this);
        }
    }

    private static function injectProp(\ReflectionProperty $prop, ?object $instance): void
    {
        if ([] === $prop->getAttributes(Injected::class)) {
            return;
        }
        if ($prop->isStatic() ? $prop->isInitialized() : $prop->isInitialized($instance)) {
            return;
        }
        $type = $prop->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return;
        }
        $value = \OCP\Server::get($type->getName());
        if ($prop->isStatic()) {
            $prop->setValue(null, $value);
        } else {
            $prop->setValue($instance, $value);
        }
    }
}

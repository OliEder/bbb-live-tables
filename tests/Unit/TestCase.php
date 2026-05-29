<?php

declare( strict_types=1 );

namespace BBB\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Base test case for pure-logic unit tests.
 *
 * Provides Brain Monkey setup/teardown plus reflection helpers so we can
 * exercise private methods and build instances without running the WP-bound
 * constructors.
 */
abstract class TestCase extends PHPUnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Create an instance of $class without invoking its constructor.
     */
    protected function makeWithoutConstructor( string $class ): object {
        return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
    }

    /**
     * Invoke a private/protected method on an object (or statically).
     *
     * @param object|string $target Object instance, or class name for static calls.
     * @param mixed         ...$args
     * @return mixed
     */
    protected function invoke( object|string $target, string $method, ...$args ): mixed {
        $class = is_object( $target ) ? get_class( $target ) : $target;
        $ref   = new ReflectionMethod( $class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( is_object( $target ) ? $target : null, $args );
    }

    /**
     * Invoke a method that takes its first argument by reference.
     *
     * @param array $byRefArg Mutated in place and returned.
     */
    protected function invokeByRef( object $target, string $method, array &$byRefArg ): mixed {
        $ref = new ReflectionMethod( get_class( $target ), $method );
        $ref->setAccessible( true );
        $args = [ &$byRefArg ];
        return $ref->invokeArgs( $target, $args );
    }
}

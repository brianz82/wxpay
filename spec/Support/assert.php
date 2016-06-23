<?php
if (!function_exists('assert_equals')) {
    /**
     * assert that two objects are equal
     *
     * @param mixed $expected
     * @param mixed $actual
     * @param string $message
     * @param float $delta
     *
     * @throws Exception        if the two objects not equal
     */
    function assert_equals($expected, $actual, $message = null, $delta = 0.0)
    {
        if ($expected == $actual) {
            return;
        }

        if (abs($expected - $actual) > $delta) {
            throw new \Exception($message ?: sprintf('expected %s, but got %s', $expected, $actual));
        }
    }
}

if (!function_exists('assert_empty')) {
    /**
     * assert given object is empty
     *
     * @param mixed $actual
     * @param string $message
     *
     * @throws Exception
     */
    function assert_empty($actual, $message = null)
    {
        if (!empty($actual)) {
            throw new \Exception($message ?: sprintf('%s is not empty', $actual));
        }
    }
}

if (!function_exists('assert_not_empty')) {
    /**
     * assert given object is not empty
     *
     * @param mixed $actual
     * @param string $message
     *
     * @throws Exception
     */
    function assert_not_empty($actual, $message = null)
    {
        if (empty($actual)) {
            throw new \Exception($message ?: sprintf('%s is empty', $actual));
        }
    }
}

if (!function_exists('assert_count')) {
    /**
     * assert given value has the desired count
     *
     * @param int $expected
     * @param array|string $actual
     * @param string $message
     *
     * @throws Exception
     */
    function assert_count($expected, $actual, $message = null)
    {
        if ($expected != count($actual)) {
            throw new \Exception($message ?: sprintf('expected count %d, but got %d', $expected, count($actual)));
        }
    }
}

if (!function_exists('assert_true')) {
    /**
     * assert that given value is true
     *
     * @param mixed $actual
     * @param string $message
     *
     * @throws Exception
     */
    function assert_true($actual, $message = null)
    {
        if (!$actual) {
            throw new \Exception($message ?: sprintf('expected true, but got false'));
        }
    }
}
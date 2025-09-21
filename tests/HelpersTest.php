<?php namespace NumenCode\SyncOps\Tests;

use PluginTestCase;

class HelpersTest extends PluginTestCase
{
    /**
     * Test function: format_path
     * Test formatting a normal directory path without trailing slash.
     */
    public function testFormatPathWithoutTrailingSlash(): void
    {
        $result = format_path('/var/www/html');

        // Assert a trailing slash was added
        $this->assertEquals('/var/www/html/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting a directory path that already has a trailing slash.
     */
    public function testFormatPathWithTrailingSlash(): void
    {
        $result = format_path('/var/www/html/');

        // Assert the path remains unchanged
        $this->assertEquals('/var/www/html/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting a directory path with multiple trailing slashes.
     */
    public function testFormatPathWithMultipleTrailingSlashes(): void
    {
        $result = format_path('/var/www/html///');

        // Assert only one trailing slash remains
        $this->assertEquals('/var/www/html/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting when the input is null.
     */
    public function testFormatPathWithNull(): void
    {
        $result = format_path(null);

        // Assert null is returned for null input
        $this->assertNull($result);
    }

    /**
     * Test function: format_path
     * Test formatting an empty string input.
     */
    public function testFormatPathWithEmptyString(): void
    {
        $result = format_path('');

        // Assert null is returned for empty string
        $this->assertNull($result);
    }
}

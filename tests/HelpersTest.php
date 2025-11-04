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
        $this->assertSame('/var/www/html/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting a directory path that already has a trailing slash.
     */
    public function testFormatPathWithTrailingSlash(): void
    {
        $result = format_path('/var/www/html/');
        $this->assertSame('/var/www/html/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting a directory path with multiple trailing slashes.
     */
    public function testFormatPathWithMultipleTrailingSlashes(): void
    {
        $result = format_path('/var/www/html///');
        $this->assertSame('/var/www/html/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting the root directory path.
     */
    public function testFormatPathWithRootSlash(): void
    {
        $result = format_path('/');
        $this->assertSame('/', $result);
    }

    /**
     * Test function: format_path
     * Ensure Windows-style backslashes are not modified unexpectedly.
     */
    public function testFormatPathWithWindowsBackslashes(): void
    {
        $result = format_path('C:\\laragon\\www\\project');
        $this->assertSame('C:\\laragon\\www\\project/', $result);
    }

    /**
     * Test function: format_path
     * Test formatting when the input is null.
     */
    public function testFormatPathWithNull(): void
    {
        $result = format_path(null);
        $this->assertNull($result);
    }

    /**
     * Test function: format_path
     * Test formatting an empty string input.
     */
    public function testFormatPathWithEmptyString(): void
    {
        $result = format_path('');
        $this->assertNull($result);
    }
}

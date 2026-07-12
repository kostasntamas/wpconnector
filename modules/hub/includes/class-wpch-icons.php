<?php

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Loads the SVG icons stored in assets/icons/. The markup is still inlined
 * into the page (an <img> reference would stop fill="currentColor" from
 * following the surrounding text color), but the files are the single source
 * so an icon can be swapped without touching the render code.
 */
class WPCH_Icons
{
	/** @var array<string, string> Raw <svg> markup keyed by icon name. */
	private static array $cache = [];

	// Returns the icon's <svg> markup with width/height set to $size, or ''
	// if no such file exists. Output is trusted markup from the plugin's own
	// assets/icons/ dir — echo it unescaped.
	public static function get(string $name, int $size): string
	{
		if (! isset(self::$cache[$name])) {
			$path                = WPCH_PLUGIN_DIR . 'assets/icons/' . $name . '.svg';
			$svg                 = is_readable($path) ? (string) file_get_contents($path) : '';
			self::$cache[$name] = trim($svg);
		}

		if ('' === self::$cache[$name]) {
			return '';
		}

		return str_replace('<svg ', sprintf('<svg width="%1$d" height="%1$d" ', $size), self::$cache[$name]);
	}
}

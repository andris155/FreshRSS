<?php
declare(strict_types=1);

/**
 * FreshRSS YouTube & Image Proxy Mod Configuration
 *
 * Location: ./data/config-youtube.php (or FreshRSS root ./config-youtube.php)
 * This file is persisted across FreshRSS updates and will not be overwritten.
 * Edit your API keys, proxy settings, and options below.
 */
return [

	// YouTube Data API v3 key (used to fetch video duration, live stream, and premiere info)
	'api_key' => 'key',

	// Enable or disable image proxy cache globally. If false, images are loaded directly without the nginx proxy cache.
	'proxy_enabled' => true,

	// Image Proxy authentication key (passed in URL query string: ?key=...)
	'proxy_key' => 'key',

	// Base URL of the image proxy service
	'proxy_url' => 'https://freshrss.lan/proxy',

	// Enable or disable YouTube thumbnail link modification (upgrading to hq720) and caching image variants in youtube.json.
	// If false, original YouTube thumbnail links are kept and no image types are cached in youtube.json.
	'youtube_image_modification' => true,

	// Enable or disable YouTube video duration check, status badges, and duration caching in youtube.json.
	// If false, YouTube duration checks are disabled, no API requests are made, and standard article summaries are shown.
	'youtube_duration_enabled' => true,

	// Enable or disable API request logging in data/cache/youtube_api.log
	'logging_enabled' => true,

	// Maximum log file size in bytes before rotating to youtube_api.log.old (default: 1048576 = 1MB)
	'maxLogSize' => 1024 * 1024,

	// Interval in seconds between cache cleanup runs (default: 86400 = 24 hours)
	'cleanup_interval' => 86400,

	// Time-to-live in seconds for normal video metadata (default: 90 * 86400 = 90 days)
	'ttl_normal' => 90 * 86400,

	// Time-to-live in seconds for short-lived entries: premieres, upcoming live streams, not found (default: 86400 = 1 day)
	'ttl_short' => 86400,

	// Time-to-live in seconds for temporary API errors before retrying (default: 3600 = 1 hour)
	'ttl_error' => 3600,

	// Maximum items to keep in cache (data/cache/youtube.json) before automatic cleanup
	'cache_max_items' => 5000,

];

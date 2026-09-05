<?php
declare(strict_types=1);

/**
 * YouTube & Image Proxy Helper for FreshRSS
 *
 * Placed in app/Models/YouTubeProxy.php and autoloaded automatically as FreshRSS_YouTubeProxy.
 * Keeping this in a separate file ensures that FreshRSS core files (like Entry.php)
 * remain 100% untouched for effortless upstream updates.
 */
class FreshRSS_YouTubeProxy {

	// ==========================================
	// CONFIGURATION & DEFAULTS
	// ==========================================
	public static string $api_key = 'key';
	public static string $proxy_key = 'key';
	public static string $proxy_url = 'https://freshrss.lan/proxy';
	public static bool $logging_enabled = true;
	public static int $cache_max_items = 5000;
	public static int $cleanup_interval = 86400;     // 24 hours
	public static int $ttl_normal = 90 * 86400;      // 90 days
	public static int $ttl_short = 86400;            // 1 day
	public static int $ttl_error = 3600;             // 1 hour
	public static int $max_log_size = 1024 * 1024;   // 1MB

	// In-memory cache & runtime state
	public static ?array $cache = null;
	public static bool $cache_changed = false;
	public static bool $circuit_broken = false;
	private static bool $shutdown_registered = false;

	// Mod configuration array loaded from data/config-youtube.php
	public static ?array $mod_config = null;

	public static function loadModConfig(): array {
		if (self::$mod_config !== null) {
			return self::$mod_config;
		}

		$path = defined('DATA_PATH') ? DATA_PATH . '/config-youtube.php' : (defined('FRESHRSS_PATH') ? FRESHRSS_PATH . '/data/config-youtube.php' : './data/config-youtube.php');
		if (is_file($path)) {
			$loaded = include $path;
			if (is_array($loaded)) {
				self::$mod_config = $loaded;
				return self::$mod_config;
			}
		}

		self::$mod_config = [];
		return self::$mod_config;
	}

	public static function getApiKey(): string {
		$cfg = self::loadModConfig();
		if (!empty($cfg['api_key']) && is_string($cfg['api_key'])) {
			return $cfg['api_key'];
		}
		if (!empty($cfg['youtube_api_key']) && is_string($cfg['youtube_api_key'])) {
			return $cfg['youtube_api_key'];
		}
		if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasSystemConf()) {
			$val = FreshRSS_Context::systemConf()->youtube_api_key ?? null;
			if (is_string($val) && $val !== '') {
				return $val;
			}
		}
		return self::$api_key;
	}

	public static function getProxyKey(): string {
		$cfg = self::loadModConfig();
		if (!empty($cfg['proxy_key']) && is_string($cfg['proxy_key'])) {
			return $cfg['proxy_key'];
		}
		if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasSystemConf()) {
			$val = FreshRSS_Context::systemConf()->proxy_key ?? null;
			if (is_string($val) && $val !== '') {
				return $val;
			}
		}
		return self::$proxy_key;
	}

	public static function getProxyUrl(): string {
		$cfg = self::loadModConfig();
		if (!empty($cfg['proxy_url']) && is_string($cfg['proxy_url'])) {
			return rtrim($cfg['proxy_url'], '/');
		}
		if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasSystemConf()) {
			$val = FreshRSS_Context::systemConf()->proxy_url ?? null;
			if (is_string($val) && $val !== '') {
				return rtrim($val, '/');
			}
		}
		return rtrim(self::$proxy_url, '/');
	}

	public static function isLoggingEnabled(): bool {
		$cfg = self::loadModConfig();
		if (isset($cfg['logging_enabled']) && is_bool($cfg['logging_enabled'])) {
			return $cfg['logging_enabled'];
		}
		if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasSystemConf()) {
			$val = FreshRSS_Context::systemConf()->youtube_logging_enabled ?? null;
			if (is_bool($val)) {
				return $val;
			}
		}
		return self::$logging_enabled;
	}

	public static function getCacheMaxItems(): int {
		$cfg = self::loadModConfig();
		if (isset($cfg['cache_max_items']) && is_int($cfg['cache_max_items']) && $cfg['cache_max_items'] > 0) {
			return $cfg['cache_max_items'];
		}
		if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasSystemConf()) {
			$val = FreshRSS_Context::systemConf()->youtube_cache_max_items ?? null;
			if (is_int($val) && $val > 0) {
				return $val;
			}
		}
		return self::$cache_max_items;
	}

	public static function getCleanupInterval(): int {
		$cfg = self::loadModConfig();
		if (isset($cfg['cleanup_interval']) && is_int($cfg['cleanup_interval']) && $cfg['cleanup_interval'] > 0) {
			return $cfg['cleanup_interval'];
		}
		return self::$cleanup_interval;
	}

	public static function getTtlNormal(): int {
		$cfg = self::loadModConfig();
		if (isset($cfg['ttl_normal']) && is_int($cfg['ttl_normal']) && $cfg['ttl_normal'] > 0) {
			return $cfg['ttl_normal'];
		}
		return self::$ttl_normal;
	}

	public static function getTtlShort(): int {
		$cfg = self::loadModConfig();
		if (isset($cfg['ttl_short']) && is_int($cfg['ttl_short']) && $cfg['ttl_short'] > 0) {
			return $cfg['ttl_short'];
		}
		return self::$ttl_short;
	}

	public static function getTtlError(): int {
		$cfg = self::loadModConfig();
		if (isset($cfg['ttl_error']) && is_int($cfg['ttl_error']) && $cfg['ttl_error'] > 0) {
			return $cfg['ttl_error'];
		}
		return self::$ttl_error;
	}

	public static function getMaxLogSize(): int {
		$cfg = self::loadModConfig();
		if (isset($cfg['maxLogSize']) && is_int($cfg['maxLogSize']) && $cfg['maxLogSize'] > 0) {
			return $cfg['maxLogSize'];
		}
		if (isset($cfg['max_log_size']) && is_int($cfg['max_log_size']) && $cfg['max_log_size'] > 0) {
			return $cfg['max_log_size'];
		}
		return self::$max_log_size;
	}

	// ==========================================
	// CACHE MANAGEMENT (Leak-Proof & Atomic)
	// ==========================================
	public static function initCache(): void {
		if (self::$cache !== null) {
			return;
		}

		$file = defined('CACHE_PATH') ? CACHE_PATH . '/youtube.json' : './data/cache/youtube.json';
		if (is_file($file)) {
			$raw = @file_get_contents($file);
			self::$cache = (is_string($raw) && $raw !== '') ? (json_decode($raw, true) ?: []) : [];
			unset($raw);
		} else {
			self::$cache = [];
		}

		self::cleanCache();

		if (!self::$shutdown_registered) {
			self::$shutdown_registered = true;
			register_shutdown_function(static function (): void {
				FreshRSS_YouTubeProxy::saveCache();
			});
		}
	}

	public static function cleanCache(bool $force = false): void {
		if (!is_array(self::$cache)) {
			return;
		}

		$now = time();
		$last_cleanup = (int)(self::$cache['__last_cleanup'] ?? 0);
		$cleanup_interval = self::getCleanupInterval();

		if (!$force && ($now - $last_cleanup) < $cleanup_interval) {
			return;
		}

		$ttl_normal = self::getTtlNormal();
		$ttl_short = self::getTtlShort();
		$ttl_error = self::getTtlError();

		foreach (self::$cache as $key => $row) {
			if ($key === '__last_cleanup' || str_starts_with((string)$key, '__')) {
				continue;
			}
			if (!is_array($row) || empty($row['timestamp'])) {
				unset(self::$cache[$key]);
				self::$cache_changed = true;
				continue;
			}

			$age = $now - (int)$row['timestamp'];
			$is_error = !empty($row['api_error']);
			$is_not_found = isset($row['duration']) && $row['duration'] === false;
			$is_premiere = !empty($row['premiere']);
			$is_live = !empty($row['liveBroadcastContent']) && $row['liveBroadcastContent'] === 'upcoming';
			$is_zero = isset($row['duration']) && ($row['duration'] === '0:00' || $row['duration'] === '00:00' || $row['duration'] === '0:0');

			$ttl = $is_error ? $ttl_error : (($is_not_found || $is_premiere || $is_live || $is_zero) ? $ttl_short : $ttl_normal);

			if ($age > $ttl) {
				unset(self::$cache[$key]);
				self::$cache_changed = true;
			}
		}

		// Enforce maximum cache capacity (LRU pruning) to prevent unbounded memory growth
		$maxItems = self::getCacheMaxItems();
		if (count(self::$cache) > $maxItems) {
			$cleanups = self::$cache['__last_cleanup'] ?? null;
			unset(self::$cache['__last_cleanup']);

			uasort(self::$cache, static fn($a, $b) => ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0)));
			self::$cache = array_slice(self::$cache, -$maxItems, null, true);

			if ($cleanups !== null) {
				self::$cache['__last_cleanup'] = $cleanups;
			}
			self::$cache_changed = true;
		}

		self::$cache['__last_cleanup'] = $now;
		self::$cache_changed = true;
	}

	public static function saveCache(): void {
		if (!self::$cache_changed || !is_array(self::$cache)) {
			return;
		}

		$file = defined('CACHE_PATH') ? CACHE_PATH . '/youtube.json' : './data/cache/youtube.json';
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		// Concurrency protection: merge changes with on-disk state
		if (is_file($file)) {
			$onDiskRaw = @file_get_contents($file);
			if (is_string($onDiskRaw) && $onDiskRaw !== '') {
				$onDisk = json_decode($onDiskRaw, true);
				if (is_array($onDisk)) {
					self::$cache = array_merge($onDisk, self::$cache);
				}
				unset($onDisk);
			}
			unset($onDiskRaw);
		}

		$json = json_encode(self::$cache, JSON_UNESCAPED_SLASHES);
		if (is_string($json)) {
			@file_put_contents($file, $json, LOCK_EX);
			unset($json);
		}
		self::$cache_changed = false;
	}

	// ==========================================
	// LOGGING & API CALLS
	// ==========================================
	public static function logApi(string $vid, string $status, string $url, int $bytes): void {
		if (!self::isLoggingEnabled()) {
			return;
		}

		$logFile = defined('CACHE_PATH') ? CACHE_PATH . '/youtube_api.log' : './data/cache/youtube_api.log';
		$maxLogSize = self::getMaxLogSize();

		clearstatcache(true, $logFile);
		if (@file_exists($logFile) && (@filesize($logFile) ?: 0) > $maxLogSize) {
			@rename($logFile, $logFile . '.old');
		}

		$entry = date('Y-m-d H:i:s') . " | VideoID: {$vid} | Status: {$status} | URL: {$url} | Bytes: {$bytes}\n";
		@file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
	}

	public static function fetchApi(string $url, string $vid): ?string {
		if (self::$circuit_broken) {
			return null;
		}

		$response = null;
		$status = 'FAIL';
		$bytes = 0;

		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 2,
				CURLOPT_TIMEOUT => 3,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT => 'FreshRSS/YouTubeFetcher',
			]);
			$result = curl_exec($ch);
			$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($result !== false && $httpCode === 200) {
				$response = (string)$result;
				$status = 'OK';
				$bytes = strlen($response);
			} elseif ($httpCode === 403 || $httpCode === 429) {
				self::$circuit_broken = true;
				$status = 'FORBIDDEN_' . $httpCode;
			} elseif ($result === false) {
				$status = 'CURL_ERROR';
			} else {
				$status = 'HTTP_' . $httpCode;
			}
		} else {
			$context = stream_context_create([
				'http' => [
					'timeout' => 3.0,
					'ignore_errors' => true,
					'header' => "User-Agent: FreshRSS/YouTubeFetcher\r\n",
				],
			]);
			$result = @file_get_contents($url, false, $context);
			if ($result !== false) {
				$response = $result;
				$status = 'OK';
				$bytes = strlen($response);
			}
		}

		self::logApi($vid, $status, $url, $bytes);

		if ($response === null) {
			self::$circuit_broken = true;
		}

		return $response;
	}

	// ==========================================
	// THUMBNAILS & PROXY
	// ==========================================
	public static function getThumbnailVariant(string $vid, string $cdn = 'i'): string {
		self::initCache();

		if (!empty(self::$cache[$vid]['thumbnail']) && is_string(self::$cache[$vid]['thumbnail'])) {
			return self::$cache[$vid]['thumbnail'];
		}

		$hq720 = "https://{$cdn}.ytimg.com/vi/{$vid}/hq720.jpg";
		$variant = 'hqdefault';

		if (function_exists('curl_init')) {
			$ch = curl_init($hq720);
			curl_setopt_array($ch, [
				CURLOPT_NOBODY => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 1,
				CURLOPT_TIMEOUT => 1,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 2,
				CURLOPT_USERAGENT => 'FreshRSS/ThumbCheck',
			]);
			curl_exec($ch);
			$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
			curl_close($ch);

			if ($httpCode === 200 && $contentLength > 2000) {
				$variant = 'hq720';
			}
		} else {
			$prev_default = stream_context_get_options(stream_context_get_default());
			stream_context_set_default(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
			$size = @getimagesize($hq720);
			if (!empty($prev_default)) {
				stream_context_set_default($prev_default);
			}
			if ($size !== false && !($size[0] === 120 && $size[1] === 90)) {
				$variant = 'hq720';
			}
		}

		if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
			self::$cache[$vid] = [];
		}
		self::$cache[$vid]['thumbnail'] = $variant;
		if (empty(self::$cache[$vid]['timestamp'])) {
			self::$cache[$vid]['timestamp'] = time();
		}
		self::$cache_changed = true;

		return $variant;
	}

	public static function proxyUrl(string $url): string {
		if ($url === '' || str_starts_with($url, 'data:') || !str_starts_with($url, 'http')) {
			return $url;
		}

		$proxyUrl = self::getProxyUrl();
		$proxyKey = self::getProxyKey();

		// Prevent double-proxying
		if (str_starts_with($url, $proxyUrl) || ($proxyKey !== '' && str_contains($url, 'key=' . $proxyKey))) {
			return $url;
		}

		// YouTube thumbnail replacement & proxying
		if (str_contains($url, 'ytimg.com') && preg_match('#^https?://(i\d*)\.ytimg\.com/vi/([a-zA-Z0-9_-]{11})/(?:hqdefault|maxresdefault|sddefault|default)\.jpg(?:\?.*)?$#i', $url, $m)) {
			$cdn = $m[1] !== '' ? $m[1] : 'i';
			$vid = $m[2];
			$variant = self::getThumbnailVariant($vid, $cdn);
			return "{$proxyUrl}?key={$proxyKey}&url=https://{$cdn}.ytimg.com/vi/{$vid}/{$variant}.jpg";
		}

		// Non-YouTube image
		$target = str_contains($url, '&') ? rawurlencode($url) : $url;
		return "{$proxyUrl}?key={$proxyKey}&url={$target}";
	}

	/**
	 * Helper to proxy entry/enclosure thumbnail array.
	 *
	 * @param array{'url'?:string,'medium'?:string,'height'?:int,'width'?:int,'time'?:string}|null $thumbnail
	 * @return array{'url'?:string,'medium'?:string,'height'?:int,'width'?:int,'time'?:string}|null
	 */
	public static function proxyThumbnail(?array $thumbnail): ?array {
		if ($thumbnail !== null && !empty($thumbnail['url']) && is_string($thumbnail['url'])) {
			$thumbnail['url'] = self::proxyUrl($thumbnail['url']);
		}
		return $thumbnail;
	}

	public static function extractVideoId(string $link): ?string {
		if ($link === '') {
			return null;
		}
		if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|shorts\/|live\/|watch\?(?:.*&)?v=))([a-zA-Z0-9_-]{11})/i', $link, $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Check if a feed originates from YouTube.
	 */
	public static function isYouTubeFeed(?FreshRSS_Feed $feed): bool {
		if ($feed === null) {
			return false;
		}
		$url = $feed->url();
		$website = $feed->website();
		return str_contains($url, 'youtube.com')
			|| str_contains($website, 'youtube.com')
			|| str_contains($url, 'youtu.be')
			|| str_contains($website, 'youtu.be');
	}

	/**
	 * Check if an entry is a YouTube video.
	 */
	public static function isYouTubeEntry(?FreshRSS_Entry $entry): bool {
		if ($entry === null) {
			return false;
		}
		return self::extractVideoId($entry->link()) !== null;
	}

	/**
	 * Check if either the feed or entry is from YouTube.
	 */
	public static function isYouTube(?FreshRSS_Feed $feed = null, ?FreshRSS_Entry $entry = null): bool {
		return self::isYouTubeFeed($feed) || self::isYouTubeEntry($entry);
	}

	/**
	 * Check if a YouTube feed is a playlist (contains playlist_id=).
	 */
	public static function isYouTubePlaylistFeed(?FreshRSS_Feed $feed): bool {
		if ($feed === null) {
			return false;
		}
		$url = $feed->url();
		return stripos($url, 'playlist_id=') !== false
			|| stripos($feed->website(), 'playlist_id=') !== false;
	}

	/**
	 * Return ' yt-thumbnail' if the entry is a YouTube video, otherwise empty string.
	 */
	public static function ytThumbnailClass(?FreshRSS_Entry $entry): string {
		return self::isYouTubeEntry($entry) ? ' yt-thumbnail' : '';
	}

	// ==========================================
	// YOUTUBE VIDEO DETAILS & RENDERING
	// ==========================================

	/**
	 * @return array{duration:string,is_live:bool,premiere:bool,not_found:bool,scheduled_start:string|false,upcoming:bool}|null
	 */
	public static function getDetails(FreshRSS_Entry $entry): ?array {
		$vid = self::extractVideoId($entry->link());
		if ($vid === null) {
			return null;
		}

		self::initCache();
		$now = time();
		$zero_durations = ['0:00', '00:00', '0:0'];

		$ttl_normal = self::getTtlNormal();
		$ttl_short = self::getTtlShort();
		$ttl_error = self::getTtlError();

		if (isset(self::$cache[$vid]) && is_array(self::$cache[$vid])) {
			$item = self::$cache[$vid];
			$age = $now - (int)($item['timestamp'] ?? 0);

			$cached_dur = $item['duration'] ?? null;
			$is_not_found = ($cached_dur === false);
			$is_premiere = !empty($item['premiere']);
			$is_error = !empty($item['api_error']);
			$is_zero = is_string($cached_dur) && in_array($cached_dur, $zero_durations, true);
			$is_upcoming = !empty($item['liveBroadcastContent']) && $item['liveBroadcastContent'] === 'upcoming';

			$ttl = $is_error ? $ttl_error : (($is_not_found || $is_premiere || $is_zero || $is_upcoming) ? $ttl_short : $ttl_normal);

			if ($age < $ttl) {
				if ($is_error) {
					return null;
				}

				$sched = false;
				if (!empty($item['scheduledStartTime']) && is_string($item['scheduledStartTime']) && strtotime($item['scheduledStartTime']) > $now) {
					$sched = $item['scheduledStartTime'];
				}

				return [
					'duration' => is_string($cached_dur) ? $cached_dur : '',
					'is_live' => $is_zero,
					'premiere' => $is_premiere,
					'not_found' => $is_not_found,
					'scheduled_start' => $sched,
					'upcoming' => $is_upcoming,
				];
			}
		}

		if (self::$circuit_broken) {
			return null;
		}

		$apiKey = self::getApiKey();
		$apiUrl = "https://www.googleapis.com/youtube/v3/videos?id={$vid}&part=contentDetails,liveStreamingDetails,snippet&key={$apiKey}";
		$response = self::fetchApi($apiUrl, $vid);

		if ($response === null || $response === '') {
			if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
				self::$cache[$vid] = [];
			}
			self::$cache[$vid]['api_error'] = true;
			self::$cache[$vid]['timestamp'] = $now;
			self::$cache_changed = true;
			return null;
		}

		$data = json_decode($response, true);
		unset($response);

		if (empty($data['items']) || !is_array($data['items'])) {
			if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
				self::$cache[$vid] = [];
			}
			self::$cache[$vid]['duration'] = false;
			self::$cache[$vid]['timestamp'] = $now;
			self::$cache_changed = true;

			return [
				'duration' => '',
				'is_live' => false,
				'premiere' => false,
				'not_found' => true,
				'scheduled_start' => false,
				'upcoming' => false,
			];
		}

		$videoItem = $data['items'][0];
		unset($data);

		$content = $videoItem['contentDetails'] ?? [];
		$live = $videoItem['liveStreamingDetails'] ?? null;
		$snippet = $videoItem['snippet'] ?? [];

		$cacheRow = self::$cache[$vid] ?? [];
		if (!is_array($cacheRow)) {
			$cacheRow = [];
		}
		$cacheRow['timestamp'] = $now;
		unset($cacheRow['api_error']);

		$duration = '';
		$is_premiere = false;
		$is_live = false;

		if (!isset($content['duration'])) {
			$is_premiere = true;
			$cacheRow['premiere'] = true;
		} else {
			try {
				$interval = new DateInterval((string)$content['duration']);
				$hours = $interval->h + ($interval->d * 24);
				$duration = $hours > 0
					? sprintf('%d:%02d:%02d', $hours, $interval->i, $interval->s)
					: sprintf('%d:%02d', $interval->i, $interval->s);
			} catch (\Throwable $e) {
				$duration = '0:00';
			}

			$cacheRow['duration'] = $duration;
			if (in_array($duration, $zero_durations, true)) {
				$is_live = true;
			}
		}

		$sched_start = false;
		$is_upcoming = false;

		if ($is_live || $is_premiere) {
			if ($live && !isset($live['actualStartTime']) && isset($live['scheduledStartTime'])) {
				$sched_start = (string)$live['scheduledStartTime'];
				$cacheRow['scheduledStartTime'] = $sched_start;
			}

			if (isset($snippet['liveBroadcastContent']) && $snippet['liveBroadcastContent'] === 'upcoming') {
				$is_upcoming = true;
				$cacheRow['liveBroadcastContent'] = 'upcoming';
			}
		}

		self::$cache[$vid] = $cacheRow;
		self::$cache_changed = true;

		$active_sched = false;
		if ($sched_start !== false && strtotime($sched_start) > $now) {
			$active_sched = $sched_start;
		}

		return [
			'duration' => $duration,
			'is_live' => $is_live,
			'premiere' => $is_premiere,
			'not_found' => false,
			'scheduled_start' => $active_sched,
			'upcoming' => $is_upcoming,
		];
	}

	/**
	 * Render YouTube duration or status badge if the entry is a YouTube video.
	 *
	 * @param FreshRSS_Entry $entry
	 * @return bool True if a YouTube badge was rendered, false if not a YouTube entry.
	 */
	public static function renderDuration(FreshRSS_Entry $entry): bool {
		$yt = self::getDetails($entry);
		if ($yt === null) {
			return false;
		}

		if ($yt['not_found']) {
			echo '<div class="duration summary">Duration not found</div>';
			return true;
		}
		if ($yt['scheduled_start'] && $yt['premiere']) {
			$formatted = date('Y-m-d H:i', strtotime($yt['scheduled_start']));
			echo '<div class="duration summary">Premiere scheduled: ' . $formatted . '</div>';
			return true;
		}
		if ($yt['scheduled_start']) {
			$formatted = date('Y-m-d H:i', strtotime($yt['scheduled_start']));
			echo '<div class="duration summary">Live scheduled: ' . $formatted . '</div>';
			return true;
		}
		if ($yt['upcoming']) {
			echo '<div class="duration summary">Live scheduled: Unknown</div>';
			return true;
		}
		if ($yt['premiere']) {
			echo '<div class="duration summary">Premiere</div>';
			return true;
		}
		if ($yt['is_live']) {
			echo '<div class="duration summary">Live</div>';
			return true;
		}
		if ($yt['duration'] !== '') {
			echo '<div class="duration summary">' . htmlspecialchars($yt['duration'], ENT_COMPAT, 'UTF-8') . '</div>';
			return true;
		}

		return false;
	}

	/**
	 * Render duration badge or fallback article summary.
	 *
	 * @param FreshRSS_Entry|null $entry
	 * @return bool True if a YouTube badge was rendered, false if standard summary was rendered.
	 */
	public static function renderSummary(?FreshRSS_Entry $entry): bool {
		if ($entry === null) {
			return false;
		}
		if (self::renderDuration($entry)) {
			return true;
		}

		// Fallback to standard summary
		echo '<div class="summary">' . trim(mb_substr(strip_tags($entry->content(false)), 0, 500, 'UTF-8')) . '</div>';
		return false;
	}

	/**
	 * Render entry header thumbnail with optional yt-thumbnail class for YouTube videos.
	 *
	 * @param FreshRSS_Entry|null $entry
	 * @param string $topline_thumbnail 'none', 'small', 'medium', 'large'
	 * @param bool $topline_summary Whether summary is displayed
	 * @param bool $lazyload Whether lazy loading is enabled
	 */
	public static function renderThumbnail(?FreshRSS_Entry $entry, string $topline_thumbnail, bool $topline_summary, bool $lazyload): void {
		if ($entry === null || $topline_thumbnail === 'none') {
			return;
		}
		$thumbnail = $entry->thumbnail();
		if ($thumbnail === null || empty($thumbnail['url']) || !is_string($thumbnail['url'])) {
			return;
		}
		$ytClass = self::ytThumbnailClass($entry);
		$smallClass = $topline_summary ? '' : 'small';
		$lazyAttr = $lazyload ? ' loading="lazy"' : '';

		echo '<li class="item thumbnail ' . htmlspecialchars($topline_thumbnail, ENT_COMPAT, 'UTF-8') . ' ' . $smallClass . '">';
		echo '<img src="' . htmlspecialchars($thumbnail['url'], ENT_COMPAT, 'UTF-8') . '" class="item-element' . $ytClass . '"' . $lazyAttr . ' alt="" />';
		echo '</li>';
	}

	/**
	 * Render feed website link, channel name, and favicon under the title.
	 * If the feed is from YouTube, the favicon is assigned the 'yt-favicon' class.
	 *
	 * @param FreshRSS_Feed|null $feed
	 * @param string $topline_website 'none', 'name', 'icon', or ''
	 * @param FreshRSS_Entry|null $entry Optional entry for additional YouTube detection
	 */
	public static function renderWebsite(?FreshRSS_Feed $feed, string $topline_website, ?FreshRSS_Entry $entry = null): void {
		if ($feed === null || $topline_website === 'none') {
			return;
		}

		$isYouTube = self::isYouTube($feed, $entry);
		$isPlaylist = self::isYouTubePlaylistFeed($feed);
		$useYtFavicon = $isYouTube && !$isPlaylist;

		$userConf = class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasUserConf()
			? FreshRSS_Context::userConf()
			: null;
		$showFavicons = ($userConf === null || $userConf->show_favicons) && 'name' !== $topline_website;
		$showName = 'icon' !== $topline_website;

		$itemClass = 'item website' . ($useYtFavicon ? ' yt-feed' : '');
		$filterTitle = function_exists('_t') ? _t('gen.action.filter') . ': ' . $feed->name() : $feed->name();
		$feedUrl = function_exists('_url') ? _url('index', 'index', 'get', 'f_' . $feed->id()) : '#';

		echo '<div class="' . $itemClass . '">';
		echo '<a href="' . $feedUrl . '" class="item-element" title="' . htmlspecialchars($filterTitle, ENT_COMPAT, 'UTF-8') . '">';

		if ($showFavicons) {
			$extraClass = $useYtFavicon ? ' yt-favicon' : '';
			echo '<img class="favicon' . $extraClass . '" src="' . $feed->favicon() . '" alt="✇" loading="lazy" />';
		}

		if ($showName) {
			echo '<span class="websiteName">' . htmlspecialchars($feed->name(), ENT_COMPAT, 'UTF-8') . '</span>';
		}

		echo '</a><br /></div>';
	}
}


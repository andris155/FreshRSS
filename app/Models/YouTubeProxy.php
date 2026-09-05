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

	// In-memory cache & runtime state
	public static ?array $cache = null;
	public static bool $cache_changed = false;
	public static bool $circuit_broken = false;
	private static bool $shutdown_registered = false;

	// Mod configuration loaded exclusively from config-youtube.php
	public static ?array $config = null;

	public static function loadConfig(): array {
		if (self::$config !== null) {
			return self::$config;
		}

		$candidates = [
			defined('DATA_PATH') ? DATA_PATH . '/config-youtube.php' : null,
			defined('FRESHRSS_PATH') ? FRESHRSS_PATH . '/data/config-youtube.php' : null,
			__DIR__ . '/../../data/config-youtube.php',
			'./data/config-youtube.php',
			defined('FRESHRSS_PATH') ? FRESHRSS_PATH . '/config-youtube.php' : null,
			__DIR__ . '/../../config-youtube.php',
			'./config-youtube.php',
			__DIR__ . '/config-youtube.php',
		];

		foreach ($candidates as $candidate) {
			if ($candidate !== null && is_file($candidate)) {
				$loaded = include $candidate;
				if (is_array($loaded)) {
					self::$config = $loaded;
					return self::$config;
				}
			}
		}

		self::$config = [];
		return self::$config;
	}

	public static function getApiKey(): string {
		$cfg = self::loadConfig();
		return (!empty($cfg['api_key']) && is_string($cfg['api_key'])) ? $cfg['api_key'] : '';
	}

	public static function isProxyEnabled(): bool {
		$cfg = self::loadConfig();
		if (isset($cfg['proxy_enabled']) && !$cfg['proxy_enabled']) {
			return false;
		}
		return !empty($cfg['proxy_url']) && is_string($cfg['proxy_url']) && $cfg['proxy_url'] !== 'none';
	}

	public static function getProxyKey(): string {
		$cfg = self::loadConfig();
		return (!empty($cfg['proxy_key']) && is_string($cfg['proxy_key'])) ? $cfg['proxy_key'] : '';
	}

	public static function getProxyUrl(): string {
		if (!self::isProxyEnabled()) {
			return '';
		}
		$cfg = self::loadConfig();
		return !empty($cfg['proxy_url']) && is_string($cfg['proxy_url'])
			? rtrim($cfg['proxy_url'], '/')
			: '';
	}

	public static function isLoggingEnabled(): bool {
		$cfg = self::loadConfig();
		return (isset($cfg['logging_enabled']) && is_bool($cfg['logging_enabled']))
			? $cfg['logging_enabled']
			: true;
	}

	public static function getMaxLogSize(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['maxLogSize']) && is_int($cfg['maxLogSize']) && $cfg['maxLogSize'] > 0)
			? $cfg['maxLogSize']
			: 1024 * 1024;
	}

	public static function getCleanupInterval(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['cleanup_interval']) && is_int($cfg['cleanup_interval']) && $cfg['cleanup_interval'] > 0)
			? $cfg['cleanup_interval']
			: 86400;
	}

	public static function getTtlNormal(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['ttl_normal']) && is_int($cfg['ttl_normal']) && $cfg['ttl_normal'] > 0)
			? $cfg['ttl_normal']
			: 90 * 86400;
	}

	public static function getTtlShort(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['ttl_short']) && is_int($cfg['ttl_short']) && $cfg['ttl_short'] > 0)
			? $cfg['ttl_short']
			: 86400;
	}

	public static function getTtlError(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['ttl_error']) && is_int($cfg['ttl_error']) && $cfg['ttl_error'] > 0)
			? $cfg['ttl_error']
			: 3600;
	}

	public static function getCircuitBreakerTtl(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['circuit_breaker_ttl']) && is_int($cfg['circuit_breaker_ttl']) && $cfg['circuit_breaker_ttl'] > 0)
			? $cfg['circuit_breaker_ttl']
			: 3600;
	}

	public static function getCacheMaxItems(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['cache_max_items']) && is_int($cfg['cache_max_items']) && $cfg['cache_max_items'] > 0)
			? $cfg['cache_max_items']
			: 5000;
	}

	// ==========================================
	// CIRCUIT BREAKER (Persistent & Fast)
	// ==========================================
	public static function isCircuitBroken(): bool {
		if (self::$circuit_broken) {
			return true;
		}
		self::initCache();
		$brokenUntil = (int)(self::$cache['__circuit_broken_until'] ?? 0);
		if ($brokenUntil > time()) {
			self::$circuit_broken = true;
			return true;
		}
		return false;
	}

	public static function tripCircuitBreaker(?int $duration = null): void {
		self::$circuit_broken = true;
		self::initCache();
		$duration = $duration ?? self::getCircuitBreakerTtl();
		self::$cache['__circuit_broken_until'] = time() + $duration;
		self::$cache_changed = true;
	}

	// ==========================================
	// CACHE MANAGEMENT (Atomic flock & Safe LRU)
	// ==========================================
	public static function getCacheFilePath(): string {
		return defined('CACHE_PATH') ? CACHE_PATH . '/youtube.json' : './data/cache/youtube.json';
	}

	public static function initCache(): void {
		if (self::$cache !== null) {
			return;
		}

		self::loadConfig();
		$file = self::getCacheFilePath();

		if (is_file($file)) {
			$fp = @fopen($file, 'rb');
			if ($fp) {
				if (flock($fp, LOCK_SH)) {
					$raw = stream_get_contents($fp);
					flock($fp, LOCK_UN);
					if (is_string($raw) && $raw !== '') {
						$decoded = json_decode($raw, true);
						if (is_array($decoded)) {
							self::$cache = $decoded;
						}
					}
				}
				fclose($fp);
			}
		}

		if (!is_array(self::$cache)) {
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
			if (str_starts_with((string)$key, '__')) {
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
			$is_zero = isset($row['duration']) && in_array($row['duration'], ['0:00', '00:00', '0:0'], true);

			$ttl = $is_error ? $ttl_error : (($is_not_found || $is_premiere || $is_live || $is_zero) ? $ttl_short : $ttl_normal);

			if ($age > $ttl) {
				unset(self::$cache[$key]);
				self::$cache_changed = true;
			}
		}

		self::pruneCache();
		self::$cache['__last_cleanup'] = $now;
		self::$cache_changed = true;
	}

	/**
	 * Enforce maximum cache capacity (LRU pruning) without data loss.
	 */
	private static function pruneCache(): void {
		if (!is_array(self::$cache)) {
			return;
		}

		$max_items = self::getCacheMaxItems();
		if (count(self::$cache) <= $max_items) {
			return;
		}

		// Preserve internal metadata keys during sorting
		$meta = [];
		foreach (self::$cache as $k => $v) {
			if (str_starts_with((string)$k, '__')) {
				$meta[$k] = $v;
				unset(self::$cache[$k]);
			}
		}

		uasort(self::$cache, static fn($a, $b) => ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0)));
		self::$cache = array_slice(self::$cache, -$max_items, null, true);

		foreach ($meta as $k => $v) {
			self::$cache[$k] = $v;
		}
	}

	/**
	 * Thread/Process-Safe Cache Save using atomic exclusive flock.
	 */
	public static function saveCache(): void {
		if (!self::$cache_changed || !is_array(self::$cache)) {
			return;
		}

		$file = self::getCacheFilePath();
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		$fp = @fopen($file, 'c+b');
		if ($fp) {
			if (flock($fp, LOCK_EX)) {
				$size = @filesize($file);
				$diskData = [];
				if ($size > 0) {
					rewind($fp);
					$onDiskRaw = stream_get_contents($fp);
					if (is_string($onDiskRaw) && $onDiskRaw !== '') {
						$decoded = json_decode($onDiskRaw, true);
						if (is_array($decoded)) {
							$diskData = $decoded;
						}
					}
				}

				// Merge: keep disk updates from other workers while applying our own
				self::$cache = array_merge($diskData, self::$cache);
				self::pruneCache();

				$json = json_encode(self::$cache, JSON_UNESCAPED_SLASHES);
				if (is_string($json)) {
					ftruncate($fp, 0);
					rewind($fp);
					fwrite($fp, $json);
					fflush($fp);
				}
				flock($fp, LOCK_UN);
			}
			fclose($fp);
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

		if (@file_exists($logFile) && (@filesize($logFile) ?: 0) > $maxLogSize) {
			@rename($logFile, $logFile . '.old');
		}

		$entry = date('Y-m-d H:i:s') . " | VideoID: {$vid} | Status: {$status} | URL: {$url} | Bytes: {$bytes}\n";
		@file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
	}

	public static function fetchApi(string $url, string $vid): ?string {
		if (self::isCircuitBroken()) {
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
				self::tripCircuitBreaker();
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

		if ($response === null && !self::$circuit_broken) {
			self::tripCircuitBreaker(300); // 5-minute cooldown on general network failure
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

		// Fast fallback without network call if circuit is broken
		if (self::isCircuitBroken()) {
			return 'hqdefault';
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

		if (!self::isProxyEnabled()) {
			return $url;
		}

		$proxyUrl = self::getProxyUrl();
		if ($proxyUrl === '') {
			return $url;
		}

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

	public static function extractVideoId(string $link): ?string {
		if ($link === '') {
			return null;
		}
		if (preg_match('#(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:embed/|v/|shorts/|live/|watch\?(?:.*&)?v=)|yt:video:)([a-zA-Z0-9_-]{11})#i', $link, $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Parse ISO-8601 duration into human-readable H:MM:SS or M:SS format.
	 */
	public static function parseDuration(string $isoDuration): string {
		if ($isoDuration === '' || $isoDuration === 'P0D' || $isoDuration === 'PT0S') {
			return '0:00';
		}
		try {
			$interval = new DateInterval($isoDuration);
			$hours = $interval->h + ($interval->d * 24) + ($interval->m * 30 * 24) + ($interval->y * 365 * 24);
			return $hours > 0
				? sprintf('%d:%02d:%02d', $hours, $interval->i, $interval->s)
				: sprintf('%d:%02d', $interval->i, $interval->s);
		} catch (\Throwable $e) {
			return '0:00';
		}
	}

	// ==========================================
	// YOUTUBE VIDEO DETAILS & BATCHING
	// ==========================================

	/**
	 * Batch fetch metadata for multiple video IDs or FreshRSS_Entry objects in chunks of 50.
	 *
	 * @param array<FreshRSS_Entry|string> $items
	 * @return array<string, array{duration:string,is_live:bool,premiere:bool,not_found:bool,scheduled_start:string|false,upcoming:bool}>
	 */
	public static function batchFetchDetails(array $items): array {
		self::initCache();
		$now = time();
		$results = [];
		$toFetch = [];

		foreach ($items as $item) {
			$vid = null;
			if ($item instanceof FreshRSS_Entry) {
				$vid = self::extractVideoId($item->link()) ?? self::extractVideoId($item->guid());
			} elseif (is_string($item)) {
				$vid = self::extractVideoId($item) ?? (strlen($item) === 11 ? $item : null);
			}

			if ($vid === null) {
				continue;
			}

			$cached = self::getCachedDetails($vid, $now);
			if ($cached !== null) {
				$results[$vid] = $cached;
			} else {
				$toFetch[$vid] = true;
			}
		}

		if (empty($toFetch) || self::isCircuitBroken()) {
			return $results;
		}

		$apiKey = self::getApiKey();
		if ($apiKey === '' || $apiKey === 'key') {
			return $results;
		}

		$chunks = array_chunk(array_keys($toFetch), 50);
		foreach ($chunks as $chunk) {
			$idParam = implode(',', $chunk);
			$apiUrl = "https://www.googleapis.com/youtube/v3/videos?id={$idParam}&part=contentDetails,liveStreamingDetails,snippet&key={$apiKey}";
			$response = self::fetchApi($apiUrl, 'batch_' . count($chunk));

			if ($response === null || $response === '') {
				foreach ($chunk as $vid) {
					if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
						self::$cache[$vid] = [];
					}
					self::$cache[$vid]['api_error'] = true;
					self::$cache[$vid]['timestamp'] = $now;
					self::$cache_changed = true;
				}
				continue;
			}

			$data = json_decode($response, true);
			$returnedIds = [];

			if (!empty($data['items']) && is_array($data['items'])) {
				foreach ($data['items'] as $videoItem) {
					$vid = $videoItem['id'] ?? null;
					if (!is_string($vid) || $vid === '') {
						continue;
					}
					$returnedIds[$vid] = true;
					$details = self::processVideoItem($vid, $videoItem, $now);
					$results[$vid] = $details;
				}
			}

			// Mark IDs not returned by API as not found (deleted/private)
			foreach ($chunk as $vid) {
				if (!isset($returnedIds[$vid])) {
					if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
						self::$cache[$vid] = [];
					}
					self::$cache[$vid]['duration'] = false;
					self::$cache[$vid]['timestamp'] = $now;
					self::$cache_changed = true;

					$results[$vid] = [
						'duration' => '',
						'is_live' => false,
						'premiere' => false,
						'not_found' => true,
						'scheduled_start' => false,
						'upcoming' => false,
					];
				}
			}
		}

		return $results;
	}

	/**
	 * Retrieve valid cached metadata for a video ID, or null if expired/missing.
	 */
	private static function getCachedDetails(string $vid, int $now): ?array {
		if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
			return null;
		}

		$item = self::$cache[$vid];
		$age = $now - (int)($item['timestamp'] ?? 0);
		$cached_dur = $item['duration'] ?? null;
		$is_not_found = ($cached_dur === false);
		$is_premiere = !empty($item['premiere']);
		$is_error = !empty($item['api_error']);
		$zero_durations = ['0:00', '00:00', '0:0'];
		$is_zero = is_string($cached_dur) && in_array($cached_dur, $zero_durations, true);
		$is_upcoming = !empty($item['liveBroadcastContent']) && $item['liveBroadcastContent'] === 'upcoming';

		$ttl = $is_error
			? self::getTtlError()
			: (($is_not_found || $is_premiere || $is_zero || $is_upcoming) ? self::getTtlShort() : self::getTtlNormal());

		if ($age >= $ttl) {
			return null;
		}

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

	/**
	 * Process raw YouTube API item and update in-memory cache.
	 */
	private static function processVideoItem(string $vid, array $videoItem, int $now): array {
		$content = $videoItem['contentDetails'] ?? [];
		$live = $videoItem['liveStreamingDetails'] ?? null;
		$snippet = $videoItem['snippet'] ?? [];

		$cacheRow = self::$cache[$vid] ?? [];
		if (!is_array($cacheRow)) {
			$cacheRow = [];
		}
		$cacheRow['timestamp'] = $now;
		unset($cacheRow['api_error']);

		// Auto-detect high-res thumbnail variant from API snippet to eliminate extra HEAD requests
		if (!empty($snippet['thumbnails']['maxres'])) {
			$cacheRow['thumbnail'] = 'maxresdefault';
		} elseif (!empty($snippet['thumbnails']['standard'])) {
			$cacheRow['thumbnail'] = 'sddefault';
		} elseif (!empty($snippet['thumbnails']['high'])) {
			$cacheRow['thumbnail'] = 'hqdefault';
		}

		$duration = '';
		$is_premiere = false;
		$is_live = false;
		$zero_durations = ['0:00', '00:00', '0:0'];

		if (!isset($content['duration'])) {
			$is_premiere = true;
			$cacheRow['premiere'] = true;
		} else {
			$duration = self::parseDuration((string)$content['duration']);
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
	 * @return array{duration:string,is_live:bool,premiere:bool,not_found:bool,scheduled_start:string|false,upcoming:bool}|null
	 */
	public static function getDetails(FreshRSS_Entry $entry): ?array {
		$vid = self::extractVideoId($entry->link()) ?? self::extractVideoId($entry->guid());
		if ($vid === null) {
			return null;
		}

		self::initCache();
		$now = time();
		$cached = self::getCachedDetails($vid, $now);
		if ($cached !== null) {
			return $cached;
		}

		if (self::isCircuitBroken()) {
			return null;
		}

		$apiKey = self::getApiKey();
		if ($apiKey === '' || $apiKey === 'key') {
			return null;
		}

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

		return self::processVideoItem($vid, $data['items'][0], $now);
	}

	/**
	 * Generate duration or status badge HTML for a YouTube video entry.
	 * Returns null if entry is not YouTube or duration is unavailable.
	 */
	public static function getDurationBadgeHtml(FreshRSS_Entry $entry): ?string {
		$yt = self::getDetails($entry);
		if ($yt === null || $yt['not_found']) {
			return null;
		}

		if ($yt['scheduled_start'] && $yt['premiere']) {
			$formatted = date('Y-m-d H:i', strtotime($yt['scheduled_start']));
			return '<div class="duration summary">Premiere scheduled: ' . htmlspecialchars($formatted, ENT_COMPAT, 'UTF-8') . '</div>';
		}
		if ($yt['scheduled_start']) {
			$formatted = date('Y-m-d H:i', strtotime($yt['scheduled_start']));
			return '<div class="duration summary">Live scheduled: ' . htmlspecialchars($formatted, ENT_COMPAT, 'UTF-8') . '</div>';
		}
		if ($yt['upcoming']) {
			return '<div class="duration summary">Live scheduled: Unknown</div>';
		}
		if ($yt['premiere']) {
			return '<div class="duration summary">Premiere</div>';
		}
		if ($yt['is_live']) {
			return '<div class="duration summary">Live</div>';
		}
		if ($yt['duration'] !== '') {
			return '<div class="duration summary">' . htmlspecialchars($yt['duration'], ENT_COMPAT, 'UTF-8') . '</div>';
		}

		return null;
	}

	/**
	 * Render YouTube duration or status badge if the entry is a YouTube video.
	 *
	 * @param FreshRSS_Entry $entry
	 * @return bool True if a YouTube badge was rendered, false if not a YouTube entry.
	 */
	public static function renderDuration(FreshRSS_Entry $entry): bool {
		$badgeHtml = self::getDurationBadgeHtml($entry);
		if ($badgeHtml !== null) {
			echo $badgeHtml;
			return true;
		}
		return false;
	}
}

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
	public static ?string $watchTime = null;

	// Reusable cURL handles for keep-alive connection pooling
	private static mixed $apiCurl = null;
	private static mixed $thumbCurl = null;

	public const ZERO_DURATIONS = ['0:00', '00:00', '0:0'];

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
		return (!empty($cfg['api_key']) && is_string($cfg['api_key'])) ? $cfg['api_key'] : 'key';
	}

	public static function isProxyEnabled(): bool {
		$cfg = self::loadConfig();
		return isset($cfg['proxy_enabled']) ? (bool)$cfg['proxy_enabled'] : true;
	}

	public static function isYoutubeImageModificationEnabled(): bool {
		$cfg = self::loadConfig();
		return isset($cfg['youtube_image_modification']) ? (bool)$cfg['youtube_image_modification'] : true;
	}

	public static function isYoutubeDurationEnabled(): bool {
		$cfg = self::loadConfig();
		return isset($cfg['youtube_duration_enabled']) ? (bool)$cfg['youtube_duration_enabled'] : true;
	}

	public static function isWatchTimeEnabled(): bool {
		if (!self::isYoutubeDurationEnabled()) {
			return false;
		}
		$cfg = self::loadConfig();
		return isset($cfg['youtube_watch_time']) ? (bool)$cfg['youtube_watch_time'] : true;
	}

	public static function getWatchTimeLimit(): int {
		$cfg = self::loadConfig();
		$limit = $cfg['youtube_watch_time_limit'] ?? 2000;
		return (is_int($limit) && $limit > 0) ? $limit : 2000;
	}

	public static function getProxyKey(): string {
		$cfg = self::loadConfig();
		return (!empty($cfg['proxy_key']) && is_string($cfg['proxy_key'])) ? $cfg['proxy_key'] : 'key';
	}

	public static function getProxyUrl(): string {
		$cfg = self::loadConfig();
		return (!empty($cfg['proxy_url']) && is_string($cfg['proxy_url']))
			? rtrim($cfg['proxy_url'], '/')
			: 'https://freshrss.lan/proxy';
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

	public static function getCacheMaxItems(): int {
		$cfg = self::loadConfig();
		return (isset($cfg['cache_max_items']) && is_int($cfg['cache_max_items']) && $cfg['cache_max_items'] > 0)
			? $cfg['cache_max_items']
			: 5000;
	}

	public static function getCacheFile(): string {
		if (defined('CACHE_PATH') && is_string(CACHE_PATH) && CACHE_PATH !== '') {
			return CACHE_PATH . '/youtube.json';
		}
		if (defined('DATA_PATH') && is_string(DATA_PATH) && DATA_PATH !== '') {
			return DATA_PATH . '/cache/youtube.json';
		}
		$dir = __DIR__ . '/../../data/cache';
		if (is_dir($dir)) {
			return $dir . '/youtube.json';
		}
		return './data/cache/youtube.json';
	}

	public static function getLogFile(): string {
		if (defined('CACHE_PATH') && is_string(CACHE_PATH) && CACHE_PATH !== '') {
			return CACHE_PATH . '/youtube_api.log';
		}
		if (defined('DATA_PATH') && is_string(DATA_PATH) && DATA_PATH !== '') {
			return DATA_PATH . '/cache/youtube_api.log';
		}
		$dir = __DIR__ . '/../../data/cache';
		if (is_dir($dir)) {
			return $dir . '/youtube_api.log';
		}
		return './data/cache/youtube_api.log';
	}

	// ==========================================
	// CACHE MANAGEMENT (Leak-Proof & Atomic)
	// ==========================================
	public static function initCache(): void {
		if (self::$cache !== null) {
			return;
		}

		self::loadConfig();

		$file = self::getCacheFile();
		if (is_file($file)) {
			$fp = @fopen($file, 'rb');
			if ($fp !== false) {
				$locked = flock($fp, LOCK_SH);
				$raw = stream_get_contents($fp);
				if ($locked) {
					flock($fp, LOCK_UN);
				}
				fclose($fp);
				$decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
				self::$cache = is_array($decoded) ? $decoded : [];
			}
		}

		if (!is_array(self::$cache)) {
			self::$cache = [];
		}

		if (!self::$shutdown_registered) {
			self::$shutdown_registered = true;
			register_shutdown_function(static function (): void {
				FreshRSS_YouTubeProxy::saveCache();
				FreshRSS_YouTubeProxy::closeCurlHandles();
			});
		}
	}

	public static function closeCurlHandles(): void {
		if (self::$apiCurl !== null) {
			if (is_resource(self::$apiCurl) || self::$apiCurl instanceof \CurlHandle) {
				@curl_close(self::$apiCurl);
			}
			self::$apiCurl = null;
		}
		if (self::$thumbCurl !== null) {
			if (is_resource(self::$thumbCurl) || self::$thumbCurl instanceof \CurlHandle) {
				@curl_close(self::$thumbCurl);
			}
			self::$thumbCurl = null;
		}
	}

	public static function pruneCache(int $max_items = 0): void {
		if (!is_array(self::$cache)) {
			return;
		}
		if ($max_items <= 0) {
			$max_items = self::getCacheMaxItems();
		}
		if (count(self::$cache) > $max_items) {
			$cleanups = self::$cache['__last_cleanup'] ?? null;
			unset(self::$cache['__last_cleanup']);

			uasort(self::$cache, static fn($a, $b) => ((int)(is_array($a) ? ($a['timestamp'] ?? 0) : 0)) <=> ((int)(is_array($b) ? ($b['timestamp'] ?? 0) : 0)));
			self::$cache = array_slice(self::$cache, -$max_items, null, true);

			if ($cleanups !== null) {
				self::$cache['__last_cleanup'] = $cleanups;
			}
			self::$cache_changed = true;
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
			$has_sched = !empty($row['scheduledStartTime']);
			$is_zero = isset($row['duration']) && in_array($row['duration'], self::ZERO_DURATIONS, true);

			$ttl = $is_error ? $ttl_error : (($is_not_found || $is_premiere || $is_live || $has_sched || $is_zero) ? $ttl_short : $ttl_normal);

			if ($age > $ttl) {
				unset(self::$cache[$key]);
				self::$cache_changed = true;
			}
		}

		// Enforce maximum cache capacity (LRU pruning) to prevent unbounded memory growth
		self::pruneCache();

		self::$cache['__last_cleanup'] = $now;
		self::$cache_changed = true;
	}

	public static function saveCache(): void {
		if (!self::$cache_changed || !is_array(self::$cache)) {
			return;
		}

		$file = self::getCacheFile();
		$dir = dirname($file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0777, true);
		}

		$fp = @fopen($file, 'c+');
		if ($fp === false) {
			return;
		}

		$locked = flock($fp, LOCK_EX);
		$onDiskRaw = stream_get_contents($fp);
		if (is_string($onDiskRaw) && $onDiskRaw !== '') {
			$onDisk = json_decode($onDiskRaw, true);
			if (is_array($onDisk)) {
				self::$cache = array_replace($onDisk, self::$cache);
			}
			unset($onDisk);
		}
		unset($onDiskRaw);

		self::cleanCache();
		self::pruneCache();

		$json = json_encode(self::$cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (is_string($json)) {
			ftruncate($fp, 0);
			rewind($fp);
			fwrite($fp, $json);
			fflush($fp);
			unset($json);
		}
		if ($locked) {
			flock($fp, LOCK_UN);
		}
		self::$cache_changed = false;
		fclose($fp);
	}

	// ==========================================
	// LOGGING & API CALLS
	// ==========================================
	public static function logApi(string $vid, string $status, string $url, int $bytes): void {
		if (!self::isLoggingEnabled()) {
			return;
		}

		$logFile = self::getLogFile();
		$maxLogSize = self::getMaxLogSize();

		clearstatcache(true, $logFile);
		if (@file_exists($logFile) && (@filesize($logFile) ?: 0) > $maxLogSize) {
			@rename($logFile, $logFile . '.old');
		}

		$cleanUrl = preg_replace('/([?&]key=)[^&]+/', '$1***', $url) ?? $url;
		$entry = date('Y-m-d H:i:s') . " | VideoID: {$vid} | Status: {$status} | URL: {$cleanUrl} | Bytes: {$bytes}\n";
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
			if (self::$apiCurl === null || (!is_resource(self::$apiCurl) && !(self::$apiCurl instanceof \CurlHandle))) {
				self::$apiCurl = curl_init();
				curl_setopt_array(self::$apiCurl, [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CONNECTTIMEOUT => 2,
					CURLOPT_TIMEOUT => 4,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS => 2,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_SSL_VERIFYHOST => 2,
					CURLOPT_USERAGENT => 'FreshRSS/YouTubeFetcher',
					CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2TLS')
						? CURL_HTTP_VERSION_2TLS
						: (defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : 0),
					CURLOPT_TCP_KEEPALIVE => 1,
					CURLOPT_TCP_KEEPIDLE => 60,
					CURLOPT_TCP_KEEPINTVL => 30,
					CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
				]);
			}
			$ch = self::$apiCurl;
			curl_setopt($ch, CURLOPT_URL, $url);
			$result = curl_exec($ch);
			$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

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
			$httpCode = 0;
			$headers = function_exists('http_get_last_response_headers')
				? http_get_last_response_headers()
				: ($http_response_header ?? $GLOBALS['http_response_header'] ?? null);
			if (is_array($headers)) {
				foreach ($headers as $header) {
					if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $hm)) {
						$httpCode = (int)$hm[1];
					}
				}
			}
			if ($result !== false && $httpCode === 200) {
				$response = (string)$result;
				$status = 'OK';
				$bytes = strlen($response);
			} elseif ($httpCode === 403 || $httpCode === 429) {
				self::$circuit_broken = true;
				$status = 'FORBIDDEN_' . $httpCode;
			} elseif ($result === false) {
				$status = 'FETCH_ERROR';
			} else {
				$status = 'HTTP_' . $httpCode;
			}
		}

		self::logApi($vid, $status, $url, $bytes);

		return $response;
	}

	// ==========================================
	// THUMBNAILS & PROXY
	// ==========================================
	public static function getThumbnailVariant(string $vid, string $cdn = 'i'): string {
		if (!self::isYoutubeImageModificationEnabled()) {
			return 'mqdefault';
		}

		self::initCache();

		if (!empty(self::$cache[$vid]['thumbnail']) && is_string(self::$cache[$vid]['thumbnail'])) {
			return self::$cache[$vid]['thumbnail'];
		}

		$hq720 = "https://{$cdn}.ytimg.com/vi/{$vid}/hq720.jpg";
		$variant = 'mqdefault';

		if (function_exists('curl_init')) {
			if (self::$thumbCurl === null || (!is_resource(self::$thumbCurl) && !(self::$thumbCurl instanceof \CurlHandle))) {
				self::$thumbCurl = curl_init();
				curl_setopt_array(self::$thumbCurl, [
					CURLOPT_NOBODY => true,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_CONNECTTIMEOUT => 1,
					CURLOPT_TIMEOUT => 2,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_MAXREDIRS => 2,
					CURLOPT_USERAGENT => 'FreshRSS/ThumbCheck',
					CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2TLS')
						? CURL_HTTP_VERSION_2TLS
						: (defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : 0),
					CURLOPT_TCP_KEEPALIVE => 1,
					CURLOPT_TCP_KEEPIDLE => 60,
					CURLOPT_TCP_KEEPINTVL => 30,
					CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
				]);
			}
			$ch = self::$thumbCurl;
			curl_setopt($ch, CURLOPT_URL, $hq720);
			curl_exec($ch);
			$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$contentLength = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

			if ($httpCode === 200 && ($contentLength > 1000 || $contentLength === -1)) {
				$variant = 'hq720';
			}
		} else {
			$context = stream_context_create([
				'http' => [
					'timeout' => 1.0,
					'ignore_errors' => true,
					'header' => "User-Agent: FreshRSS/ThumbCheck\r\n",
				],
			]);
			$chunk = @file_get_contents($hq720, false, $context, 0, 4096);
			if ($chunk !== false && strlen($chunk) > 0) {
				$size = function_exists('getimagesizefromstring') ? @getimagesizefromstring($chunk) : false;
				if ($size !== false && !($size[0] === 120 && $size[1] === 90)) {
					$variant = 'hq720';
				}
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
		if ($url === '' || str_starts_with($url, 'data:')) {
			return $url;
		}

		if (str_starts_with($url, '//')) {
			$url = 'https:' . $url;
		} elseif (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
			return $url;
		}

		$isProxy = self::isProxyEnabled();
		$isYtMod = self::isYoutubeImageModificationEnabled();

		// If both proxy and YouTube image modification are disabled, return original URL as-is
		if (!$isProxy && !$isYtMod) {
			return $url;
		}

		$targetUrl = $url;

		// YouTube thumbnail replacement (upgrades to hq720 variant if available, cached in youtube.json)
		if ($isYtMod && (str_contains($url, 'ytimg.com') || str_contains($url, 'img.youtube.com')) && preg_match('#^https?://(?:(i\d*)\.ytimg\.com|img\.youtube\.com)/vi/([a-zA-Z0-9_-]{11})/(?:hqdefault|maxresdefault|sddefault|mqdefault|default|0)\.jpg(?:\?.*)?$#i', $url, $m)) {
			$cdn = (!empty($m[1])) ? $m[1] : 'i';
			$vid = $m[2];
			$variant = self::getThumbnailVariant($vid, $cdn);
			$targetUrl = "https://{$cdn}.ytimg.com/vi/{$vid}/{$variant}.jpg";
		}

		// If proxy is disabled, return target URL directly (with or without YouTube thumbnail modification)
		if (!$isProxy) {
			return $targetUrl;
		}

		$proxyUrl = self::getProxyUrl();
		$proxyKey = self::getProxyKey();

		// Prevent double-proxying
		if ($proxyUrl === '' || str_starts_with($targetUrl, $proxyUrl) || ($proxyKey !== '' && str_contains($targetUrl, 'key=' . $proxyKey))) {
			return $targetUrl;
		}

		// Proxy the target URL
		return "{$proxyUrl}?key={$proxyKey}&url={$targetUrl}";
	}

	public static function extractVideoId(string $link): ?string {
		if ($link === '') {
			return null;
		}
		if (!str_contains($link, 'youtu') && !str_contains($link, 'yt:') && !str_contains($link, 'ytimg')) {
			return (strlen($link) === 11 && preg_match('/^[a-zA-Z0-9_-]{11}$/', $link)) ? $link : null;
		}
		if (preg_match('/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:embed\/|v\/|shorts\/|live\/|watch\?(?:.*&)?v=))([a-zA-Z0-9_-]{11})/i', $link, $m)) {
			return $m[1];
		}
		if (preg_match('/(?:^|:)yt:video:([a-zA-Z0-9_-]{11})/i', $link, $m)) {
			return $m[1];
		}
		if (preg_match('/(?:ytimg\.com|img\.youtube\.com)\/vi\/([a-zA-Z0-9_-]{11})/i', $link, $m)) {
			return $m[1];
		}
		return null;
	}

	// ==========================================
	// YOUTUBE VIDEO DETAILS & RENDERING
	// ==========================================

	/**
	 * @return array{duration:string,is_live:bool,premiere:bool,not_found:bool,scheduled_start:string|false,upcoming:bool}|null
	 */
	public static function getDetails(FreshRSS_Entry $entry): ?array {
		if (!self::isYoutubeDurationEnabled()) {
			return null;
		}

		$vid = self::extractVideoId($entry->link());
		if ($vid === null) {
			$vid = self::extractVideoId($entry->guid());
		}
		if ($vid === null) {
			return null;
		}

		self::initCache();
		$now = time();

		$ttl_normal = self::getTtlNormal();
		$ttl_short = self::getTtlShort();
		$ttl_error = self::getTtlError();

		$has_details = isset(self::$cache[$vid])
			&& is_array(self::$cache[$vid])
			&& (array_key_exists('duration', self::$cache[$vid])
				|| !empty(self::$cache[$vid]['premiere'])
				|| !empty(self::$cache[$vid]['api_error']));

		if ($has_details) {
			$item = self::$cache[$vid];
			$age = $now - (int)($item['timestamp'] ?? 0);

			$cached_dur = $item['duration'] ?? null;
			$is_not_found = ($cached_dur === false);
			$is_premiere = !empty($item['premiere']);
			$is_error = !empty($item['api_error']);
			$is_zero = is_string($cached_dur) && in_array($cached_dur, self::ZERO_DURATIONS, true);
			$is_upcoming = !empty($item['liveBroadcastContent']) && $item['liveBroadcastContent'] === 'upcoming';

			$has_sched = !empty($item['scheduledStartTime']);

			$ttl = $is_error ? $ttl_error : (($is_not_found || $is_premiere || $is_zero || $is_upcoming || $has_sched) ? $ttl_short : $ttl_normal);

			if ($age < $ttl) {
				if ($is_error) {
					return null;
				}
				return self::formatItemDetails($item, $now);
			}
		}

		if (self::$circuit_broken) {
			return null;
		}

		$candidates = [$vid];
		if (class_exists('FreshRSS_Factory', false)) {
			try {
				$entryDAO = FreshRSS_Factory::createEntryDao();
				if (method_exists($entryDAO, 'listWhere')) {
					foreach ($entryDAO->listWhere('a', 0, limit: 50) as $recentEntry) {
						if ($recentEntry instanceof FreshRSS_Entry) {
							$cand = self::extractVideoId($recentEntry->link()) ?? self::extractVideoId($recentEntry->guid());
							if ($cand !== null) {
								$candItem = self::$cache[$cand] ?? null;
								$hasCandDetails = is_array($candItem) && (array_key_exists('duration', $candItem) || !empty($candItem['premiere']) || !empty($candItem['api_error']));
								if (!$hasCandDetails) {
									$candidates[] = $cand;
									if (count($candidates) >= 50) {
										break;
									}
								}
							}
						}
					}
				}
			} catch (\Throwable $e) {
				// Non-fatal, fallback to [$vid]
			}
		}

		self::fetchBatch($candidates);

		if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
			return null;
		}

		$item = self::$cache[$vid];
		if (!empty($item['api_error'])) {
			return null;
		}

		return self::formatItemDetails($item, $now);
	}

	/**
	 * Format cached video metadata into standard return array.
	 *
	 * @param array<string,mixed> $item
	 * @param int $now
	 * @return array{duration:string,is_live:bool,premiere:bool,not_found:bool,scheduled_start:string|false,upcoming:bool}
	 */
	private static function formatItemDetails(array $item, int $now): array {
		$cached_dur = $item['duration'] ?? null;
		$is_not_found = ($cached_dur === false);
		$is_premiere = !empty($item['premiere']);
		$is_live = is_string($cached_dur) && in_array($cached_dur, self::ZERO_DURATIONS, true);
		$raw_upcoming = !empty($item['liveBroadcastContent']) && $item['liveBroadcastContent'] === 'upcoming';

		$sched = false;
		$sched_time = (!empty($item['scheduledStartTime']) && is_string($item['scheduledStartTime']))
			? strtotime($item['scheduledStartTime'])
			: false;

		$is_upcoming = false;
		if ($sched_time !== false) {
			if ($sched_time > $now) {
				$sched = $item['scheduledStartTime'];
				$is_upcoming = true;
			} else {
				// Scheduled start time has passed -> video has started (live or premiere)
				if (!$is_premiere) {
					$is_live = true;
				}
			}
		} elseif ($raw_upcoming) {
			// Upcoming, but scheduled start time is unknown
			$is_upcoming = true;
		}

		return [
			'duration' => is_string($cached_dur) ? $cached_dur : '',
			'is_live' => $is_live,
			'premiere' => $is_premiere,
			'not_found' => $is_not_found,
			'scheduled_start' => $sched,
			'upcoming' => $is_upcoming,
		];
	}

	/**
	 * Parse a single YouTube API video item and update in-memory cache row.
	 *
	 * @param array<string,mixed> $videoItem
	 * @param int $now
	 * @return array<string,mixed> Cache row
	 */
	public static function parseVideoItem(array $videoItem, int $now): array {
		$vid = (string)($videoItem['id'] ?? '');
		$content = (array)($videoItem['contentDetails'] ?? []);
		$snippet = (array)($videoItem['snippet'] ?? []);
		$liveDetails = (array)($videoItem['liveStreamingDetails'] ?? []);

		$cacheRow = (isset(self::$cache[$vid]) && is_array(self::$cache[$vid])) ? self::$cache[$vid] : [];
		$cacheRow['timestamp'] = $now;
		unset($cacheRow['api_error']);

		// 1. Duration parsing
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
			if (in_array($duration, self::ZERO_DURATIONS, true)) {
				$is_live = true;
			} else {
				unset($cacheRow['premiere'], $cacheRow['scheduledStartTime'], $cacheRow['liveBroadcastContent']);
			}
		}

		// 2. Premiere / Live Stream details
		if ($is_live || $is_premiere) {
			$sched_start = false;
			if (!empty($liveDetails['scheduledStartTime']) && !isset($liveDetails['actualStartTime'])) {
				$sched_start = (string)$liveDetails['scheduledStartTime'];
			} elseif (!empty($cacheRow['scheduledStartTime']) && !isset($liveDetails['actualStartTime']) && empty($cacheRow['actualStartTime'])) {
				$sched_start = $cacheRow['scheduledStartTime'];
			}

			if (!empty($liveDetails['actualStartTime'])) {
				$cacheRow['actualStartTime'] = (string)$liveDetails['actualStartTime'];
				$is_upcoming = false;
			} elseif (!empty($cacheRow['actualStartTime'])) {
				$is_upcoming = false;
			} else {
				$is_upcoming = (!empty($snippet['liveBroadcastContent']) && $snippet['liveBroadcastContent'] === 'upcoming')
					|| (!empty($cacheRow['liveBroadcastContent']) && $cacheRow['liveBroadcastContent'] === 'upcoming');
			}

			$cacheRow['scheduledStartTime'] = $sched_start;
			$cacheRow['liveBroadcastContent'] = $is_upcoming ? 'upcoming' : ($is_live ? 'live' : false);
		}

		// 3. Zero-HTTP Thumbnail Derivation
		if (self::isYoutubeImageModificationEnabled() && isset($snippet['thumbnails']) && is_array($snippet['thumbnails'])) {
			$cacheRow['thumbnail'] = isset($snippet['thumbnails']['maxres']) ? 'hq720' : 'mqdefault';
		}

		return $cacheRow;
	}

	/**
	 * Batch fetch metadata for multiple YouTube video IDs in chunks of up to 50.
	 *
	 * @param list<string> $vids
	 */
	public static function fetchBatch(array $vids): void {
		if (empty($vids) || self::$circuit_broken) {
			return;
		}

		$apiKey = static::getApiKey();
		if ($apiKey === '' || $apiKey === 'key') {
			return;
		}

		self::initCache();
		$now = time();
		$ttl_normal = self::getTtlNormal();
		$ttl_short = self::getTtlShort();
		$ttl_error = self::getTtlError();

		// Deduplicate and filter out unexpired cached IDs that already have video details
		$toFetch = [];
		foreach (array_unique($vids) as $vid) {
			if (!is_string($vid) || strlen($vid) !== 11) {
				continue;
			}
			if (isset(self::$cache[$vid]) && is_array(self::$cache[$vid])) {
				$item = self::$cache[$vid];
				$hasDetails = array_key_exists('duration', $item) || !empty($item['premiere']) || !empty($item['api_error']);
				if ($hasDetails) {
					$age = $now - (int)($item['timestamp'] ?? 0);
					$is_error = !empty($item['api_error']);
					$is_short = (isset($item['duration']) && $item['duration'] === false)
						|| !empty($item['premiere'])
						|| (!empty($item['liveBroadcastContent']) && $item['liveBroadcastContent'] === 'upcoming')
						|| !empty($item['scheduledStartTime'])
						|| (isset($item['duration']) && in_array($item['duration'], self::ZERO_DURATIONS, true));
					$ttl = $is_error ? $ttl_error : ($is_short ? $ttl_short : $ttl_normal);
					if ($age < $ttl) {
						continue;
					}
				}
			}
			$toFetch[] = $vid;
		}

		if (empty($toFetch)) {
			return;
		}

		// Process in chunks of 50 (YouTube Data API limit per call)
		$chunks = array_chunk($toFetch, 50);
		foreach ($chunks as $chunk) {
			if (self::$circuit_broken) {
				break;
			}

			$idList = implode(',', $chunk);
			$apiUrl = "https://www.googleapis.com/youtube/v3/videos?id={$idList}&part=contentDetails,liveStreamingDetails,snippet&key={$apiKey}";
			$response = static::fetchApi($apiUrl, count($chunk) === 1 ? $chunk[0] : 'batch_' . count($chunk));

			if ($response === null || $response === '') {
				foreach ($chunk as $vid) {
					if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
						self::$cache[$vid] = [];
					}
					self::$cache[$vid]['api_error'] = true;
					self::$cache[$vid]['timestamp'] = $now;
				}
				self::$cache_changed = true;
				continue;
			}

			$data = json_decode($response, true);
			unset($response);

			if (!is_array($data) || isset($data['error'])) {
				foreach ($chunk as $vid) {
					if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
						self::$cache[$vid] = [];
					}
					self::$cache[$vid]['api_error'] = true;
					self::$cache[$vid]['timestamp'] = $now;
				}
				self::$cache_changed = true;
				continue;
			}

			$returnedVids = [];
			if (!empty($data['items']) && is_array($data['items'])) {
				foreach ($data['items'] as $videoItem) {
					if (!is_array($videoItem) || empty($videoItem['id'])) {
						continue;
					}
					$vid = (string)$videoItem['id'];
					$returnedVids[$vid] = true;
					self::$cache[$vid] = self::parseVideoItem($videoItem, $now);
				}
			}
			unset($data);

			// Any ID requested but not returned by YouTube is not found (deleted/private)
			foreach ($chunk as $vid) {
				if (!isset($returnedVids[$vid])) {
					if (!isset(self::$cache[$vid]) || !is_array(self::$cache[$vid])) {
						self::$cache[$vid] = [];
					}
					self::$cache[$vid]['duration'] = false;
					self::$cache[$vid]['timestamp'] = $now;
				}
			}

			self::$cache_changed = true;
		}

		if (count(self::$cache) > self::getCacheMaxItems()) {
			self::pruneCache();
		}
	}

	/**
	 * Warm the YouTube cache by querying recent entries from the database.
	 *
	 * @param int $limit Maximum entries to scan
	 * @return int Number of video IDs queued for fetching
	 */
	public static function warmCache(int $limit = 100): int {
		if (!class_exists('FreshRSS_Factory', false)) {
			return 0;
		}

		$vids = [];
		try {
			$entryDAO = FreshRSS_Factory::createEntryDao();
			if (method_exists($entryDAO, 'listWhere')) {
				foreach ($entryDAO->listWhere('a', 0, limit: $limit) as $entry) {
					if ($entry instanceof FreshRSS_Entry) {
						$vid = self::extractVideoId($entry->link()) ?? self::extractVideoId($entry->guid());
						if ($vid !== null) {
							$vids[] = $vid;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			return 0;
		}

		$vids = array_values(array_unique($vids));
		self::fetchBatch($vids);
		return count($vids);
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
		if ($yt['scheduled_start']) {
			$ts = strtotime($yt['scheduled_start']);
			$formatted = ($ts !== false) ? date('Y-m-d H:i', $ts) : 'Unknown';
			$prefix = $yt['premiere'] ? 'Premiere' : 'Live';
			echo '<div class="duration summary">' . $prefix . ' scheduled: ' . $formatted . '</div>';
			return true;
		}
		if ($yt['upcoming']) {
			$prefix = $yt['premiere'] ? 'Premiere' : 'Live';
			echo '<div class="duration summary">' . $prefix . ' scheduled: Unknown</div>';
			return true;
		}
		if ($yt['duration'] !== '' && !$yt['is_live']) {
			echo '<div class="duration summary">' . htmlspecialchars($yt['duration'], ENT_COMPAT, 'UTF-8') . '</div>';
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

		return false;
	}

	/**
	 * Convert a YouTube duration string (H:MM:SS, M:SS, SS) to total seconds.
	 *
	 * @param string $dur
	 * @return int Total seconds, or 0 if invalid or zero-duration.
	 */
	public static function durationToSeconds(string $dur): int {
		$dur = trim($dur);
		if ($dur === '' || in_array($dur, self::ZERO_DURATIONS, true)) {
			return 0;
		}
		$parts = explode(':', $dur);
		$count = count($parts);
		foreach ($parts as $p) {
			if (!ctype_digit($p)) {
				return 0;
			}
		}
		if ($count === 3) {
			return ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
		}
		if ($count === 2) {
			return ((int)$parts[0] * 60) + (int)$parts[1];
		}
		if ($count === 1) {
			return (int)$parts[0];
		}
		return 0;
	}

	/**
	 * Format total seconds into a compact, human-readable watch time string.
	 * Formats as 'd h m' (days omitted if 0). Examples: '1d 2h 15m', '2h 15m', '45m', '2m 30s', '35s'.
	 *
	 * @param int $totalSeconds
	 * @return string Formatted watch time or empty string if 0.
	 */
	public static function formatSecondsToWatchTime(int $totalSeconds): string {
		if ($totalSeconds <= 0) {
			return '';
		}

		if ($totalSeconds >= 300) {
			$totalSeconds = ($totalSeconds % 60 >= 30)
				? (intdiv($totalSeconds, 60) + 1) * 60
				: intdiv($totalSeconds, 60) * 60;
		}

		$days = intdiv($totalSeconds, 86400);
		$hours = intdiv($totalSeconds % 86400, 3600);
		$minutes = intdiv($totalSeconds % 3600, 60);
		$seconds = $totalSeconds % 60;

		$parts = [];
		if ($days > 0) {
			$parts[] = $days . 'd';
		}
		if ($hours > 0) {
			$parts[] = $hours . 'h';
		}
		if ($minutes > 0) {
			$parts[] = $minutes . 'm';
		}

		if (!empty($parts)) {
			if ($days === 0 && $hours === 0 && $minutes < 5 && $seconds > 0) {
				$parts[] = $seconds . 's';
			}
			return implode(' ', $parts);
		}

		return $seconds . 's';
	}

	/**
	 * Calculate total watch time in seconds for a list of video IDs using cache and optional batch fetch.
	 *
	 * @param list<string> $vids
	 * @param bool $allowFetch Whether to fetch uncached videos from YouTube API
	 * @return int Total seconds
	 */
	public static function calculateWatchTimeFromVids(array $vids, bool $allowFetch = true): int {
		self::initCache();
		$uniqueVids = array_values(array_unique($vids));
		$uncached = [];
		$totalSeconds = 0;

		foreach ($uniqueVids as $vid) {
			$item = self::$cache[$vid] ?? null;
			$hasDetails = is_array($item) && (
				array_key_exists('duration', $item)
				|| !empty($item['premiere'])
				|| !empty($item['api_error'])
			);

			if ($hasDetails) {
				$dur = $item['duration'] ?? null;
				if (is_string($dur)) {
					$totalSeconds += self::durationToSeconds($dur);
				}
			} else {
				$uncached[] = $vid;
			}
		}

		if ($allowFetch && !empty($uncached) && !self::$circuit_broken && self::getApiKey() !== 'key' && self::getApiKey() !== '') {
			$batch = array_slice($uncached, 0, 50);
			self::fetchBatch($batch);
			foreach ($batch as $vid) {
				$item = self::$cache[$vid] ?? null;
				if (is_array($item)) {
					$dur = $item['duration'] ?? null;
					if (is_string($dur)) {
						$totalSeconds += self::durationToSeconds($dur);
					}
				}
			}
		}

		return $totalSeconds;
	}

	/**
	 * Render the total unread YouTube watch time.
	 *
	 * @return string HTML span containing formatted watch time or empty string.
	 */
	public static function renderWatchTime(): string {
		if (!self::isWatchTimeEnabled()) {
			return '';
		}

		if (self::$watchTime !== null) {
			return self::$watchTime;
		}

		if (class_exists('FreshRSS_Context', false) && isset(FreshRSS_Context::$total_unread) && FreshRSS_Context::$total_unread <= 0) {
			self::$watchTime = '';
			return '';
		}

		if (!class_exists('FreshRSS_Factory', false)) {
			self::$watchTime = '';
			return '';
		}

		self::initCache();

		$totalUnread = (class_exists('FreshRSS_Context', false) && isset(FreshRSS_Context::$total_unread)) ? (int)FreshRSS_Context::$total_unread : 1000;
		$maxLimit = self::getWatchTimeLimit();
		$limit = min($maxLimit, max(1, $totalUnread));

		$vids = [];
		try {
			$entryDAO = FreshRSS_Factory::createEntryDao();
			if (method_exists($entryDAO, 'listWhere')) {
				$stateNotRead = class_exists('FreshRSS_Entry', false) && defined('FreshRSS_Entry::STATE_NOT_READ')
					? FreshRSS_Entry::STATE_NOT_READ
					: 2;

				foreach ($entryDAO->listWhere('a', 0, state: $stateNotRead, limit: $limit) as $entry) {
					if ($entry instanceof FreshRSS_Entry) {
						$vid = self::extractVideoId($entry->link()) ?? self::extractVideoId($entry->guid());
						if ($vid !== null) {
							$vids[$vid] = true;
						}
					}
				}
			}
		} catch (\Throwable $e) {
			self::$watchTime = '';
			return '';
		}

		if (empty($vids)) {
			self::$watchTime = '';
			return '';
		}

		$totalSeconds = self::calculateWatchTimeFromVids(array_keys($vids));
		$formatted = self::formatSecondsToWatchTime($totalSeconds);
		if ($formatted === '') {
			self::$watchTime = '';
			return '';
		}

		$titleLabel = function_exists('_t') ? _t('index.menu.main_stream') : 'Main stream';
		self::$watchTime = '<span class="yt-watchtime" title="' .
			htmlspecialchars($titleLabel . ' · ' . $formatted, ENT_QUOTES, 'UTF-8') .
			'"> (<span class="yt-watchtime-text">' . htmlspecialchars($formatted, ENT_NOQUOTES, 'UTF-8') . '</span>)</span>';

		return self::$watchTime;
	}
}

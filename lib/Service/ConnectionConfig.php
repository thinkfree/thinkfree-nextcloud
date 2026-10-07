<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\AppInfo\Application;
use OCP\IAppConfig;

/**
 * 웹오피스 서버 주소를 관리한다.
 *
 * 주소는 관리자 설정 화면에서 지정하며 인스턴스 전체가 같은 값을 쓴다.
 * 예전에는 사용자마다 개인 설정에서 지정했지만, 웹오피스 서버는 보통 조직에
 * 하나뿐이라 관리자가 한 번 정하는 편이 맞다.
 */
class ConnectionConfig {
	/** 웹오피스 서버 주소를 담는 관리자 설정 키. */
	public const CONFIG_SERVER_ADDRESS = 'serverAddress';

	private IAppConfig $appConfig;

	public function __construct(IAppConfig $appConfig) {
		$this->appConfig = $appConfig;
	}

	/**
	 * 설정된 웹오피스 서버 주소. 지정되지 않았으면 빈 문자열이다.
	 *
	 * 빈 값은 "아직 설정하지 않음"이라는 뜻이고, DiscoveryService 가 그 상태를
	 * not_configured 로 구분해 알린다. 닿지 않는 기본 주소로 대신 채우면
	 * 설정하지 않은 것과 잘못 설정한 것이 화면에서 구분되지 않는다.
	 */
	public function getServerAddress(): string {
		return self::normalizeAddress(
			$this->appConfig->getValueString(Application::APP_ID, self::CONFIG_SERVER_ADDRESS)
		);
	}

	/**
	 * 관리자가 입력한 주소를 저장해도 되는지. 설정 화면의 저장 요청이 쓴다.
	 *
	 * 스킴과 호스트는 toNormalOriginAddress() 와 같은 규칙으로 확인하고, 하위 경로는
	 * 허용한다(예: https://office.example.com/tfo). 아이디·비밀번호, 쿼리, 조각은
	 * 서버 주소에 쓸 일이 없고 그대로 로그와 discovery 요청에 실리므로 받지 않는다.
	 */
	public static function isValidServerAddress(string $address): bool {
		$normalized = self::normalizeAddress($address);

		if (self::toNormalOriginAddress($normalized) === null) {
			return false;
		}

		$parts = parse_url($normalized);

		return !isset($parts['query']) && !isset($parts['fragment']);
	}

	/**
	 * URL 에서 origin(스킴://호스트[:포트])만 남긴다. 형식이 맞지 않으면 null 이다.
	 *
	 * 이 값은 CSP 헤더의 form-action 에 그대로 이어 붙는다. NextCloud 는 값을 검사하지
	 * 않으므로, 주소에 "https://x; script-src-elem * 'unsafe-inline'" 처럼 ; 가 있으면
	 * 모든 페이지의 CSP 에 새 지시문이 끼어들고, 줄바꿈이 있으면 PHP 가 헤더 전송을
	 * 거부해 CSP 가 통째로 빠진다. 그래서 들어갈 수 있는 글자를 좁게 정해 둔다.
	 *
	 *   스킴   http, https
	 *   호스트 영숫자와 . - 만. IPv6 는 대괄호로 감싼 16진수와 : . 만
	 *   포트   숫자 (parse_url 이 1~65535 가 아니면 실패한다)
	 */
	public static function toNormalOriginAddress(string $url): ?string {
		// 공백과 제어 문자는 어디에 있든 받지 않는다. 헤더를 깨뜨리는 줄바꿈이 여기 걸린다.
		if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
			return null;
		}

		$parts = parse_url($url);

		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			return null;
		}

		$scheme = strtolower($parts['scheme']);

		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}

		// user:pass@host 는 받지 않는다.
		if (isset($parts['user']) || isset($parts['pass'])) {
			return null;
		}

		$host = strtolower($parts['host']);

		if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1 && preg_match('/^\[[0-9a-f:.]+\]$/', $host) !== 1) {
			return null;
		}

		return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}

	/**
	 * 주소에서 앞뒤 공백과 후행 슬래시를 떼고, 스킴이 없으면 http 를 붙인다.
	 */
	private static function normalizeAddress(string $address): string {
		$address = trim($address);

		if ($address === '') {
			return '';
		}

		// 스킴이 아예 없을 때만 붙인다. http(s) 가 아니라는 이유로 붙이면 ftp://host 가
		// http://ftp://host 가 되어 호스트가 "ftp" 인 주소로 읽힌다.
		// 후행 슬래시는 그다음에 뗀다. 먼저 떼면 "https://" 가 "https:" 가 되어 같은 일이 생긴다.
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $address)) {
			$address = 'http://' . $address;
		}

		return rtrim($address, '/');
	}
}

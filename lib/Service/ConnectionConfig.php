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
		return $this->normalizeAddress(
			$this->appConfig->getValueString(Application::APP_ID, self::CONFIG_SERVER_ADDRESS)
		);
	}

	/**
	 * 주소에서 앞뒤 공백과 후행 슬래시를 떼고, 스킴이 없으면 http 를 붙인다.
	 */
	private function normalizeAddress(string $address): string {
		$address = rtrim(trim($address), '/');

		if ($address === '') {
			return '';
		}

		if (!preg_match('#^https?://#i', $address)) {
			$address = 'http://' . $address;
		}

		return $address;
	}
}

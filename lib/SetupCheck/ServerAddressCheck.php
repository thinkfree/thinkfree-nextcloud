<?php

declare(strict_types=1);

namespace OCA\Thinkfree\SetupCheck;

use OCA\Thinkfree\Service\ConnectionConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * 웹오피스 서버 주소가 비어 있으면 관리자 설정 > 개요의 "보안 및 설정 경고"에 알린다.
 */
class ServerAddressCheck implements ISetupCheck {
	private ConnectionConfig $connectionConfig;
	private IL10N $l10n;
	private IURLGenerator $urlGenerator;

	public function __construct(
		ConnectionConfig $connectionConfig,
		IL10N $l10n,
		IURLGenerator $urlGenerator,
	) {
		$this->connectionConfig = $connectionConfig;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
	}

	public function getCategory(): string {
		return 'config';
	}

	public function getName(): string {
		return $this->l10n->t('Thinkfree Office server');
	}

	public function run(): SetupResult {
		if ($this->connectionConfig->getServerAddress() !== '') {
			return SetupResult::success();
		}

		return SetupResult::warning(
			$this->l10n->t('The Thinkfree Office server address is not set, so "Open in Thinkfree Office" is not shown to users. Save the server address in the Thinkfree Office administration settings.'),
			// 경고 옆의 링크가 주소를 입력하는 관리자 설정 화면으로 바로 이어진다
			$this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'thinkfree'])
		);
	}
}

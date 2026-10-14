<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Listener;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Service\ConnectionConfig;
use OCA\Thinkfree\Service\DiscoveryService;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use Psr\Log\LoggerInterface;

/**
 * 웹오피스로 폼을 제출할 수 있도록 CSP 에 그 주소를 추가한다.
 *
 * WOPI 는 액세스 토큰을 폼 POST 본문으로 보내라고 정하는데, NextCloud 의
 * 기본 정책은 form-action 'self' 라 다른 출처인 웹오피스로의 제출을
 * 브라우저가 막는다. 새로 연 탭이 about:blank 인 채로 남고 콘솔에만
 * 위반이 기록되어, 아무 일도 일어나지 않은 것처럼 보인다.
 *
 * 따라서 CSP에 걸리지 않도록 설정된 서버 주소와 캐시된 주소를 모두 추가한다.
 *
 * 두 값 모두 origin 만 남기고, 형식이 맞지 않으면 추가하지 않는다.
 *
 * @template-implements IEventListener<AddContentSecurityPolicyEvent>
 */
class CspListener implements IEventListener {
	private DiscoveryService $discoveryService;
	private ConnectionConfig $connection;
	private LoggerInterface $logger;

	public function __construct(
		DiscoveryService $discoveryService,
		ConnectionConfig $connection,
		LoggerInterface $logger,
	) {
		$this->discoveryService = $discoveryService;
		$this->connection = $connection;
		$this->logger = $logger;
	}

	public function handle(Event $event): void {
		if (!($event instanceof AddContentSecurityPolicyEvent)) {
			return;
		}

		$candidates = $this->discoveryService->getCachedEditorOrigins();
		$configured = $this->connection->getServerAddress();

		if ($configured !== '') {
			$candidates[] = $configured;
		}

		$origins = [];

		foreach ($candidates as $candidate) {
			$origin = ConnectionConfig::toNormalOriginAddress($candidate);

			if ($origin === null) {
				$this->logger->warning('Not adding an invalid web office address to the CSP form-action', [
					'app' => Application::APP_ID,
				]);

				continue;
			}

			$origins[$origin] = true; // 키로 모아 중복을 없앤다.
		}

		if ($origins === []) { // 웹오피스 주소가 설정되지 않았으면 넓힐 이유가 없다.
			return;
		}

		$policy = new EmptyContentSecurityPolicy();

		foreach (array_keys($origins) as $origin) {
			$policy->addAllowedFormActionDomain($origin);
		}

		$event->addPolicy($policy);
	}
}

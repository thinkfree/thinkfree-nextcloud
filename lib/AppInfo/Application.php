<?php

declare(strict_types=1);

namespace OCA\Thinkfree\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCA\Thinkfree\Service\DiscoveryService;
use OCP\Util;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCA\Thinkfree\Service\ConnectionConfig;

class Application extends App implements IBootstrap {
	public const APP_ID = 'thinkfree';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// TODO. 추후에 AppConfig 클래스 주입하여 관리필요.
	}

	public function boot(IBootContext $context): void {
        $eventDispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		$eventDispatcher->addListener(LoadAdditionalScriptsEvent::class, function() use ($context) {
			$this->registerFileAction($context);
		});

		$eventDispatcher->addListener(
			AddContentSecurityPolicyEvent::class,
			function(AddContentSecurityPolicyEvent $event) use ($context) {
				$this->allowEditorFormAction($context, $event);
			}
		);
	}

	/**
	 * 웹오피스로 폼을 제출할 수 있도록 CSP 에 그 주소를 추가한다.
	 *
	 * WOPI 는 액세스 토큰을 폼 POST 본문으로 보내라고 정하는데, NextCloud 의
	 * 기본 정책은 form-action 'self' 라 다른 출처인 웹오피스로의 제출을
	 * 브라우저가 막는다. 새로 연 탭이 about:blank 인 채로 남고 콘솔에만
	 * 위반이 기록되어, 아무 일도 일어나지 않은 것처럼 보인다.
	 *
	 * 따라서 CSP에 걸리지 않도록 설정된 서버 주소와 캐시된 주소를 모두 추가한다.
	 */
	private function allowEditorFormAction(IBootContext $context, AddContentSecurityPolicyEvent $event): void {
		$container = $context->getAppContainer();

		$addresses = $container->get(DiscoveryService::class)->getCachedEditorOrigins();
		$configured = $container->get(ConnectionConfig::class)->getServerAddress();

		if ($configured !== '') {
			$addresses[] = $configured;
		}

		if ($addresses === []) { // 웹오피스 주소가 설정되지 않았으면 넓힐 이유가 없다.
			return;
		}

		$policy = new EmptyContentSecurityPolicy();

		foreach (array_unique($addresses) as $address) {
			$policy->addAllowedFormActionDomain($address);
		}

		$event->addPolicy($policy);
	}

	/**
     * 파일 목록에 "TFO에서 열기" 메뉴를 붙이는 스크립트를 로드한다.
     *
     * 웹오피스 주소가 설정되지 않았거나 discovery 에 닿지 않으면 어떤 확장자를
     * 지원하는지 알 수 없다. 그럴 때는 스크립트를 아예 로드하지 않아, 눌러도
     * 열리지 않는 메뉴가 생기지 않도록 한다.
	 */
	private function registerFileAction(IBootContext $context): void {
		// IInitialState is scoped to the app, so the services have to come from
		// the app container rather than the server container.
		$container = $context->getAppContainer();

		Util::addInitScript(self::APP_ID, 'newfilemenu'); // newfilemenu.js 로드

		$discovery = $container->get(DiscoveryService::class);

		if ($discovery->getWopiUrl() === '') {
			return;
		}

		$extensions = $discovery->getSupportedExtensions();
		if ($extensions === []) {
			return;
		}

		$container->get(IInitialState::class) // 초기값 세팅
			->provideInitialState('wopiExtensions', $extensions);

		Util::addInitScript(self::APP_ID, 'wopi'); // wopi.js 로드
	}
}

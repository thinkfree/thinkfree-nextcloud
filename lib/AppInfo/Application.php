<?php

declare(strict_types=1);

namespace OCA\Thinkfree\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCA\Thinkfree\Service\DiscoveryService;
use OCA\Thinkfree\Notification\Notifier;
use OCA\Thinkfree\SetupCheck\ServerAddressCheck;
use OCP\Util;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCA\Thinkfree\Listener\CspListener;

class Application extends App implements IBootstrap {
	public const APP_ID = 'thinkfree';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// TODO. 추후에 AppConfig 클래스 주입하여 관리필요.

		// 웹오피스 주소가 비어 있으면 관리자 개요에 경고를 띄운다
		// 설정 경고를 등록하기 위해서는 반드시 register에 추가해야함
		$context->registerSetupCheck(ServerAddressCheck::class);

		// 주소가 비어 있을 때 관리자에게 보내는 알림(NotifyMissingServerAddress)을 표시한다
		$context->registerNotifierService(Notifier::class);

		// 웹오피스로 폼을 제출할 수 있도록 CSP form-action 에 그 주소를 추가한다
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
	}

	public function boot(IBootContext $context): void {
        $eventDispatcher = $context->getServerContainer()->get(IEventDispatcher::class);

		$eventDispatcher->addListener(LoadAdditionalScriptsEvent::class, function() use ($context) {
			$this->registerFileAction($context);
		});
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

<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Listener;

use OCA\Thinkfree\AppInfo\Application;
use OCA\Thinkfree\Service\DiscoveryService;
use OCP\AppFramework\Services\IInitialState;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * 링크 미리보기를 그리는 페이지(Assistant, Talk, Text …)에 Thinkfree Office 위젯 스크립트를 싣는다.
 *
 * 웹오피스 주소가 없거나 discovery 에 닿지 않으면 띄울 수 없으므로 싣지 않는다.
 * 그러면 링크는 위젯 없이 기본 미리보기(파일 이름)로만 보인다.
 *
 * @template-implements IEventListener<RenderReferenceEvent>
 */
class RenderReferenceListener implements IEventListener {
	private DiscoveryService $discoveryService;
	private IInitialState $initialState;

	public function __construct(DiscoveryService $discoveryService, IInitialState $initialState) {
		$this->discoveryService = $discoveryService;
		$this->initialState = $initialState;
	}

	public function handle(Event $event): void {
		if (!($event instanceof RenderReferenceEvent)) {
			return;
		}

		if ($this->discoveryService->getWopiUrl() === '') {
			return;
		}

		$extensions = $this->discoveryService->getSupportedExtensions();
		if ($extensions === []) {
			return;
		}

		// Smart Picker 의 파일 선택 창에서 열 수 있는 파일만 보이도록 넘긴다
		$this->initialState->provideInitialState('wopiExtensions', $extensions);

		Util::addScript(Application::APP_ID, 'reference');
		Util::addStyle(Application::APP_ID, 'reference');
	}
}

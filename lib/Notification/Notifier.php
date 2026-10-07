<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Notification;

use OCA\Thinkfree\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * 이 앱이 보내는 알림을 화면에 표시할 문장으로 바꾼다.
 */
class Notifier implements INotifier {
	/** 웹오피스 주소가 비어 있다는 알림 */
	public const SUBJECT_MISSING_SERVER_ADDRESS = 'server_address_missing';

	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;

	public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * "주소가 비어 있음" 알림의 뼈대. 보낼 때와 지울 때 같은 값으로 찾아야 하므로
	 * 한 곳에서 만든다. 받는 사람과 시각은 보내는 쪽이 붙인다.
	 */
	public static function missingServerAddress(INotificationManager $manager): INotification {
		return $manager->createNotification()
			->setApp(Application::APP_ID)
			->setObject('server_address', 'missing')
			->setSubject(self::SUBJECT_MISSING_SERVER_ADDRESS);
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Thinkfree Office');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		// 다른 앱의 알림은 건드리지 않는다
		if ($notification->getApp() !== Application::APP_ID
			|| $notification->getSubject() !== self::SUBJECT_MISSING_SERVER_ADDRESS) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		$notification
			->setParsedSubject($l->t('Thinkfree Office server address is not set'))
			->setParsedMessage($l->t('"Open in Thinkfree Office" is not shown to users until the server address is saved in the administration settings.'))
			// 알림을 누르면 주소를 입력하는 관리자 설정 화면으로 간다
			->setLink($this->urlGenerator->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'thinkfree']))
			->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')));

		return $notification;
	}
}

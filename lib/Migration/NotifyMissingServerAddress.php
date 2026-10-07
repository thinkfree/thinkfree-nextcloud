<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Migration;

use OCA\Thinkfree\Notification\Notifier;
use OCA\Thinkfree\Service\ConnectionConfig;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Notification\IManager as INotificationManager;

/**
 * 설치나 업그레이드 직후 웹오피스 주소가 비어 있으면 관리자들에게 알린다.
 *
 * 2.0.0 은 주소를 사용자별 설정에 저장했고 3.0.0 은 관리자 설정만 읽으므로,
 * 업그레이드된 App은 주소가 빈 상태로 시작한다.
 * 따라서, 관리자에게 서버 설정 알림을 보내도록 한다.
 *
 * info.xml 의 post-migration(업그레이드, 다시 켜기)과 install(처음 설치)에 등록되어 있다.
 * 업그레이드하면 두 단계가 모두 실행되지만, 보내기 전에 이전 알림을 지우므로 한 번만 남는다.
 */
class NotifyMissingServerAddress implements IRepairStep {
	private ConnectionConfig $connectionConfig;
	private IGroupManager $groupManager;
	private INotificationManager $notificationManager;

	public function __construct(
		ConnectionConfig $connectionConfig,
		IGroupManager $groupManager,
		INotificationManager $notificationManager,
	) {
		$this->connectionConfig = $connectionConfig;
		$this->groupManager = $groupManager;
		$this->notificationManager = $notificationManager;
	}

	public function getName(): string {
		return 'Notify admins when the Thinkfree Office server address is missing';
	}

	public function run(IOutput $output): void {
		if ($this->connectionConfig->getServerAddress() !== '') {
			return;
		}

		// 업그레이드를 실행한 사람에게는 콘솔이나 업데이터 화면에 바로 보인다
		$output->warning('Thinkfree Office server address is not set. Save it in the Thinkfree Office administration settings.');

		// 업그레이드를 여러 번 해도 알림이 쌓이지 않도록 이전 알림을 먼저 지운다
		$this->notificationManager->markProcessed(Notifier::missingServerAddress($this->notificationManager));

		$admins = $this->groupManager->get('admin');
		if ($admins === null) {
			return;
		}

		foreach ($admins->getUsers() as $admin) {
			$notification = Notifier::missingServerAddress($this->notificationManager)
				->setUser($admin->getUID())
				->setDateTime(new \DateTime());

			$this->notificationManager->notify($notification);
		}
	}
}

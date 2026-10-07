<?php

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Notification\Notifier;
use OCA\Thinkfree\Service\ConnectionConfig;
use OCA\Thinkfree\Service\DiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Notification\IManager as INotificationManager;

/**
 * 관리자 설정 화면이 쓰는 엔드포인트.
 *
 * 설정 항목은 웹오피스 서버 주소 하나뿐이고 인스턴스 전체에 적용되므로,
 * 두 메서드 모두 관리자만 호출할 수 있다.
 */
class SettingsController extends Controller {
	private IAppConfig $appConfig;
	private ConnectionConfig $connection;
	private DiscoveryService $discoveryService;
	private INotificationManager $notificationManager;

	public function __construct(
		IRequest $request,
		IAppConfig $appConfig,
		ConnectionConfig $connection,
		DiscoveryService $discoveryService,
		INotificationManager $notificationManager,
	) {
		parent::__construct('thinkfree', $request);
		$this->appConfig = $appConfig;
		$this->connection = $connection;
		$this->discoveryService = $discoveryService;
		$this->notificationManager = $notificationManager;
	}

	#[NoCSRFRequired]
	public function get(): DataResponse {
		return new DataResponse([
			// 화면의 입력란을 채우는 값이므로 저장된 그대로 돌려준다.
			'serverAddress' => $this->appConfig->getValueString(
				'thinkfree',
				ConnectionConfig::CONFIG_SERVER_ADDRESS
			),
		]);
	}

	/**
	 * 설정을 저장한다.
	 */
	public function set(): DataResponse {
		$data = $this->readJsonBody();
		if (!is_array($data)) {
			return new DataResponse(['error' => 'Malformed request'], Http::STATUS_BAD_REQUEST);
		}

		$response = ['status' => 'success'];

		// 빈 문자열이 "설정 해제"를 뜻하므로 isset 이 아니라 키 존재로 판단한다.
		if (array_key_exists('serverAddress', $data)) {
			$address = is_string($data['serverAddress']) ? trim($data['serverAddress']) : '';

			if ($address !== '' && !ConnectionConfig::isValidServerAddress($address)) {
				// 이 주소는 CSP 헤더에 들어간다. 형식이 맞지 않으면 400 에러를 응답
				return new DataResponse(
					['error' => 'Invalid server address. Enter http:// or https:// followed by a host name, for example https://office.example.com'],
					Http::STATUS_BAD_REQUEST
				);
			}

			if ($address === '') {
				// 빈 값을 남기지 않고 키를 지운다. 읽는 쪽은 둘을 같게 취급하지만
				// 설정 목록에 빈 항목이 남지 않는 편이 낫다.
				$this->appConfig->deleteKey('thinkfree', ConnectionConfig::CONFIG_SERVER_ADDRESS);
			} else {
				$this->appConfig->setValueString('thinkfree', ConnectionConfig::CONFIG_SERVER_ADDRESS, $address);

				// 주소가 비어 있다는 알림을 모든 관리자에게서 지운다
				$this->notificationManager->markProcessed(Notifier::missingServerAddress($this->notificationManager));
			}

			// 정규화가 적용된 "방금 저장된" 주소로 확인한다.
			$response['discovery'] = $this->checkDiscovery($this->connection->getServerAddress());
		}

		return new DataResponse($response);
	}

	/**
	 * 요청 본문의 JSON 을 읽는다.
	 *
	 * 단위 테스트에서는 이 메서드를 오버라이드해서 사용한다
	 *
	 * @return mixed 디코딩한 값. JSON 이 아니면 null
	 */
	protected function readJsonBody(): mixed {
		return json_decode(file_get_contents('php://input'), true);
	}

	/**
	 * 방금 저장한 주소로 웹오피스에 실제로 닿는지 확인한다.
	 *
	 * @return array{checked: bool, ok: bool, reason: string, address: string,
	 *               extensions: int, status: int}
	 */
	private function checkDiscovery(string $address): array {
		return ['checked' => true] + $this->discoveryService->checkAddress($address);
	}
}

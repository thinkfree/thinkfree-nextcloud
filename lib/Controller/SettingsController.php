<?php

namespace OCA\Thinkfree\Controller;

use OCA\Thinkfree\Service\ConnectionConfig;
use OCA\Thinkfree\Service\DiscoveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IRequest;

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

	public function __construct(
		IRequest $request,
		IAppConfig $appConfig,
		ConnectionConfig $connection,
		DiscoveryService $discoveryService,
	) {
		parent::__construct('thinkfree', $request);
		$this->appConfig = $appConfig;
		$this->connection = $connection;
		$this->discoveryService = $discoveryService;
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

	#[NoCSRFRequired]
	public function set(): DataResponse {
		$data = json_decode(file_get_contents('php://input'), true);
		if (!is_array($data)) {
			return new DataResponse(['error' => 'Malformed request'], Http::STATUS_BAD_REQUEST);
		}

		$response = ['status' => 'success'];

		// 빈 문자열이 "설정 해제"를 뜻하므로 isset 이 아니라 키 존재로 판단한다.
		if (array_key_exists('serverAddress', $data)) {
			$address = is_string($data['serverAddress']) ? trim($data['serverAddress']) : '';

			if ($address === '') {
				// 빈 값을 남기지 않고 키를 지운다. 읽는 쪽은 둘을 같게 취급하지만
				// 설정 목록에 빈 항목이 남지 않는 편이 낫다.
				$this->appConfig->deleteKey('thinkfree', ConnectionConfig::CONFIG_SERVER_ADDRESS);
			} else {
				$this->appConfig->setValueString('thinkfree', ConnectionConfig::CONFIG_SERVER_ADDRESS, $address);
			}

			// discovery 문서는 주소별로 캐시되므로, 주소를 바꾸면 옛 항목이
			// 남아 있다가 엉뚱한 결과를 내놓는다.
			$this->discoveryService->clearCache();

			// 정규화가 적용된 "방금 저장된" 주소로 확인한다.
			$response['discovery'] = $this->checkDiscovery($this->connection->getServerAddress());
		}

		return new DataResponse($response);
	}

	/**
	 * 방금 저장한 주소로 웹오피스에 실제로 닿는지 확인한다.
	 *
	 * 확인이 실패해도 저장 자체는 성공으로 둔다. 방화벽이 열리기 전에 주소를
	 * 미리 넣어 두는 것까지 막을 이유가 없고, 관리자에게는 "저장되었지만 이
	 * 주소로는 웹오피스를 쓸 수 없다"가 정확한 설명이다.
	 *
	 * @return array{checked: bool, ok: bool, reason: string, address: string,
	 *               extensions: int, status: int}
	 */
	private function checkDiscovery(string $address): array {
		return ['checked' => true] + $this->discoveryService->checkAddress($address);
	}
}

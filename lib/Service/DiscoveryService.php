<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * discovery를 담당하는 서비스
 */
class DiscoveryService {
	public const APP_ID = 'thinkfree';

	/** 연결 확인 결과. 화면에 띄울 문구는 프론트엔드가 이 값으로 고른다. */
	public const REASON_OK = 'ok';
	public const REASON_NOT_CONFIGURED = 'not_configured';
	public const REASON_UNREACHABLE = 'unreachable';
	public const REASON_HTTP_ERROR = 'http_error';
	public const REASON_NOT_XML = 'not_xml';
	public const REASON_NO_ACTIONS = 'no_actions';

	private const CACHE_TTL = 3600;

	/**
	 * 실패도 짧게 캐시한다. 실패를 기억하지 않으면 파일 목록을 열 때마다
	 * 타임아웃을 다시 기다리게 되어, 웹오피스가 닿지 않는 동안 Nextcloud
	 * 자체가 느려진다. 곧 복구될 수 있으니 TTL 은 성공보다 훨씬 짧게 둔다.
	 */
	private const FAILURE_CACHE_TTL = 60;

	/**
	 * 이 요청은 파일 목록을 그리는 도중에 일어난다. 어떤 확장자에 메뉴를 붙일지
	 * 알아야 하기 때문인데, 그래서 웹오피스가 응답하지 않으면 파일 목록 자체가
	 * 그만큼 멈춘다. 때문에 길게 잡지 않고 짧게 5초로 설정한다.
	 */
	private const REQUEST_TIMEOUT = 5;

	/**
	 * 연결 확인은 사람이 저장 버튼을 누르고 결과를 기다리는 중이라, 렌더 경로와
	 * 같은 값이어도 성격이 다르다. 한쪽을 조정할 때 다른 쪽이 끌려가지 않도록
	 * 따로 둔다.
	 */
	private const HEALTH_CHECK_TIMEOUT = 5;

	private ConnectionConfig $connection;
	private IClientService $clientService;
	private ICache $cache;
	private LoggerInterface $logger;

	public function __construct(
		ConnectionConfig $connection,
		IClientService $clientService,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger,
	) {
		$this->connection = $connection;
		$this->clientService = $clientService;
		$this->cache = $cacheFactory->createDistributed(self::APP_ID . '-discovery');
		$this->logger = $logger;
	}

	/**
     * discovery 를 조회할 웹오피스 서버 주소를 가져온다.
     */
	public function getWopiUrl(): string {
		return $this->connection->getServerAddress();
	}

	/**
	 * 지금 discovery 에 쓰이는 주소로 확인한다.
	 *
	 * @return array{ok: bool, reason: string, address: string, extensions: int, status: int}
	 */
	public function checkConnection(): array {
		return $this->checkAddress($this->getWopiUrl());
	}

	/**
	 * 주어진 주소로 discovery 를 실제로 한 번 요청해 해당 주소가 연동 가능한지 알려준다.
	 *
	 * 지금 설정된 주소가 아니라 주소를 인자로 받는 이유가 있다. 사용자가 개인
	 * 설정에서 저장한 주소는 관리자가 서버용 주소를 따로 지정해 두면 discovery
	 * 에 쓰이지 않는데, 그렇다고 유효 주소만 확인하면 "방금 입력한 주소가
	 * 쓸 수 있는 주소인지"에 대해서는 아무것도 알려주지 못한다. 사용자가 고칠
	 * 수 있는 건 자기가 입력한 값이므로 그쪽을 확인해야 한다.
	 *
	 * 캐시를 신뢰하지 않고 새로 물어본다. 사용자가 방금 주소를 바꿨거나 서버
	 * 쪽 문제를 고친 상황이기 때문이다. 성공하면 그 주소의 캐시를 채워 두므로
	 * 확인이 곧 예열이 된다.
	 *
	 * @return array{ok: bool, reason: string, address: string, extensions: int, status: int}
	 */
	public function checkAddress(string $address): array {
		if ($address === '') {
			return $this->result(self::REASON_NOT_CONFIGURED, $address, 0);
		}

		$this->cache->remove($this->cacheKeyFor($address)); // discovery 캐싱 삭제
		$this->cache->remove($this->failureKeyFor($address)); // 실패 캐싱 삭제

		// 상태 코드를 직접 보려고 http_errors 를 끈다. 그대로 두면 404 도 예외로
		// 올라와 연결 자체가 안 된 것과 구분되지 않는데, 404 는 오히려 흔한
		// 상황이다 — 웹오피스는 떠 있고 hosting 구성요소만 없는 경우다.
		$status = 0;
		$content = $this->requestDiscovery(self::HEALTH_CHECK_TIMEOUT, $status, $address); // discovery 요청, status 응답 코드 받아옴

		if ($content === null) {
			$this->cacheFailure($address); // 실패 캐싱

			return $this->result(self::REASON_UNREACHABLE, $address, 0);
		}

		if ($status >= 400) { // 응답 코드가 4xx, 5xx 면 실패
			$this->cacheFailure($address); // 실패 캐싱
			$this->logger->error(
				'Discovery request returned HTTP ' . $status,
				['app' => self::APP_ID, 'url' => $address . '/hosting/discovery']
			);

			return $this->result(self::REASON_HTTP_ERROR, $address, 0, $status);
		}

		$xml = $this->parseXml($content); // 성공했을 경우 xml 파싱
		if ($xml === null) {
			$this->cacheFailure($address); // xml이 없으면 실패 캐싱

			return $this->result(self::REASON_NOT_XML, $address, 0, $status);
		}

		// 여기까지 오면 문서 자체는 받았으니 캐시해 둔다. 형식 목록이 비어 있는
		// 것은 웹오피스 구성 문제이고, 다시 물어도 답이 달라지지 않는다.
		$this->cache->set($this->cacheKeyFor($address), $content, self::CACHE_TTL);

		$extensions = $this->extensionsFrom($xml); // 사용 가능한 확장자들을 가져온다.

		return $extensions === []
			? $this->result(self::REASON_NO_ACTIONS, $address, 0, $status)
			: $this->result(self::REASON_OK, $address, count($extensions), $status); //비어있지 않으면 몇개의 확장자를 사용 가능한지 보여준다.
	}

	/**
	 * discovery xml로부터 urlsrc를 가져온다.
	 *
	 * @param string $action `edit` or `view`
	 */
	public function getUrlSrc(string $extension, string $action): ?string {
		$xml = $this->getParsedXmlContent();
		if ($xml === null) {
			return null;
		}

		$extension = strtolower($extension);

		foreach ($this->netZones() as $zone) {
			$query = sprintf(
				"/wopi-discovery/net-zone[@name='%s']/app/action[@ext='%s' and @name='%s']",
				$zone,
				$extension,
				$action
			);

			$matches = $xml->xpath($query);
			if (!empty($matches)) {
				return $this->normalizeUrlSrc((string)$matches[0]['urlsrc']);
			}
		}

		return null;
	}


	/**
	 * 웹오피스가 discovery 에 실어 보낸 RSA 공개키.
	 *
	 * 키 교체 중에도 검증이 끊기지 않도록 옛 키가 함께 온다. 옛 키가 없으면
	 * 현재 키로 채워, 호출부가 두 값이 늘 있다고 가정할 수 있게 한다.
	 *
	 * @return array{modulus: string, exponent: string, oldmodulus: string, oldexponent: string}|null
	 */
	public function getProofKey(): ?array {
		$xml = $this->getParsedXmlContent();

		if ($xml === null) {
			return null;
		}

		$nodes = $xml->xpath('/wopi-discovery/proof-key') ?: [];

		if ($nodes === []) {
			return null;
		}

		$node = $nodes[0];
		$modulus = (string)($node['modulus'] ?? '');
		$exponent = (string)($node['exponent'] ?? '');

		if ($modulus === '' || $exponent === '') {
			return null;
		}

		$oldModulus = (string)($node['oldmodulus'] ?? '');
		$oldExponent = (string)($node['oldexponent'] ?? '');

		return [
			'modulus' => $modulus,
			'exponent' => $exponent,
			'oldmodulus' => $oldModulus !== '' ? $oldModulus : $modulus,
			'oldexponent' => $oldExponent !== '' ? $oldExponent : $exponent,
		];
	}

    /**
     * 지원하는 확장자들을 반환한다.
     * Application.php의 registerFileAction 메서드에서 호출
     */
	public function getSupportedExtensions(): array {
		$xml = $this->getParsedXmlContent();
		if ($xml === null) {
			return [];
		}

		return $this->extensionsFrom($xml);
	}

	public function clearCache(): void {
		$this->cache->remove($this->cacheKey());
		$this->cache->remove($this->failureKey());
	}

    // 캐시 키 이름 만들기
	private function cacheKey(): string {
		return $this->cacheKeyFor($this->getWopiUrl());
	}

	private function cacheKeyFor(string $address): string {
		return 'discovery.' . md5($address) . '.xml';
	}

	/** 실패 키 이름 만들기 */
	private function failureKey(): string {
		return $this->failureKeyFor($this->getWopiUrl());
	}

	private function failureKeyFor(string $address): string {
		return 'discovery.' . md5($address) . '.failed';
	}

    // 실패 캐싱
	private function cacheFailure(string $address): void {
		$this->cache->set($this->failureKeyFor($address), '1', self::FAILURE_CACHE_TTL);
	}

	/**
	 * @return array{ok: bool, reason: string, address: string, extensions: int, status: int}
	 */
	private function result(string $reason, string $address, int $extensions, int $status = 0): array {
		return [
			'ok' => $reason === self::REASON_OK,
			'reason' => $reason,
			'address' => $address,
			'extensions' => $extensions,
			'status' => $status,
		];
	}

	/**
     * netZones 배열 생성
	 *
	 * @return string[]
	 */
	private function netZones(): array {
		// 이 서버가 웹오피스에 접속하는 주소의 스킴을 먼저 시도한다. 네 zone 을
		// 모두 훑으므로 순서가 틀려도 결과는 같고, 맞으면 한 번에 찾는다.
		$protocol = str_starts_with($this->connection->getServerAddress(), 'https://') ? 'https' : 'http';
		$fallback = $protocol === 'https' ? 'http' : 'https';

        // external-http(s), internal-http(s)
		return [
			'external-' . $protocol,
			'internal-' . $protocol,
			'external-' . $fallback,
			'internal-' . $fallback,
		];
	}

	/**
	 * 파싱된 문서에서 편집·보기 액션이 선언된 확장자를 모은다.
	 *
	 * @return string[] lowercase extensions, without a leading dot
	 */
	private function extensionsFrom(\SimpleXMLElement $xml): array {
		$extensions = [];

		foreach ($this->netZones() as $zone) { // ['external-http', 'internal-http', 'external-https', 'internal-https']
			$query = sprintf(
				"/wopi-discovery/net-zone[@name='%s']/app/action[@name='edit' or @name='view']",
                // 위 순서대로 xml을 훑어서 edit또는 view 액션 전부를 뽑는다.
				$zone
			);

			foreach ($xml->xpath($query) ?: [] as $action) { // 추출한 row들을 순회
				$extension = strtolower((string)$action['ext']); // 확장자 추출

				if ($extension !== '') {
					$extensions[$extension] = true; // 비어있지 않으면 해당 확장자는 true, 사용 가능하다.
                                                    // ex) $extensions['docx'] = true
				}
			}

			if (!empty($extensions)) {
				break;
			}
		}

		if ($extensions === []) {
			// 문서는 받았는데 쓸 수 있는 액션이 없는 경우
			$this->logger->warning(
				'Discovery document declares no edit or view action for any net zone',
				['app' => self::APP_ID, 'zones' => $this->netZones()]
			);

			return [];
		}


        // 이 함수로 key('docx','pptx')를 value로 옮김.
        // [0 => 'docx', 1 => 'pptx'...]
		return array_keys($extensions);
	}

	private function parseXml(string $content): ?\SimpleXMLElement {
		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($content);
		libxml_use_internal_errors($previous);

		if ($xml === false) {
			$this->logger->error('Discovery response is not valid XML', ['app' => self::APP_ID]);

			return null;
		}

		return $xml;
	}

	private function getParsedXmlContent(): ?\SimpleXMLElement {
		// 직전 시도가 실패했다면 TTL 이 지나기 전에는 다시 시도하지 않는다.
		if ($this->cache->get($this->failureKey()) !== null) {
			return null;
		}

		$cacheKey = $this->cacheKey();
		$content = $this->cache->get($cacheKey); // 캐시에 존재하는지 확인

		if (!is_string($content) || $content === '') {
			$content = $this->requestDiscovery(); // 캐시에 존재하지 않으면 discovery 요청

			if ($content === null) {
				$this->cacheFailure($this->getWopiUrl());

				return null;
			}

			$this->cache->set($cacheKey, $content, self::CACHE_TTL); // 캐시 SET
		}

		$xml = $this->parseXml($content); // 실제 파싱

		if ($xml === null) {
			$this->clearCache();
			$this->cacheFailure($this->getWopiUrl());

			return null;
		}

		return $xml;
	}

    /**
     * 실제 discovery 요청을 보낸다.
     * status에 status code를 담고, response의 body를 리턴한다.
     */
	private function requestDiscovery(?int $timeout = null, ?int &$status = null, ?string $address = null): ?string {
		$wopiUrl = $address ?? $this->getWopiUrl();

		if ($wopiUrl === '') {
			$this->logger->error(
				'Cannot fetch discovery: no web office server address is configured',
				['app' => self::APP_ID]
			);

			return null;
		}

		$url = $wopiUrl . '/hosting/discovery'; // WOPIUrl + /hosting/discovery

		try {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => $timeout ?? self::REQUEST_TIMEOUT,
				// timeout 만으로는 응답 없는 주소에서 연결 단계가 길어져 예상보다
				// 오래 걸린다. 사람이 기다리는 확인이므로 연결 단계도 함께 묶는다.
				'connect_timeout' => $timeout ?? self::REQUEST_TIMEOUT,
				'http_errors' => $status === null, // 이 인자가 true일 경우 예외가 바로 터져 상태 코드를 알 수 없음. false일 경우 상태 코드를 받아올 수 있음
				'nextcloud' => ['allow_local_address' => true],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to fetch discovery from ' . $url, [
				'app' => self::APP_ID,
				'exception' => $e,
			]);

			return null;
		}

		$status = $response->getStatusCode();

		return (string)$response->getBody();
	}

	/**
     * urlSrc를 정규화하여 리턴합니다.
     * 1. <> 꺽쇠를 제거
     * 2. 쿼리 파라미터의 구분자인 (?, &)을 추가한다.
	 */
	private function normalizeUrlSrc(string $urlSrc): string {
		$urlSrc = preg_replace('/<[^>]*>/', '', $urlSrc) ?? $urlSrc;

		if (str_ends_with($urlSrc, '?') || str_ends_with($urlSrc, '&')) {
			return $urlSrc;
		}

		return $urlSrc . (str_contains($urlSrc, '?') ? '&' : '?');
	}
}

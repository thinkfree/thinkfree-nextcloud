<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Service;

use OCA\Thinkfree\AppInfo\Application;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * WOPI 프루프 키 검증.
 *
 * access_token 은 "이 파일을 이 사용자로 다룰 권한"만 증명한다. 토큰이 어딘가로
 * 새면 누구든 그 파일을 읽고 쓸 수 있다. 프루프 키는 여기에 "이 요청이 정말 그
 * 웹오피스에서 왔다"는 확인을 더한다.
 *
 * 웹오피스는 요청마다 자기 개인키로 서명한 헤더를 붙이고, 대응하는 공개키를
 * discovery 문서에 실어 보낸다. 우리는 요청 내용으로 같은 바이트 배열을 만들어
 * 서명을 검증한다.
 *
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/concepts#proof-keys
 */
class ProofKeyService {
	/**
	 * 명세가 정한 허용 오차. 이보다 오래된 요청은 재전송으로 본다.
	 */
	private const TIMESTAMP_TOLERANCE = 1200;

	/**
	 * .NET 의 tick 은 서력 1년부터 100나노초 단위로 센다. Unix epoch 까지의
	 * tick 수를 빼고 1천만으로 나누면 초가 된다.
	 */
	private const TICKS_AT_UNIX_EPOCH = 621355968000000000;

	private const ALGORITHM = OPENSSL_ALGO_SHA256;

	private DiscoveryService $discovery;
	private LoggerInterface $logger;

	public function __construct(
		DiscoveryService $discovery,
		LoggerInterface $logger,
	) {
		$this->discovery = $discovery;
		$this->logger = $logger;
	}

	/**
	 * 요청의 proof 헤더를 검증한다.
	 *
	 * 어떤 키로 검증할지는 discovery 조회 결과에 따라 정한다.
	 *
	 *   키를 찾음          그 키로 검증한다
	 *   키가 없음          거부한다
	 *   조회 실패          마지막으로 성공한 키로 검증한다. 그런 키가 없으면 거부한다
	 *
	 * Thinkfree Office 는 discovery 에 항상 proof 키를 싣는다. 키가 없는 discovery 는
	 * 웹오피스가 아니거나 중간에서 바뀐 것이므로 통과시키지 않는다. 통과시키면
	 * discovery 에서 proof-key 만 지워 서명 검사를 끌 수 있다.
	 */
	public function verify(IRequest $request, string $accessToken): bool {
		$lookup = $this->discovery->getProofKey();

		if ($lookup['status'] === DiscoveryService::PROOF_KEY_ABSENT) { // ProofKey가 없을 경우
			$this->logger->warning('Rejected WOPI request: discovery declares no proof key', [
				'app' => Application::APP_ID,
			]);

			return false; // 요청을 거부
		}

		$key = $lookup['key'];

		if ($key === null) {
			// discovery 를 받지 못했다. 마지막으로 성공한 키로 검증을 이어 간다.
			$key = $this->discovery->getLastProofKey();

			if ($key === null) { // 마지막으로 성공했던 키가 없으면 false를 반환
				$this->logger->warning('Rejected WOPI request: discovery is unavailable and no proof key has been seen yet', [
					'app' => Application::APP_ID,
				]);

				return false;
			}

			$this->logger->info('Discovery is unavailable, verifying with the last known proof key', [
				'app' => Application::APP_ID,
			]);
		}

		$proof = $request->getHeader('X-WOPI-Proof');
		$proofOld = $request->getHeader('X-WOPI-ProofOld');
		$timestamp = $request->getHeader('X-WOPI-TimeStamp');

		if ($timestamp === '' || ($proof === '' && $proofOld === '')) {
			$this->logger->warning('Rejected WOPI request: proof headers are missing', [
				'app' => Application::APP_ID,
			]);

			return false;
		}

		if (!$this->isTimestampFresh($timestamp)) { // X-WOPI-TimeStamp 검사
			return false;
		}

        // 액세스토큰, URL, timestamp를 조합해서 바이트 배열을 만든다.
        // client 측에서 같은 바이트 배열을 만들어서 보냈는지 검증하기 위함
		$expected = $this->expectedBytes($accessToken, $this->requestUrl($request), $timestamp);

        // 바이트 배열 검사
		if ($this->matches($expected, $proof, $proofOld, $key)) {
			return true;
		}

		// 웹오피스가 키를 교체한 직후라면 우리 캐시가 옛 키를 들고 있을 수 있다.
		// 명세도 이 경우 discovery 를 다시 읽고 한 번 더 시도하라고 한다.
		// 다시 읽어서 새 키를 얻었을 때만 한 번 더 검사한다. 다시 읽기에 실패하면
		// 방금 확인한 키 말고는 쓸 키가 없으므로 거부한다.
		$this->discovery->clearCache();
		$retry = $this->discovery->getProofKey();

        // 다시 검사
		if ($retry['status'] === DiscoveryService::PROOF_KEY_FOUND // 다시 검사해서 찾았을 때
			&& $this->matches($expected, $proof, $proofOld, $retry['key'])) {
			return true;
		}

		$this->logger->warning('Rejected WOPI request: proof key verification failed', [
			'app' => Application::APP_ID,
			// URL 불일치가 가장 흔한 원인이라 무엇으로 검증했는지 남긴다.
            // access_token을 redactToken을 사용해서 로그에서 블러 처리한다.
			'url' => $this->redactToken($this->requestUrl($request)),
		]);

		return false;
	}

	/**
	 * 명세가 정한 세 조합을 순서대로 시도한다. 키 교체 중에도 검증이 끊기지
	 * 않도록, 현재 키와 옛 키를 교차로 확인한다.

	 * @param array{modulus: string, exponent: string, oldmodulus: string, oldexponent: string} $key
	 */
	private function matches(string $expected, string $proof, string $proofOld, array $key): bool {
		$current = $this->publicKey($key['modulus'], $key['exponent']);
		$previous = $this->publicKey($key['oldmodulus'], $key['oldexponent']);

		$attempts = [
			[$proof, $current],
			[$proofOld, $current],
			[$proof, $previous],
		];

		foreach ($attempts as [$signature, $publicKey]) { // 세 조합을 시도하고 하나라도 성공하면 true 리턴
			if ($signature === '' || $publicKey === null) {
				continue;
			}

			$raw = base64_decode($signature, true);

			if ($raw === false || $raw === '') {
				continue;
			}

			if (openssl_verify($expected, $raw, $publicKey, self::ALGORITHM) === 1) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 서명 대상 바이트 배열.
	 *
	 * 값마다 길이를 4바이트 빅엔디안으로 앞에 붙여 이어 붙인다. URL 은 대문자로
	 * 바꾸고, 타임스탬프는 8바이트 정수로 넣는다. 한 바이트만 어긋나도 서명이
	 * 맞지 않으므로 순서와 형식을 명세 그대로 따른다.
	 */
	private function expectedBytes(string $accessToken, string $url, string $timestamp): string {
		return $this->lengthPrefixed($accessToken)
			. $this->lengthPrefixed(strtoupper($url))
			. pack('N', 8) . pack('J', (int)$timestamp);
	}

	private function lengthPrefixed(string $value): string {
		return pack('N', strlen($value)) . $value;
	}

	/**
	 * 웹오피스가 호출한 URL. access_token 을 포함한 전체 주소여야 한다.
	 */
	private function requestUrl(IRequest $request): string {
		return $request->getServerProtocol() . '://'
			. $request->getServerHost()
			. $request->getRequestUri();
	}

    /**
     * proof header의 timestamp를 검사하여 20분 이내면 true, 아니라면 false를 반환
     * @param string $timestamp
     * @return bool
     */
	private function isTimestampFresh(string $timestamp): bool {
		if (!ctype_digit($timestamp)) {
			$this->logger->warning('Rejected WOPI request: malformed proof timestamp', [
				'app' => Application::APP_ID,
			]);

			return false;
		}

		$seconds = intdiv((int)$timestamp - self::TICKS_AT_UNIX_EPOCH, 10000000);
		$age = time() - $seconds;

		if (abs($age) > self::TIMESTAMP_TOLERANCE) {
			$this->logger->warning('Rejected WOPI request: proof timestamp is off by ' . $age . 's', [
				'app' => Application::APP_ID,
			]);

			return false;
		}

		return true;
	}

	/**
	 * discovery 는 RSA 공개키를 base64 로 인코딩한 modulus 와 exponent 로 준다.
	 * 이 메서드에서 modulus와 exponent를 디코딩하고, openssl이 쓸 수 있는 공개키로 만든다.
     *
     * 자바의 RSAPublicKeySpec 메서드를 php에서는 직접 구현해야함
	 *
	 * @return \OpenSSLAsymmetricKey|null
	 */
	private function publicKey(string $modulusB64, string $exponentB64) {
        // base64 디코딩
		$modulus = base64_decode($modulusB64, true);
		$exponent = base64_decode($exponentB64, true);

		if ($modulus === false || $exponent === false || $modulus === '' || $exponent === '') {
			return null;
		}

		$rsaPublicKey = $this->derSequence(
			$this->derInteger($modulus) . $this->derInteger($exponent)
		);

		// AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1) + NULL
		$algorithm = $this->derSequence(
			$this->derOid("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00"
		);

		$subjectPublicKeyInfo = $this->derSequence(
			$algorithm . $this->derBitString($rsaPublicKey)
		);

		$pem = "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
			. "-----END PUBLIC KEY-----\n";

		$key = openssl_pkey_get_public($pem);

		return $key === false ? null : $key;
	}

	private function derLength(int $length): string {
		if ($length < 0x80) {
			return chr($length);
		}

		$bytes = '';

		while ($length > 0) {
			$bytes = chr($length & 0xff) . $bytes;
			$length >>= 8;
		}

		return chr(0x80 | strlen($bytes)) . $bytes;
	}

	/** DER 정수는 최상위 비트가 켜져 있으면 부호 때문에 0 을 앞에 붙여야 한다. */
	private function derInteger(string $raw): string {
		$raw = ltrim($raw, "\x00");

		if ($raw === '') {
			$raw = "\x00";
		}

		if ((ord($raw[0]) & 0x80) !== 0) {
			$raw = "\x00" . $raw;
		}

		return "\x02" . $this->derLength(strlen($raw)) . $raw;
	}

	private function derSequence(string $content): string {
		return "\x30" . $this->derLength(strlen($content)) . $content;
	}

	private function derBitString(string $content): string {
		// 첫 바이트는 "쓰이지 않는 비트 수"이고 여기서는 항상 0 이다.
		$content = "\x00" . $content;

		return "\x03" . $this->derLength(strlen($content)) . $content;
	}

	private function derOid(string $encoded): string {
		return "\x06" . $this->derLength(strlen($encoded)) . $encoded;
	}

    /**
     * url을 전달받아 쿼리 파라미터에 있는 access_token을 블러 처리한다.
     */
    private function redactToken(string $url)
    {
        return preg_replace('/([?&]access_token=)[^&#]*/i', '$1***', $url) ?? $url;
    }

}

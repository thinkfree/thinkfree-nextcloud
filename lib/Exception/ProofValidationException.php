<?php

declare(strict_types=1);

namespace OCA\Thinkfree\Exception;

use OCP\AppFramework\Http;

/**
 * proof 서명을 검증하지 못했을 때 던진다. 서명이 틀렸거나, 헤더가 없거나, 20분보다
 * 오래됐거나, 검증할 키를 구하지 못한 경우다.
 *
 * WOPI 명세는 이때 500 으로 응답하라고 한다.
 *
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/online/scenarios/proofkeys
 */
class ProofValidationException extends WopiRequestException {
	public function getStatus(): int {
		return Http::STATUS_INTERNAL_SERVER_ERROR;
	}
}

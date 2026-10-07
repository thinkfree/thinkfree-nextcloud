<?php

namespace OCA\Thinkfree\Sections;

use OCP\IL10N;
use OCP\Settings\IIconSection;
use OCP\IURLGenerator;

class ThinkfreeAdmin implements IIconSection {
    private $l;
    private $urlGenerator;

    public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
        $this->l = $l;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'thinkfree';
    }

    /**
     * 관리자 메뉴에 표시될 이름(라벨)을 반환
     */
    public function getName(): string {
        return $this->l->t('Thinkfree Office');
    }

    /**
     * 메뉴 항목의 우선순위를 지정
     * 낮은 값일수록 위쪽에 배치
     */
    public function getPriority(): int {
        return 98;
    }

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('thinkfree', 'app-dark.svg');
	}
}

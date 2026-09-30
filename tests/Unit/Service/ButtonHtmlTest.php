<?php

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Service;

use OCA\Ticketcheck\Service\ButtonHtml;
use PHPUnit\Framework\TestCase;

class ButtonHtmlTest extends TestCase
{
	public function testLinkPrimaryEscapesLabelAndHref(): void
	{
		$html = ButtonHtml::link('/go', 'Create & save', ButtonHtml::VARIANT_PRIMARY);

		$this->assertStringContainsString('href="/go"', $html);
		$this->assertStringContainsString('helpdesk-btn helpdesk-btn--primary', $html);
		$this->assertStringContainsString('Create &amp; save', $html);
		$this->assertStringNotContainsString('Create & save', $html);
	}

	public function testButtonSecondaryWithExtraClassAndSize(): void
	{
		$html = ButtonHtml::button(
			'Cancel',
			ButtonHtml::VARIANT_SECONDARY,
			['class' => 'tc-export__clear', 'data-action' => 'clear'],
			'sm',
		);

		$this->assertStringContainsString('type="button"', $html);
		$this->assertStringContainsString('helpdesk-btn--secondary', $html);
		$this->assertStringContainsString('helpdesk-btn--sm', $html);
		$this->assertStringContainsString('tc-export__clear', $html);
		$this->assertStringContainsString('data-action="clear"', $html);
	}

	public function testUnknownVariantThrows(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		ButtonHtml::buildClass('invalid');
	}
}

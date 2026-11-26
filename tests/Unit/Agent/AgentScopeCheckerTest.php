<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\Ticketcheck\Tests\Unit\Agent;

use OCA\Ticketcheck\Agent\AgentException;
use OCA\Ticketcheck\Agent\AgentScopeChecker;
use PHPUnit\Framework\TestCase;

/**
 * Scope vocabulary is exact-match only — the checks an attacker would
 * probe first (wildcards, prefix tricks, case games) must all fail closed.
 */
class AgentScopeCheckerTest extends TestCase
{
	public function testValidFormatsAccepted(): void
	{
		$this->assertTrue(AgentScopeChecker::isValidFormat('ticketcheck:time_entries:read'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('ticketcheck:invoices:write'));
		$this->assertTrue(AgentScopeChecker::isValidFormat('a1_b2:c3_d4:write'));
	}

	public function testInvalidFormatsRejected(): void
	{
		$cases = [
			'',
			'ticketcheck:time_entries',                 // missing action
			'ticketcheck:time_entries:read:extra',      // too many segments
			'ticketcheck:Time_Entries:READ',            // case games
			'ticketcheck:*:read',                       // wildcard resource
			'ticketcheck:time_entries:*',               // wildcard action
			'*:*:*',
			'ticketcheck:*',                            // app-wide wildcard
			'ticketcheck :time_entries:read',           // embedded space
			"ticketcheck:time_entries:read\n",          // control chars
			'ticketcheck:time-entries:read',            // dash not allowed
			'ticketcheck::read',                        // empty resource
			':time_entries:read',                      // empty app
			'ticketcheck:time_entries:delete',          // v1 action set is read|write only
		];
		foreach ($cases as $scope) {
			$this->assertFalse(
				AgentScopeChecker::isValidFormat($scope),
				"scope should be invalid: " . var_export($scope, true)
			);
		}
	}

	public function testPrefixGrantIsNotSatisfaction(): void
	{
		// granted scope that is a string-prefix of the requirement must NOT
		// satisfy — exact match is the whole security model.
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['ticketcheck:time_ent'],
				'ticketcheck:time_entries:write',
			)
		);
		$this->assertFalse(
			AgentScopeChecker::satisfied(
				['ticketcheck:time_entries:write_extra'],
				'ticketcheck:time_entries:write',
			)
		);
	}

	public function testReadDoesNotSatisfyWrite(): void
	{
		$granted = ['ticketcheck:time_entries:read'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'ticketcheck:time_entries:write'),
			'read scope must never satisfy a write requirement'
		);
		$this->assertTrue(AgentScopeChecker::satisfied($granted, 'ticketcheck:time_entries:read'));
	}

	public function testPrefixMatchIsNotSatisfaction(): void
	{
		$granted = ['ticketcheck:time_entr:write', 'ticketcheck:time_entries_extended:write'];
		$this->assertFalse(
			AgentScopeChecker::satisfied($granted, 'ticketcheck:time_entries:write'),
			'prefix/partial matches must not satisfy'
		);
	}

	public function testValidateGrantRequestDeduplicatesAndValidates(): void
	{
		$declared = ['ticketcheck:a:read', 'ticketcheck:b:write'];
		$grants = AgentScopeChecker::validateGrantRequest(
			['ticketcheck:a:read', ' ticketcheck:a:read ', 'ticketcheck:b:write'],
			$declared,
		);
		$this->assertSame(['ticketcheck:a:read', 'ticketcheck:b:write'], $grants);
	}

	public function testValidateGrantRequestRejectsUndeclared(): void
	{
		$this->expectException(AgentException::class);
		AgentScopeChecker::validateGrantRequest(
			['ticketcheck:secret:read'],
			['ticketcheck:a:read'],
		);
	}

	public function testValidateGrantRequestRejectsWildcard(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['ticketcheck:*:read'], ['ticketcheck:a:read']);
			$this->fail('wildcard grant must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsEmpty(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest(['  '], ['ticketcheck:a:read']);
			$this->fail('empty grant list must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}

	public function testValidateGrantRequestRejectsNonString(): void
	{
		try {
			AgentScopeChecker::validateGrantRequest([['nested']], ['ticketcheck:a:read']);
			$this->fail('non-string scope must throw');
		} catch (AgentException $e) {
			$this->assertSame('validation', $e->getErrorCode());
		}
	}
}

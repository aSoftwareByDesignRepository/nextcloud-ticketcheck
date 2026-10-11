<?php

declare(strict_types=1);

/**
 * Inbound mail dedupe/audit row for helpdesk app
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row per claimed inbound message (IMAP or webhook).
 *
 * The row is claimed BEFORE side effects so two delivery paths racing the
 * same mail collapse to a single ticket/comment (spec §9.3 step 5).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getMailboxId()
 * @method void setMailboxId(string $mailboxId)
 * @method int getUid()
 * @method void setUid(int $uid)
 * @method int getUidvalidity()
 * @method void setUidvalidity(int $uidvalidity)
 * @method string getMsgHash()
 * @method void setMsgHash(string $msgHash)
 * @method string|null getMessageId()
 * @method void setMessageId(?string $messageId)
 * @method string|null getFromAddr()
 * @method void setFromAddr(?string $fromAddr)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getAction()
 * @method void setAction(string $action)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method \DateTime|null getClaimedAt()
 * @method void setClaimedAt(?\DateTime $claimedAt)
 * @method \DateTime|null getFinishedAt()
 * @method void setFinishedAt(?\DateTime $finishedAt)
 * @method \DateTime getReceivedAt()
 * @method void setReceivedAt(\DateTime $receivedAt)
 * @method int|null getTicketId()
 * @method void setTicketId(?int $ticketId)
 * @method int|null getCommentId()
 * @method void setCommentId(?int $commentId)
 * @method string|null getError()
 * @method void setError(?string $error)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 */
class InboundMessage extends Entity
{
	public const CHANNEL_IMAP = 'imap';
	public const CHANNEL_WEBHOOK = 'webhook';

	// Terminal + in-flight actions (spec §9.1). Actions starting with
	// 'ignored_' or 'error_' are terminal; 'processing' is the claim state.
	public const ACTION_PROCESSING = 'processing';
	public const ACTION_TICKET_CREATED = 'ticket_created';
	public const ACTION_COMMENT = 'comment';
	public const ACTION_IGNORED_AUTO = 'ignored_auto';
	public const ACTION_IGNORED_UNAUTHORIZED = 'ignored_unauthorized';
	public const ACTION_IGNORED_NO_TICKET = 'ignored_no_ticket';
	public const ACTION_IGNORED_BAD_TOKEN = 'ignored_bad_token';
	public const ACTION_IGNORED_CAP = 'ignored_cap';
	public const ACTION_IGNORED_POLICY = 'ignored_policy';
	public const ACTION_IGNORED_EMPTY = 'ignored_empty';
	public const ACTION_ERROR_RETRYABLE = 'error_retryable';
	public const ACTION_ERROR_PERMANENT = 'error_permanent';

	/** @var string */
	protected $mailboxId;

	/** @var int */
	protected $uid;

	/** @var int */
	protected $uidvalidity;

	/** @var string */
	protected $msgHash;

	/** @var string|null */
	protected $messageId;

	/** @var string|null */
	protected $fromAddr;

	/** @var string|null */
	protected $subject;

	/** @var string */
	protected $channel;

	/** @var string */
	protected $action;

	/** @var int */
	protected $attempts;

	/** @var \DateTime|null */
	protected $claimedAt;

	/** @var \DateTime|null */
	protected $finishedAt;

	/** @var \DateTime */
	protected $receivedAt;

	/** @var int|null */
	protected $ticketId;

	/** @var int|null */
	protected $commentId;

	/** @var string|null */
	protected $error;

	/** @var \DateTime */
	protected $createdAt;

	/** @var \DateTime */
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('mailboxId', 'string');
		$this->addType('uid', 'integer');
		$this->addType('uidvalidity', 'integer');
		$this->addType('msgHash', 'string');
		$this->addType('messageId', 'string');
		$this->addType('fromAddr', 'string');
		$this->addType('subject', 'string');
		$this->addType('channel', 'string');
		$this->addType('action', 'string');
		$this->addType('attempts', 'integer');
		$this->addType('claimedAt', 'datetime');
		$this->addType('finishedAt', 'datetime');
		$this->addType('receivedAt', 'datetime');
		$this->addType('ticketId', 'integer');
		$this->addType('commentId', 'integer');
		$this->addType('error', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function isTerminal(): bool
	{
		$action = $this->getAction();
		return $action !== self::ACTION_PROCESSING
			&& $action !== self::ACTION_ERROR_RETRYABLE;
	}
}

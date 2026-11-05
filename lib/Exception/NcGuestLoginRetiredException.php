<?php
declare(strict_types=1);
namespace OCA\Ticketcheck\Exception;
/** S3 door: an NC guest session hit /portal/** after NC login was refused. */
class NcGuestLoginRetiredException extends \Exception
{
}

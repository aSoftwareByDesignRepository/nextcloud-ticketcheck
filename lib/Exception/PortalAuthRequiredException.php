<?php
declare(strict_types=1);
namespace OCA\Ticketcheck\Exception;
class PortalAuthRequiredException extends \Exception
{
    public function __construct(private readonly string $reasonCode = 'login')
    {
        parent::__construct('portal session required: ' . $reasonCode);
    }
    public function reason(): string
    {
        return $this->reasonCode;
    }
}

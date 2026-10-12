<?php
declare(strict_types=1);

namespace Zero1\RequestLimiter\Block\Adminhtml\Requests;

use Magento\Backend\Block\Template;
use Zero1\RequestLimiter\Model\RequestLimiter;

class Listing extends Template
{
    private RequestLimiter $requestLimiter;

    public function __construct(
        Template\Context $context,
        RequestLimiter $requestLimiter,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->requestLimiter = $requestLimiter;
    }

    public function getRequests(): array
    {
        return $this->requestLimiter->getRateLimitedRequests();
    }
}
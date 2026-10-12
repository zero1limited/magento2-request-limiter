<?php
declare(strict_types=1);

namespace Zero1\RequestLimiter\Plugin;

use Magento\Framework\App\Http;
use Zero1\RequestLimiter\Model\RequestLimiter;

class ReleaseRequest
{
    private RequestLimiter $requestLimiter;

    public function __construct(RequestLimiter $requestLimiter)
    {
        $this->requestLimiter = $requestLimiter;
    }

    public function aroundLaunch(Http $subject, callable $proceed)
    {
        try {
            return $proceed();
        } finally {
            $this->requestLimiter->release();
        }
    }
}
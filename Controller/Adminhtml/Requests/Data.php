<?php
declare(strict_types=1);

namespace Zero1\RequestLimiter\Controller\Adminhtml\Requests;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Zero1\RequestLimiter\Model\RequestLimiter;

class Data extends Action
{
    public const ADMIN_RESOURCE = 'Zero1_RequestLimiter::view';

    private JsonFactory $resultJsonFactory;
    private RequestLimiter $requestLimiter;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        RequestLimiter $requestLimiter
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->requestLimiter = $requestLimiter;
    }

    public function execute()
    {
        return $this->resultJsonFactory->create()->setData([
            'requests' => $this->requestLimiter->getRateLimitedRequests(),
        ]);
    }
}
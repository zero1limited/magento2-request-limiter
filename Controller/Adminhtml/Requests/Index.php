<?php
declare(strict_types=1);

namespace Zero1\RequestLimiter\Controller\Adminhtml\Requests;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'Zero1_RequestLimiter::view';

    private PageFactory $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Rate-Limited Requests'));

        return $page;
    }
}
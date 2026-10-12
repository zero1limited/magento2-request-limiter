<?php
declare(strict_types=1);

namespace Zero1\RequestLimiter\Observer;

use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Zero1\RequestLimiter\Model\RequestLimiter;
use Magento\Framework\App\RequestInterface;

class CheckRequest implements ObserverInterface
{
    private const CONFIG_PATH_ENABLED = 'zero1_request_limiter/general/enabled';
    private const CONFIG_PATH_CONCURRENT_REQUESTS = 'zero1_request_limiter/general/concurrent_requests';
    private const CONFIG_PATH_MIN_QUERY_FILTERS = 'zero1_request_limiter/general/min_query_filters';
    private const CONFIG_PATH_MAINTENANCE_PAGE = 'zero1_request_limiter/general/maintenance_page';
    private const LIMITED_ACTIONS = [
        'catalog_category_view',
        'catalogsearch_result_index',
        'catalogsearch_advanced_index',
        'catalogsearch_advanced_result',
    ];

    private Http $request;
    private HttpResponse $response;
    private ActionFlag $actionFlag;
    private ScopeConfigInterface $scopeConfig;
    private RequestLimiter $requestLimiter;
    private GetPageByIdentifierInterface $getPageByIdentifier;
    private FilterProvider $filterProvider;
    private LoggerInterface $logger;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Http $request,
        HttpResponse $response,
        ActionFlag $actionFlag,
        ScopeConfigInterface $scopeConfig,
        RequestLimiter $requestLimiter,
        GetPageByIdentifierInterface $getPageByIdentifier,
        FilterProvider $filterProvider,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        protected RequestInterface $requestInterface,
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->actionFlag = $actionFlag;
        $this->scopeConfig = $scopeConfig;
        $this->requestLimiter = $requestLimiter;
        $this->getPageByIdentifier = $getPageByIdentifier;
        $this->filterProvider = $filterProvider;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
    }

    public function execute(Observer $observer): void
    {
        /** @var Magento\Framework\App\Request\Http $this->request */
        if (!(bool)$this->scopeConfig->getValue(self::CONFIG_PATH_ENABLED)) {
            return;
        }

        if (!in_array($this->request->getFullActionName(), self::LIMITED_ACTIONS, true)) {
            return;
        }

        $queryParameters = [];
        parse_str((string)$this->request->getServer('QUERY_STRING'), $queryParameters);
        $minimumFilters = max(1, (int)$this->scopeConfig->getValue(self::CONFIG_PATH_MIN_QUERY_FILTERS));
        if (count($queryParameters) < $minimumFilters) {
            return;
        }

        $concurrentLimit = max(1, (int)$this->scopeConfig->getValue(self::CONFIG_PATH_CONCURRENT_REQUESTS));
        if ($this->requestLimiter->acquire($this->request->getPathInfo(), $concurrentLimit)) {
            return;
        }

        $this->response->setHttpResponseCode(503);
        $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8', true);
        $this->response->setHeader('Retry-After', (string)RequestLimiter::WINDOW_SECONDS, true);
        $this->response->setBody($this->getMaintenanceContent());
        $this->actionFlag->set('', ActionInterface::FLAG_NO_DISPATCH, true);
        $requestUrl = $this->getFullRequestUrl();
        $this->logger->info('Rate limiter blocked request: ' . $requestUrl, [
            'url' => $requestUrl,
        ]);
    }

    private function getMaintenanceContent(): string
    {
        $configuredIdentifier = trim((string)$this->scopeConfig->getValue(self::CONFIG_PATH_MAINTENANCE_PAGE));
        if ($configuredIdentifier !== '' && $configuredIdentifier !== '0') {
            $pageIdentifier = explode('|', $configuredIdentifier, 2)[0];
            try {
                $page = $this->getPageByIdentifier->execute(
                    $pageIdentifier,
                    (int)$this->storeManager->getStore()->getId()
                );
                $filter = $this->filterProvider->getPageFilter();
                $filter->setVariables([
                    'request_url' => $this->getQuerylessRequestUrl(),
                ]);

                return $filter->filter((string)$page->getContent());
            } catch (Throwable $exception) {
                $this->logger->error('Request limiter could not load the configured maintenance CMS page.', [
                    'page_identifier' => $pageIdentifier,
                    'exception' => $exception,
                ]);
            }
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Service temporarily busy</title></head>' .
            '<body><main><h1>Service temporarily busy</h1>' .
            '<p>The server is busy right now. Please try again later or remove some query parameters.</p>' .
            '</main></body></html>';
    }

    private function getQuerylessRequestUrl(): string
    {
        $uri = $this->request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        if ($scheme === '' || $host === '') {
            return $this->request->getPathInfo();
        }

        $port = $uri->getPort();
        $includePort = $port && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80));

        return $scheme . '://' . $host . ($includePort ? ':' . $port : '') . $uri->getPath();
    }

    private function getFullRequestUrl(): string
    {
        $host = (string)$this->request->getServer('HTTP_HOST');
        $requestUri = (string)$this->request->getServer('REQUEST_URI');
        if ($host !== '' && str_starts_with($requestUri, '/')) {
            $scheme = $this->request->isSecure() ? 'https' : 'http';
            return $scheme . '://' . $host . $requestUri;
        }

        return $this->request->getUriString();
    }
}
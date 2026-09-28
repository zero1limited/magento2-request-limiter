# Request Limiter

The limiter applies to category and catalog search result pages when the URL has
at least the configured number of query parameters. It counts concurrent
requests per request path, ignoring the query string.

Configure it in **Stores > Configuration > Services > Request Limiter**:

- **Concurrent Requests**: maximum active requests for the same path (default: 10).
- **Min Query Filters**: minimum number of query parameters required (default: 5).

Reservations are stored as Redis hashes for 60 seconds and removed after the
Magento application has rendered the response. Redis uses the database configured
for Magento's default cache backend (database 0 by default). If Redis is
unavailable, requests are allowed and the error is logged so the limiter does not
take the storefront offline.

The selected maintenance CMS page can include `{{var request_url}}` to render the
requested URL without its query parameters.


## Testing
This can be a a little tricky to test as there are a few nuances to it.

1. Ensure php-fpm max_children is considerably higher then your max concurrent request settings. For testing, recommend max concurrent: 3, max child processes 6 or 7.

2. Run 5 shells with:
  ```
  while [ true ]; do curl -IX GET "YOUR_URL?a=b&c=d&e=f&gREQUESTER_ID=$(date +%s)" | grep HTTP; sleep 1; done
  ```
  Change `YOUR_URL` - with the url you want.  
  Change `REQUESTER_ID` - to a unique url for that shell. If you don't there is a chance two or more requests will happen in the same second and because   the url is the same. Varnish will only make 1 request to PHP.  

You should then be able to 503s in some of the shells.  

To debug what's in redis you can do something like
```
while [ true ]; do redis-cli -h 21820-redis -n 0 hkeys rate-limit:/catalog/category/view/id/20; sleep 1; echo "-------------------"; done
```
Which is pretty much what the MAP does.
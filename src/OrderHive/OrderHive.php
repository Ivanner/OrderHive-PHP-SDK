<?php
/**
 * OrderHive PHP API Client
 *
 * @author ivanner@gmail.com
 * @version 1.0.0
 */
namespace OrderHive;

use OrderHive\Exceptions\OrderHiveException;
use OrderHive\HttpClients\CurlHttpClient;
use OrderHive\HttpClients\GuzzleHttpClient;
use OrderHive\Models\Product;

/**
 *
 * @package OrderHive
 */
class OrderHive
{
    /**
     * @const string
     */
    const VERSION = '1.0.0';

    /**
     *@const string
     */
    const API_URL = 'https://api.orderhive.com';

    /**
     * @const string
     */
    const HOST = 'api.orderhive.com';

    /**
     * @var string
     */
    protected $id_token = '';

    /**
     * @var string
     */
    protected $refresh_token = '';
	
	/**
	 * @var array
	 */
	protected $headers = [];
	
	/**
	 * @var
	 */
	protected $client;
	
	/**
	 * @var string
	 */
	protected $client_http = 'curl';
	
	/**
	 * @var
	 */
	protected $last_response;
	
	/**
	 *
	 * @param  array  $config
	 *        - id_token: generated token on OrderHive
	 *        - refresh_token: your OrderHive email
	 *        - client_http: curl (default) or guzzle
	 * @throws OrderHiveException
	 */
	public function __construct(array $config = [])
	{
		if (isset($config['id_token'])) {
			$this->id_token = $config['id_token'];
		}
		if (empty($this->id_token)) {
			throw new OrderHiveException('Config "id_token" is required');
		}
		
		if (isset($config['refresh_token'])) {
			$this->refresh_token = $config['refresh_token'];
        }
        if (empty($this->refresh_token)) {
            throw new OrderHiveException('Config "refresh_token" is required');
        }

        $this->headers = [
            'content-type' => 'application/json',
            'host' => self::HOST
        ];

        if (isset($config['client_http'])) {
            $this->client_http = $config['client_http'];
        }
        if ($this->client_http == 'curl') {
            $this->client = new CurlHttpClient(array(
                'url' => self::API_URL,
                'headers' => $this->headers
            ));
        } else if ($this->client_http == 'guzzle') {
            $this->client = new GuzzleHttpClient(array(
                'url' => self::API_URL,
                'headers' => $this->headers
            ));
        } else {
            throw new OrderHiveException('Invalid client_http. Allowed clients: curl, guzzle');
        }
    }

    private function signRequest($cmd, $httpMethodName = 'POST', $queryParams = [], $postParams = [])
    {
        $response = $this->client->post('/setup/refreshtokenviaidtoken', [
            'id_token' => $this->id_token,
            'refresh_token' => $this->refresh_token,
        ]);
        // obtained on refresh token
        $this->client->addHeader('id_token', $response['id_token']);
        // current Date Time ('YmdTHMSZ' format)
        $this->client->addHeader('X-Amz-Date', gmdate("Ymd\THis\Z"));
        // Session Token which you get in the refreshtokenviaidtoken api (Expired in 1 hour)
        $this->client->addHeader('X-Amz-Security-Token', $response['session_token']);
        $accessKeyID = $response['access_key_id'];
        $secretAccessKey = $response['secret_key'];
        $regionName = $response['region'];
        $serviceName = 'execute-api';
        $get_aws4_sign = new AWSV4(
            $accessKeyID,
            $secretAccessKey,
            $regionName,
            $serviceName,
            $httpMethodName,
            $cmd,
            $queryParams,
            $this->headers,
	        $postParams);
	    $this->client->addHeader('Authorization', $get_aws4_sign->getAuthorizationHeaders());
    }
	
	/**
	 *
	 * @param  string  $cmd
	 * @param  array  $params
	 * @return array
	 */
	public function get($cmd)
	{
		$params = [];
		if (strpos($cmd, '?') !== false) {
			list($url, $querystring) = explode('?', $cmd);
			parse_str($querystring, $params);
		} else {
			$url = $cmd;
		}
		$this->signRequest($url, 'GET', $params);
		$this->last_response = $this->client->get($cmd);
		return $this->last_response;
	}
	
	/**
	 *
	 * @param  string  $cmd
	 * @param  array  $params
	 * @return array
	 */
	public function post($cmd, array $params = [])
	{
		$output = [];
		if (strpos($cmd, '?') !== false) {
			list($url, $querystring) = explode('?', $cmd);
			parse_str($querystring, $output);
		} else {
			$url = $cmd;
		}

//        $params = array_merge($params, $output);
		$this->signRequest($url, 'POST', $output, $params);
		$this->last_response = $this->client->post($cmd, $params);
		return $this->last_response;
	}

    /**
     *
     * @param string $cmd
     * @param array $params
     * @return array
     */
    public function put($cmd, array $params = [])
    {
		$output = [];
		if (strpos($cmd, '?') !== false) {
			list($url, $querystring) = explode('?', $cmd);
			parse_str($querystring, $output);
		} else {
			$url = $cmd;
		}
		$this->signRequest($url, 'PUT', $output, $params);
	    $this->last_response = $this->client->put($cmd, $params);
	    return $this->last_response;
    }
	
	/**
	 *
	 * @param  string  $cmd
	 * @param  array  $params
	 * @return array
	 */
	public function delete($cmd)
	{
		$this->last_response = $this->client->delete($cmd);
		return $this->last_response;
	}
	
	/**
	 *
	 * @return array
	 */
	public function get_last_response()
	{
		return $this->last_response;
	}
	
	/**
	 * Search product with given value. The product will be searched in name, sku, barcode and asin fields.
	 * @param  string  $query
	 * @return array
	 */
	public function productSearch(string $query)
	{
		$result = $this->get("/product/index/elastic/search?query=$query");
		$products = [];
		if (isset($result['products'])) {
			foreach ($result['products'] as $product) {
				$products[] = new Product($product);
			}
		}
		return $products;
	}
	
	/**
	 * Get product by id.
	 * @param $id
	 * @return Product|null
	 */
	public function getProduct($id)
	{
		$result = $this->get("/product/$id");
		if ($result) {
			return new Product($result);
		}
		return null;
	}
	
	/**
	 * Retrieves product list with filter options, search, sort and pagination.
	 * @param  array  $params
	 * @param  int  $page
	 * @param  int  $size
	 * @return array
	 */
	public function productCatalog($params = [], $page = 1, $size = 10)
	{
		$result = $this->post("/product/listing/flat?page=$page&size=$size", $params);
		$products = [];
		if (isset($result['products'])) {
			foreach ($result['products'] as $product) {
				$products[] = new Product($product);
			}
		}
		return $products;
	}
	
	/**
	 * Retrieves product inventory for desired products.
	 * @param  array  $IDs  Product ids
	 * @return array
	 */
	public function productInventoryList(array $IDs)
	{
		$result = $this->post("/product/warehouses", ['ids' => $IDs]);
		$products = [];
		if (isset($result['products'])) {
			foreach ($result['products'] as $product) {
				$products[] = new Product($product);
			}
		}
		return $products;
	}
}

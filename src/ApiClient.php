<?php

namespace Sinterix;

use Exception;
use Sinterix\Exceptions\RequestError;

class ApiClient {

    /**
     * Token lifetime in seconds
     * 10 minutes, less 5 second buffer.
     */
    public const TOKEN_TTL = 595;

    private ?string $token = null;

    public function __construct(
        private string $apiBaseUrl,
        private string $username,
        private string $password,
        private string $key,
        private TokenStorageInterface $tokenStorage
    ) {}

    /**
     * Get customer information
     */
    public function getCustomerInfo(mixed $id, bool $useReferenceId=false): array {
        return $this->makeRequest('GET', 'get_cid_info', [$useReferenceId ? 'reference_id' : 'cid' => $id]);
    }

    /**
     * Get customer information by reference_id
     */
    public function getCustomerInfoByReferenceId(mixed $refId): array {
        return $this->getCustomerInfo($refId, true);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function getAllPackages(): array {
        return $this->makeRequest('GET', 'get_agent_packages_addons');
    }

    /**
     * @param int $packageId
     * @return array
     * @throws Exception
     */
    public function getPackageChannels(int $packageId): array {
        $result = $this->makeRequest('GET', 'get_package_channels', ['package_id' => $packageId]);
        # this endpoint returns some nonsensical json with a bunch of empty strings when the resource is not found.
        # lets try to turn that into a reasonable result by checking for the presence of an id.
        if(empty($result['id'])) {
            throw new RequestError('Package not found');
        }

        return $result;
    }

    /**
     * Set the customer packages exactly as defined.
     * Currently packages will be replaced by whatever is provided here.
     */
    public function setCustomerPackage(
        int $cid,
        int $packageId,
        array $addOnsIds=[],
        array $pickPayChannelIds=[],
        array $standaloneChannelIds=[]
    ): void {

        $params = [
            'cid' => $cid,
            'package_id' => $packageId
        ];

        if(count($addOnsIds) > 0) {
            $params['addon_ids'] = implode(',', $addOnsIds);
            # these will only be processed if a pick-pack or standalone add-on is set in 'addon_ids'
            $params['pick_pay_channels[channels]'] = implode(',', $pickPayChannelIds);
            $params['standalone_channels'] = implode(',', $standaloneChannelIds);
        }

        # GET request.. actually behaves as a PUT request.
        # API does not return anything meaningful either.
        $this->makeRequest('GET', 'manage_package_channels', $params);
    }

    /**
     * Generic API request method
     *
     * @param string $action API action to call
     * @param array $params Additional parameters
     * @param bool $requiresToken Whether to include the token (default: true)
     * @param int $retryCount Number of retry attempts on token failure (default: 1)
     *
     * @return array API response
     * @throws Exception If the request fails
     */
    public function makeRequest(string $method, string $action, array $params=[], bool $requiresToken = true, int $retryCount = 1): array {
        if ($requiresToken) {
            $params['token'] = $this->getToken();
        }
        $params['action'] = $action;

        $ch = curl_init();

        if ('POST' === strtoupper($method)) {
            curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

        } elseif('GET' === strtoupper($method)) { // Default to GET
            curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . '?' . http_build_query($params));

        } else {
            throw new \RuntimeException("Unsupported request method: '{$method}'");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception("cURL error: " . curl_error($ch));
        }

        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        # The API returns 200 for almost every request and (usually) uses the "status" field to indicate request errors,
        # but there are a few exceptions I found such as a 403 and presumably a 5xx.
        if($httpStatus < 200 || $httpStatus >= 300) {

            if ($httpStatus >= 500) {
                throw new Exceptions\ServerError('', $httpStatus);

            } else if ($httpStatus === 403) {
                throw new \Exception('Request denied: make sure you are using a white-listed IP address');
            }

            throw new \Exception('Unexpected status code: ' . $httpStatus);
        }

        $decodedResponse = json_decode($response, true) ?? [];

        # status indicated if a request was successful or not (who needs status codes anyway)
        if(isset($decodedResponse['status']) && $decodedResponse['status'] === false) {
            if(isset($decodedResponse['msg'])) {
                # msg can either be a string or an array (also amazing)
                # string = general request error
                # array = input validation errors
                if (is_array($decodedResponse['msg'])) {
                    throw new Exceptions\ValidationError($decodedResponse['msg']);

                } else {
                    # If token is invalid, refresh and retry
                    if ($requiresToken && str_contains(strtolower($decodedResponse['msg']), 'token')) {
                        if ($retryCount > 0) {
                            $this->token = $this->fetchNewToken();
                            return $this->makeRequest($method, $action, $params, true, $retryCount - 1);
                        }
                    }

                    throw new Exceptions\RequestError($decodedResponse['msg']);
                }

            } else {
                # this should never happen, but we'll treat it as a server error if it does.
                throw new Exceptions\ServerError('Unknown Error');
            }
        }

        return $decodedResponse;
    }

    /**
     * Fetch a valid token, refreshing if necessary
     */
    private function getToken(): string {
        if ($this->token === null) {
            $this->token = $this->tokenStorage->getToken();
        }

        if (!$this->token) {
            $this->token = $this->fetchNewToken();
        }

        return $this->token;
    }

    /**
     * Fetch a new token from the API and store it
     */
    private function fetchNewToken(): string {
        $response = $this->makeRequest('GET', 'get_token', [
            'username' => $this->username,
            'password' => $this->password,
            'key' => $this->key,
        ], false); # Skip token injection

        if ($response['status'] === true && isset($response['token'])) {
            $this->tokenStorage->storeToken($response['token'], self::TOKEN_TTL);
            return $response['token'];
        }

        throw new Exception("Failed to fetch token: " . json_encode($response));
    }

}

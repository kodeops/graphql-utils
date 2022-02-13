<?php
namespace kodeops\LaravelGraphQL;

use Illuminate\Support\Facades\Http;
use kodeops\LaravelGraphQL\Exceptions\LaravelGraphQLException;

class Query
{
    protected $endpoint;
    protected $offset;
    protected $max_request_per_minute;

    public function __construct($endpoint = null, $offset = null, $max_request_per_minute = null)
    {
        $this->endpoint = $endpoint ?? env('LARAVEL_GRAPHQL_ENDPOINT');
        $this->offset = $offset ?? env('LARAVEL_GRAPHQL_OFFSET');
        $this->max_request_per_minute = $max_request_per_minute ?? env('LARAVEL_GRAPHQL_MAX_REQUEST_PER_MINUTE');
    }

    public function resolve($query, $method, $variables)
    {
        $body = [
            'query' => $query,
            'variables' => $variables,
            'operationName' => "GenericQuery",
        ];

        return $this->request($body, $method);
    }

    private function request(array $body, $method, $all_results = false)
    {
        // $all_results is used to automatically paginate through results
        if (! $this->endpoint) {
            throw new LaravelGraphQLException("Undefined GraphQL endpoint");
        }

        $request = Http::post($this->endpoint, $body);
    
        if ($request->failed()) {
            throw new LaravelGraphQLException("GraphQL request failed");
        }

        $response = $request->json();
        
        if (isset($response['errors'])) {
            throw new LaravelGraphQLException("Invalid GraphQL response: {$response['errors'][0]['message']}");
        }

        if (! isset($response['data'])) {
            throw new LaravelGraphQLException("Invalid GraphQL structure: missing data");
        }

        $data = $response['data'][$method];
        $more_results = count($data) >= $this->offset;
        if (! $more_results OR $all_results) {
            return $data;
        }

        // Keep resolving the query until there are no more results available
        $requests_made = 1;
        while ($more_results AND $requests_made < $this->max_request_per_minute) {
            $body['variables']['offset'] = count($data);
            $new_data = $this->request($body, $method, true);
            $data = array_merge($data, $new_data);
            $more_results = count($new_data) >= $this->offset;
            $requests_made++;
        }

        return $data;
    }
}
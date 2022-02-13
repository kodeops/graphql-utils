<?php
namespace kodeops\LaravelGraphQL;

use Illuminate\Support\Facades\Http;
use LaravelGraphQL\Exceptions\LaravelGraphQLException;

class Query
{
    protected $endpoint;
    protected $offset;

    public function __construct($endpoint = null, $offset = null)
    {
        $this->endpoint = $endpoint ?? env('LARAVEL_GRAPHQL_ENDPOINT');
        $this->offset = $offset ?? env('LARAVEL_GRAPHQL_OFFSET');
    }

    public function query($query, $method, $variables)
    {
        $body = [
            'query' => $query,
            'variables' => $variables,
            'operationName' => "GenericQuery",
        ];

        return $this->resolve($body, $method);
    }

    private function resolve(array $body, $method, $all_results = false)
    {
        // $all_results is used to automatically paginate through results
        if (! $this->endpoint) {
            throw new LaravelGraphQLException("Undefined GraphQL endpoint");
        }

        $response = Http::post($this->endpoint, $body)->throw()->json();

        if (! isset($response['data'][$method])) {
            throw new LaravelGraphQLException("Invalid GraphQL response: {$response['errors'][0]['message']}");
        }

        $data = $response['data'][$method];
        $more_results = count($data) >= $this->offset;
        if (! $more_results OR $simple) {
            return $data;
        }

        // Keep resolving the query until there are no more results available
        while ($more_results) {
            $body['variables']['offset'] = count($data);
            $new_data = $this->query($body, $method, true);
            $data = array_merge($data, $new_data);
            $more_results = count($new_data) >= $this->offset;
        }

        return $data;
    }
}
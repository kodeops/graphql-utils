<?php
namespace kodeops\LaravelGraphQL;

use Illuminate\Support\Facades\Http;
use kodeops\LaravelGraphQL\Exceptions\LaravelGraphQLException;

class Query
{
    protected $endpoint;
    protected $offset;
    protected $max_request_per_minute;
    protected $fragments;

    public function __construct($endpoint = null, $offset = null, $max_request_per_minute = null)
    {
        $this->endpoint = $endpoint ?? env('LARAVEL_GRAPHQL_ENDPOINT');

        if (is_null($this->endpoint) OR empty($this->endpoint)) {
            throw new LaravelGraphQLException("Undefined GraphQL endpoint.");
        }

        $this->offset = $offset ?? env('LARAVEL_GRAPHQL_OFFSET');
        $this->max_request_per_minute = $max_request_per_minute ?? env('LARAVEL_GRAPHQL_MAX_REQUEST_PER_MINUTE');
        $this->fragments = [];
    }

    public function addFragment(array $fragments)
    {
        $this->fragments = $fragments;
        return $this;
    }

    public function build($query, $variables, $fragments = [])
    {
        $query_needle = 'query ';
        if (substr($query, 0, 6) != $query_needle) {
            throw new LaravelGraphQLException("Invalid query format (must start with 'query ' {$query}");
        }

        if (substr($query, -1) != '}') {
            throw new LaravelGraphQLException("Invalid query format (must end with '}' {$query}");
        }

        // Extract operation name from $query
        // Eg: "query GenericQuery(" to "GenericQuery"
        $operationName = explode('query ', $query);
        $operationName = explode('(', $operationName[1])[0];

        if (count($fragments)) {
            $add_fragments = '';
            foreach ($this->fragments as $fragment) {
                $add_fragments .= PHP_EOL . $fragment . PHP_EOL;
            }

            $query .= $add_fragments;
        }
        return [
            'query' => $query,
            'variables' => $variables,
            'operationName' => $operationName,
        ];
    }

    public function resolve($query, $variables, $fragments = [])
    {
        $this->fragments = $fragments;
        return $this->request($this->build($query, $variables, $fragments));
    }

    private function request(array $body)
    {
        $request = Http::post($this->endpoint, $body);

        if ($request->failed()) {
            throw new LaravelGraphQLException("GraphQL request failed: {$request->body()}");
        }

        $response = $request->json();
        if (isset($response['errors'])) {
            throw new LaravelGraphQLException("GraphQL error: {$response['errors'][0]['message']}");
        }

        if (! isset($response['data'])) {
            throw new LaravelGraphQLException("Invalid GraphQL structure: missing data");
        }

        return $response;
    }

    private function paginate(array $body, $entity, $pages)
    {
        $response = $this->request($body);

        if (! isset($response[$entity])) {
            throw new LaravelGraphQLException("Entity not found: {$entity}");
        }

        $data = $response['data'][$entity];
        $more_results = count($data) >= $this->offset;
        if (! $more_results OR $all_results) {
            return $data;
        }

        // Keep resolving the query until there are no more results available
        $requests_made = 1;
        while ($more_results AND $requests_made < $this->max_request_per_minute) {
            $body['variables']['offset'] = count($data);
            $new_data = $this->request($body, $entity, true);
            $data = array_merge($data, $new_data);
            $more_results = count($new_data) >= $this->offset;
            $requests_made++;
        }

        return $data;
    }
}

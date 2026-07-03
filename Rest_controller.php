<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Lightweight REST base controller for CodeIgniter 3.
 *
 * Provides:
 *  - JSON request body parsing (works for POST/PUT/PATCH/DELETE)
 *  - Standard JSON response helper with correct HTTP status codes
 *  - Pagination / search / sort helpers shared by all list endpoints
 */
class REST_Controller extends CI_Controller
{
    const HTTP_OK                    = 200;
    const HTTP_CREATED               = 201;
    const HTTP_NO_CONTENT             = 204;
    const HTTP_BAD_REQUEST           = 400;
    const HTTP_UNAUTHORIZED          = 401;
    const HTTP_FORBIDDEN             = 403;
    const HTTP_NOT_FOUND             = 404;
    const HTTP_METHOD_NOT_ALLOWED    = 405;
    const HTTP_UNPROCESSABLE_ENTITY  = 422;
    const HTTP_SERVER_ERROR          = 500;

    /** @var array Parsed JSON / form body, regardless of HTTP verb */
    protected $payload = array();

    public function __construct()
    {
        parent::__construct();
        $this->parse_request_body();
    }

    /**
     * Reads php://input for JSON bodies (needed for PUT/PATCH/DELETE which
     * CodeIgniter does not populate into $_POST), falling back to $_POST
     * for classic form-encoded / multipart requests (e.g. file uploads).
     */
    protected function parse_request_body()
    {
        $raw = file_get_contents('php://input');
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

        if (!empty($raw) && stripos($contentType, 'application/json') !== FALSE) {
            $decoded = json_decode($raw, TRUE);
            $this->payload = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : array();
        } elseif (!empty($_POST)) {
            $this->payload = $_POST;
        } else {
            // Some clients send JSON without correct Content-Type header
            $decoded = json_decode($raw, TRUE);
            $this->payload = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : array();
        }
    }

    /**
     * Fetch a single field from parsed body or query string.
     */
    protected function input($key, $default = NULL)
    {
        if (array_key_exists($key, $this->payload)) {
            return $this->payload[$key];
        }
        return $this->input->get($key) !== NULL ? $this->input->get($key) : $default;
    }

    /**
     * Send a JSON response with an HTTP status code and stop execution.
     */
    protected function respond($body, $httpCode = self::HTTP_OK)
    {
        $this->output
            ->set_content_type('application/json', 'utf-8')
            ->set_status_header($httpCode)
            ->set_output(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function respond_success($data = NULL, $message = 'Success', $httpCode = self::HTTP_OK, $meta = NULL)
    {
        $this->respond(success_response($data, $message, $meta), $httpCode);
    }

    protected function respond_error($message = 'Something went wrong', $httpCode = self::HTTP_BAD_REQUEST, $errors = NULL)
    {
        $this->respond(error_response($message, $errors), $httpCode);
    }

    /**
     * Common pagination/search/sort params used across all list endpoints.
     * page   -> 1-based page number (default 1)
     * limit  -> items per page (default from config, capped at max)
     * search -> free text search term
     * sort   -> column to sort by
     * order  -> asc|desc
     */
    protected function get_list_params($allowedSortColumns = array(), $defaultSort = 'id')
    {
        $CI =& get_instance();
        $CI->config->load('config', TRUE, TRUE);

        $defaultLimit = (int) $CI->config->item('default_page_limit') ?: 10;
        $maxLimit     = (int) $CI->config->item('max_page_limit') ?: 100;

        $page  = max(1, (int) $this->input->get('page'));
        $limit = (int) $this->input->get('limit');
        $limit = $limit > 0 ? min($limit, $maxLimit) : $defaultLimit;

        $sort = $this->input->get('sort');
        if (empty($sort) || !in_array($sort, $allowedSortColumns, TRUE)) {
            $sort = $defaultSort;
        }

        $order = strtolower((string) $this->input->get('order'));
        $order = in_array($order, array('asc', 'desc'), TRUE) ? $order : 'desc';

        return array(
            'page'   => $page,
            'limit'  => $limit,
            'offset' => ($page - 1) * $limit,
            'search' => trim((string) $this->input->get('search')),
            'sort'   => $sort,
            'order'  => $order,
        );
    }

    protected function build_meta($totalRows, $params)
    {
        $totalPages = $params['limit'] > 0 ? (int) ceil($totalRows / $params['limit']) : 1;
        return array(
            'page'        => $params['page'],
            'limit'       => $params['limit'],
            'total'       => (int) $totalRows,
            'total_pages' => max(1, $totalPages),
        );
    }
}
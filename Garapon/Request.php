<?php
namespace CoEdo\Garapon;


class Request {

    /**
     * @var Resource $_ch
     */
    protected $_ch;

    /**
     * @var array $connection
     */
    public $connection = array();

    /**
     * @var array $error_messages
     */
    protected $error_messages = array(
        'auth' => array(
            '0' => 'error status or empty params',
            '100' => 'login failed',
            '200' => 'login failed',
        ),
        'search' => array(
            '0' => 'invalid session',
            '100' => 'invalid params',
            '200' => 'DB connect failed',
        ),
    );

    /**
     * @var string $method
     */
    public $method;

    /**
     * @var array $options
     */
    public $options = array();

    /**
     * @var int $timeout
     */
    public $timeout = 10;

    /**
     * @var string $url
     */
    public $url;

    public function __construct($url = null)
    {
        $this->response = new Response($this);
        if ($url) {
            $this->url = $url;
        }
    }

    public function buildUrl($method = '', $options = array())
    {
        $options += (array)$this->connection;
        extract($options);
        $url = sprintf("http://%s:%s/%s/%s/%s", $ip, $port, $api_dir, $api_version, $method);
        if (!isset($addParams) || $addParams == false) {
            $query = isset($query) ? $query : array();
            $addParams = true;
        }
        if (isset($query)) {
            $url .= $this->_buildQuery($query, $addParams);
        }
        $this->url = $url;
        return $this;
    }

    protected function _buildQuery($query, $addParams = true)
    {
        if ($addParams) {
            if (isset($this->connection->developer_id)) {
                $query['dev_id'] = $this->connection->developer_id;
            }
            if (isset($this->connection->gtvsession)) {
                $query['gtvsession'] = $this->connection->gtvsession;
            }
        }
        $result = '?' . http_build_query($query);
        return $result;
    }

    protected function _close()
    {
        $result = curl_close($this->_ch);
        if ($result) {
            $this->_ch = null;
        }
        return $result;
    }

    protected function _exec()
    {
        return curl_exec($this->_ch);
    }

    public function method($method)
    {
        $this->method = $method;
        return $this;
    }

    public function get($method = '', $options = array())
    {
        $options['httpMethod'] = 'get';
        return $this->request($method, array(), $options);
    }

    protected function _init($url = null)
    {
        $url = $url ? $url : $this->url;
        $ch = curl_init($url);
        if (!$ch) {
            new \Exception('curl init failed');
        } else {
            $this->_ch = $ch;
            $this->_setOption(CURLOPT_RETURNTRANSFER, true);
            $this->_setOption(CURLOPT_TIMEOUT, $this->timeout);
        }
        return $ch;
    }

    public function options($options, $value = null)
    {
        if (!is_null($value) && is_string($options)) {
            $this->options[$options] = $value;
        } else {
            foreach ($options as $_key => $_value) {
                $this->options[$_key] = $_value;
            }
        }
        return $this;
    }

    protected function _parse($result, $type = null)
    {
        $type = $type ? $type : 'json';
        $method = '_parse' . strtoupper($type[0]) . strtolower(substr($type, 1));
        return $this->$method($result);
    }

    protected function _parseGarapon($result)
    {
        $results = new \stdClass();
        $result = preg_split("/\n/", $result);
        foreach ($result as $record) {
            list($key, $value) = preg_split('/;/', $record, 2);
            $results->$key = $value;
        }
        $this->response->results = $results;
        return $this->response->results;
    }

    protected function _parseJson($result)
    {
        $this->response->results = json_decode($result);
        return $this->response->results;
    }

    public function post($method = '', $data = array(), $options = array())
    {
        $options['httpMethod'] = 'post';
        return $this->request($method, $data, $options);
    }

    public function request($method = '', $data = array(), $options = array())
    {
        $options += (array)$this->connection;
        $this->buildUrl($method, $options);
        $this->method($method);
        $this->_init($this->url);
        $httpMethod = $options['httpMethod'];
        unset($options['httpMethod']);
        if (strtolower($httpMethod) == 'post') {
            $this->_setOption(CURLOPT_POST, 1);
            if (!empty($data)) {
                $this->_setOption(CURLOPT_POSTFIELDS, $data);
            }
        }
        $this->_send($options);
        return $this->response->results;
    }

    protected function _result()
    {
        if (empty($this->response->results->status)) {
            // web
            $this->response->success = empty($this->response->results->{1});
            if (!$this->response->success) {
                $this->response->error_message = $this->response->results->{1};
            }
        } else {
            // API
            $this->response->success = $this->response->results->status == '1';
            if (!$this->response->success) {
                $messages = $this->error_messages[$this->method];
                $this->response->error_message = $messages[$this->response->results->status];
            }
        }
        return $this->response->success;
    }

    protected function _send($options = array())
    {
        $type = null;
        if (!empty($options['type'])) {
            $type = $options['type'];
            unset($options['type']);
        }
        if (!empty($options)) {
            foreach ($options as $key => $value) {
                if (!is_int($key)) {
                    unset($options[$key]);
                }
            }
            $this->_setOption($options);
        }
        $result = $this->_exec();
        if ($result) {
            $this->_close();
        } else {
            $this->response->success = false;
            $this->response->error_message = 'curl returns error code[' . curl_errno($this->_ch) . '] : ' . curl_error($this->_ch);
        }
        $results = $this->_parse($result, $type);
        $this->_result($results);
        return $results;
    }

    protected function _setOption($option, $value = null)
    {
        if (is_array($option) && !$value) {
            $result = curl_setopt_array($this->_ch, $option);
        } else {
            $result = curl_setopt($this->_ch, $option, $value);
        }
        return $result;
    }

    public function url($url)
    {
        $this->url = $url;
        return $this;
    }

    public function webRequest($data)
    {
        $this->_init($this->url);
        $this->_setOption(CURLOPT_POST, 1);
        $this->_setOption(CURLOPT_POSTFIELDS, $data);
        $options = array(
            'type' => 'garapon',
        );
        $this->_send($options);
        return $this->response->results;
    }

}
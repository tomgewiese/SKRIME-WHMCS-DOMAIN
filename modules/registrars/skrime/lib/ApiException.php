<?php

namespace WHMCS\Module\Registrar\Skrime;

class ApiException extends \Exception
{
    private $httpStatus;
    private $response;

    public function __construct($message, $httpStatus = 0, $response = null, $previous = null)
    {
        parent::__construct($message, (int) $httpStatus, $previous);
        $this->httpStatus = (int) $httpStatus;
        $this->response = $response;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function isNotFound()
    {
        if ($this->httpStatus === 404) {
            return true;
        }
        $message = strtolower($this->getMessage());

        return strpos($message, 'no product found') !== false || strpos($message, 'not found') !== false;
    }
}

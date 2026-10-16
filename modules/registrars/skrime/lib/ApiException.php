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
        if (strpos(strtolower($this->getMessage()), 'no product found') !== false) {
            return true;
        }

        return $this->httpStatus === 404 && is_array($this->response);
    }

    public function isProductMissing()
    {
        return is_array($this->response) && strpos(strtolower($this->getMessage()), 'no product found') !== false;
    }

    public function isTransportError()
    {
        return $this->httpStatus === 0 || $this->httpStatus >= 500;
    }
}

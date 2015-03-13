<?php


namespace Finna\Auth\Discourse;


class SingleSignOn implements \ArrayAccess
{
    private static $attributes = [
        'nonce', 'name', 'username', 'email', 'avatar_url', 'avatar_force_update',
        'about_me', 'external_id', 'return_sso_url', 'admin', 'moderator',
    ];

    private static $integers = [];
    private static $booleans = [
        'avatar_force_update', 'admin', 'moderator'
    ];

    private $values;
    private $customFields;
    private $ssoSecret;

    public function __construct($secret)
    {
        $this->ssoSecret = $secret;
        $this->values = array_fill_keys(self::$attributes, null);
        $this->customFields = [];
    }

    public function getSsoSecret()
    {
        return $this->ssoSecret;
    }

    public static function parse($payload, $ssoSecret = null)
    {
        $sso = new SingleSignOn($ssoSecret);

        parse_str($payload, $parsed);
        if (!isset($parsed['sso'], $parsed['sig']) || $sso->sign($parsed['sso']) !== $parsed['sig']) {
            throw new \RuntimeException('Bad signature for payload');
        }

        $decoded = base64_decode($parsed['sso']);
        parse_str($decoded, $decodedHash);

        foreach (self::$attributes as $attribute) {
            if (!isset($decodedHash[$attribute])) {
                continue;
            }

            $value = $decodedHash[$attribute];

            if (in_array($attribute, self::$integers)) {
                $value = (int) $value;
            } elseif (in_array($attribute, self::$booleans)) {
                $value = in_array($value, ['true', 'false'], true) ? $value === 'true' : null;
            }

            $sso[$attribute] = $value;
        }

        foreach ($decodedHash as $attribute => $value)
        {
            if (substr($attribute, 0, 7) !== 'custom.') {
                continue;
            }

            $field = substr($attribute, 7);
            $sso->customFields[$field] = $value;
        }

        return $sso;
    }

    public function sign($payload)
    {
        return hash_hmac('sha256', $payload, $this->ssoSecret);
    }

    public function getPayload()
    {
        $payload = base64_encode($this->getUnsignedPayload());
        return http_build_query([
            'sso' => $payload,
            'sig' => $this->sign($payload),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function getUnsignedPayload()
    {
        $payload = [];

        foreach ($this->values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $payload[$key] = $value;
        }

        foreach ($this->customFields as $key => $value) {
            $payload['custom.' . $key] = $value;
        }

        // Handle string casting differences between ruby and php
        $payload = array_map(function ($value) {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            return (string) $value;
        }, $payload);

        return http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }


    public function offsetExists($offset)
    {
        $this->validateAttribute($offset);
        return $this->values[$offset] !== null;
    }

    public function offsetGet($offset)
    {
        $this->validateAttribute($offset);
        return $this->values[$offset];
    }

    public function offsetSet($offset, $value)
    {
        $this->validateAttribute($offset);
        $this->values[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        $this->validateAttribute($offset);
        $this->values[$offset] = null;
    }

    private function validateAttribute($attribute)
    {
        if (!array_key_exists($attribute, $this->values)) {
            throw new \RuntimeException('Invalid SSO attribute');
        }
    }
}
